<?php

declare(strict_types=1);

namespace App\Integrations\Ai\Llm;

use App\Kernel\Database\Connection;
use App\Kernel\Security\Crypto;
use App\Support\Clock;
use App\Support\DbTime;

/**
 * Which language-model providers are on, their addresses, models and keys, as the owner set them in the admin area. Keys are stored
 * encrypted and never handed back to a page (the form learns only whether a secret is set). One provider is the default. Reads are
 * cached for a few seconds so a long-running worker follows a change.
 */
final class LlmSettings
{
    private const TTL_SECONDS = 5;

    /** @var array<string, array{kind: string, label: string, enabled: bool, default: bool, values: array<string, string>}>|null */
    private ?array $rows = null;

    private int $loadedAt = 0;

    public function __construct(private readonly Connection $db, private readonly Crypto $crypto, private readonly Clock $clock)
    {
    }

    /**
     * @return array<string, array{kind: string, label: string, enabled: bool, default: bool, values: array<string, string>}>
     */
    public function all(): array
    {
        if ($this->rows !== null && time() - $this->loadedAt < self::TTL_SECONDS) {
            return $this->rows;
        }
        $rows = [];
        foreach ($this->db->select('SELECT code, kind, label, enabled, is_default, config_enc FROM ai_providers ORDER BY code') as $row) {
            $rows[(string) $row['code']] = [
                'kind' => (string) $row['kind'],
                'label' => (string) $row['label'],
                'enabled' => (int) $row['enabled'] === 1,
                'default' => (int) $row['is_default'] === 1,
                'values' => $this->decode(is_string($row['config_enc']) ? $row['config_enc'] : null),
            ];
        }
        $this->loadedAt = time();

        return $this->rows = $rows;
    }

    /**
     * Providers that can answer right now: switched on and with a key and a model.
     *
     * @return array<string, array{kind: string, label: string, enabled: bool, default: bool, values: array<string, string>}>
     */
    public function usable(): array
    {
        return array_filter($this->all(), fn (array $p, string $code): bool => $p['enabled'] && ($p['values']['api_key'] ?? '') !== '' && $this->model($p, $code) !== '', ARRAY_FILTER_USE_BOTH);
    }

    /** The default provider's code if it is usable, else the first usable one, else null. */
    public function defaultCode(): ?string
    {
        $usable = $this->usable();
        foreach ($usable as $code => $p) {
            if ($p['default']) {
                return $code;
            }
        }

        return array_key_first($usable);
    }

    /**
     * @param array<string, mixed> $input form fields: `enabled`, `default`, `base_url`, `model`, `api_key` (empty keeps the saved key), `auth`, `label` (custom only)
     * @return list<string> problems to show (nothing saved when there are some)
     */
    public function save(string $code, array $input, ?int $actorId): array
    {
        if (!LlmCatalog::isKnown($code)) {
            return ['Такого провайдера нет.'];
        }
        $preset = LlmCatalog::presets()[$code] ?? null;
        $current = $this->all()[$code]['values'] ?? [];
        $values = $current;
        $errors = [];
        $text = static fn (string $key): string => is_string($input[$key] ?? null) ? trim($input[$key]) : '';

        $baseUrl = $text('base_url');
        $model = $text('model');
        $label = $preset['label'] ?? $text('label');
        $kind = $preset['kind'] ?? 'openai';
        if ($baseUrl !== '' && !self::validUrl($baseUrl)) {
            $errors[] = 'Адрес API должен начинаться с https:// и не содержать логина, пароля и параметров.';
        }
        if ($preset === null && $baseUrl === '') {
            $errors[] = 'Укажите адрес API.';
        }
        if ($model !== '' && preg_match('/^[\w.:\/@\- ]{1,120}$/u', $model) !== 1) {
            $errors[] = 'Название модели заполнено неверно.';
        }
        if ($preset === null && mb_strlen($label) < 2) {
            $errors[] = 'Назовите провайдера (от 2 символов).';
        }
        $key = is_string($input['api_key'] ?? null) ? trim($input['api_key']) : '';
        if ($key !== '' && (mb_strlen($key) > 400 || preg_match('/[\x00-\x20\x7F]/', $key) === 1)) {
            $errors[] = 'Ключ заполнен неверно.';
        }
        if ($errors !== []) {
            return $errors;
        }
        foreach (['base_url' => $baseUrl, 'model' => $model] as $k => $v) {
            $v === '' ? $this->unsetKey($values, $k) : $values[$k] = $v;
        }
        $auth = $text('auth');
        if (isset(LlmCatalog::AUTH_SCHEMES[$auth]) && $preset === null) {
            $values['auth'] = $auth;
        }
        if ($key !== '') {
            $values['api_key'] = $key;
        }
        $enabled = ($input['enabled'] ?? '') === '1';
        $default = $enabled && ($input['default'] ?? '') === '1';
        $this->db->transaction(function (Connection $db) use ($code, $kind, $label, $enabled, $default, $values, $actorId): void {
            if ($default) {
                $db->execute('UPDATE ai_providers SET is_default = 0 WHERE code <> ?', [$code]);
            }
            $db->execute(
                'INSERT INTO ai_providers (code, kind, label, enabled, is_default, config_enc, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?) '
                . 'ON DUPLICATE KEY UPDATE label = VALUES(label), enabled = VALUES(enabled), is_default = VALUES(is_default), config_enc = VALUES(config_enc), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)',
                [$code, $kind, mb_substr($label, 0, 80), $enabled ? 1 : 0, $default ? 1 : 0, $values === [] ? null : $this->crypto->encrypt(json_encode($values, JSON_THROW_ON_ERROR)), $actorId, DbTime::format($this->clock->now())],
            );
        });
        $this->rows = null;

        return [];
    }

    public function delete(string $code): void
    {
        if (LlmCatalog::isCustom($code)) {
            $this->db->execute('DELETE FROM ai_providers WHERE code = ?', [$code]);
            $this->rows = null;
        }
    }

    /**
     * Effective address, model and auth of a provider: what was saved, else the preset's default.
     *
     * @param array{kind: string, values: array<string, string>} $provider
     */
    public function baseUrl(string $code, array $provider): string
    {
        return ($provider['values']['base_url'] ?? '') !== '' ? $provider['values']['base_url'] : (LlmCatalog::presets()[$code]['base_url'] ?? '');
    }

    /** @param array{kind: string, values: array<string, string>} $provider */
    public function model(array $provider, ?string $code = null): string
    {
        if (($provider['values']['model'] ?? '') !== '') {
            return $provider['values']['model'];
        }
        $model = $code !== null ? (LlmCatalog::presets()[$code]['model'] ?? '') : '';

        // A preset is usable with its example model, except Yandex whose model carries the customer's folder id.
        return str_contains($model, 'ИДЕНТИФИКАТОР') ? '' : $model;
    }

    /**
     * A shared address check for the owner's providers and a workspace's own endpoint: https only, no credentials in the URL, no query.
     */
    public static function validUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts) && ($parts['scheme'] ?? '') === 'https' && ($parts['host'] ?? '') !== '' && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['query']) && !isset($parts['fragment']) && mb_strlen($url) <= 300 && preg_match('/[\x00-\x20\x7F]/', $url) !== 1;
    }

    /** @param array<string, string> $values */
    private function unsetKey(array &$values, string $key): void
    {
        unset($values[$key]);
    }

    /** @return array<string, string> */
    private function decode(?string $encrypted): array
    {
        if ($encrypted === null || $encrypted === '') {
            return [];
        }
        try {
            $decoded = json_decode($this->crypto->decrypt($encrypted), true, 8, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }
        $values = [];
        foreach (is_array($decoded) ? $decoded : [] as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $values[$key] = $value;
            }
        }

        return $values;
    }
}
