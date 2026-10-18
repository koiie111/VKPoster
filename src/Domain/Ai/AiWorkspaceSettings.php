<?php

declare(strict_types=1);

namespace App\Domain\Ai;

use App\Integrations\Ai\Llm\LlmCatalog;
use App\Integrations\Ai\Llm\LlmSettings;
use App\Kernel\Database\Connection;
use App\Kernel\Security\Crypto;
use App\Support\Clock;
use App\Support\DbTime;

/**
 * Per-workspace assistant settings: the brand voice and an optional own OpenAI-compatible endpoint (bring your own key). The key is
 * encrypted and never shown again; an empty key field keeps the saved one. The workspace is always passed in: callers hold a
 * `WorkspaceContext`, so one workspace cannot read another's settings.
 */
final class AiWorkspaceSettings
{
    public function __construct(private readonly Connection $db, private readonly Crypto $crypto, private readonly Clock $clock)
    {
    }

    public function brandVoice(int $workspaceId): BrandVoice
    {
        $row = $this->row($workspaceId);
        if ($row === null || !is_string($row['brand_json']) || $row['brand_json'] === '') {
            return new BrandVoice();
        }
        $data = json_decode($row['brand_json'], true);

        return BrandVoice::fromArray(is_array($data) ? $data : []);
    }

    public function saveBrandVoice(int $workspaceId, BrandVoice $voice): void
    {
        $this->db->execute(
            'INSERT INTO ai_workspace_settings (workspace_id, brand_json, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE brand_json = VALUES(brand_json), updated_at = VALUES(updated_at)',
            [$workspaceId, $voice->isEmpty() ? null : json_encode($voice->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), DbTime::format($this->clock->now())],
        );
    }

    /**
     * The workspace's own endpoint when it is switched on and complete.
     *
     * @return array{base_url: string, model: string, api_key: string, auth: string}|null
     */
    public function own(int $workspaceId): ?array
    {
        $row = $this->row($workspaceId);
        if ($row === null || (int) $row['byok_enabled'] !== 1) {
            return null;
        }
        $values = $this->values($row);
        if (($values['base_url'] ?? '') === '' || ($values['model'] ?? '') === '' || ($values['api_key'] ?? '') === '') {
            return null;
        }

        return ['base_url' => $values['base_url'], 'model' => $values['model'], 'api_key' => $values['api_key'], 'auth' => ($values['auth'] ?? '') === 'api-key' ? 'api-key' : 'bearer'];
    }

    /**
     * What the form may show: addresses and whether a key is set, never the key.
     *
     * @return array{enabled: bool, base_url: string, model: string, auth: string, key_set: bool}
     */
    public function ownForForm(int $workspaceId): array
    {
        $row = $this->row($workspaceId);
        $values = $row === null ? [] : $this->values($row);

        return [
            'enabled' => $row !== null && (int) $row['byok_enabled'] === 1,
            'base_url' => $values['base_url'] ?? '',
            'model' => $values['model'] ?? '',
            'auth' => ($values['auth'] ?? '') === 'api-key' ? 'api-key' : 'bearer',
            'key_set' => ($values['api_key'] ?? '') !== '',
        ];
    }

    /**
     * @param array<string, mixed> $input `enabled`, `base_url`, `model`, `auth`, `api_key` (empty keeps the saved key)
     * @return list<string> problems (nothing saved when there are some)
     */
    public function saveOwn(int $workspaceId, array $input): array
    {
        $text = static fn (string $key): string => is_string($input[$key] ?? null) ? trim($input[$key]) : '';
        $current = $this->row($workspaceId);
        $values = $current === null ? [] : $this->values($current);
        $enabled = ($input['enabled'] ?? '') === '1';
        $baseUrl = $text('base_url');
        $model = $text('model');
        $key = $text('api_key');
        $errors = [];
        if ($baseUrl !== '' && !LlmSettings::validUrl($baseUrl)) {
            $errors[] = 'Адрес API должен начинаться с https:// и не содержать логина, пароля и параметров.';
        }
        if ($model !== '' && preg_match('/^[\w.:\/@\- ]{1,120}$/u', $model) !== 1) {
            $errors[] = 'Название модели заполнено неверно.';
        }
        if ($key !== '' && (mb_strlen($key) > 400 || preg_match('/[\x00-\x20\x7F]/', $key) === 1)) {
            $errors[] = 'Ключ заполнен неверно.';
        }
        if ($enabled && ($baseUrl === '' || $model === '' || ($key === '' && ($values['api_key'] ?? '') === ''))) {
            $errors[] = 'Чтобы включить свою модель, укажите адрес API, модель и ключ.';
        }
        if ($errors !== []) {
            return $errors;
        }
        $values['base_url'] = $baseUrl;
        $values['model'] = $model;
        $values['auth'] = isset(LlmCatalog::AUTH_SCHEMES[$text('auth')]) ? $text('auth') : 'bearer';
        if ($key !== '') {
            $values['api_key'] = $key;
        }
        $this->db->execute(
            'INSERT INTO ai_workspace_settings (workspace_id, byok_enc, byok_enabled, updated_at) VALUES (?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE byok_enc = VALUES(byok_enc), byok_enabled = VALUES(byok_enabled), updated_at = VALUES(updated_at)',
            [$workspaceId, $this->crypto->encrypt(json_encode($values, JSON_THROW_ON_ERROR)), $enabled ? 1 : 0, DbTime::format($this->clock->now())],
        );

        return [];
    }

    /** @return array<string, mixed>|null */
    private function row(int $workspaceId): ?array
    {
        return $this->db->select('SELECT brand_json, byok_enc, byok_enabled FROM ai_workspace_settings WHERE workspace_id = ?', [$workspaceId])[0] ?? null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, string>
     */
    private function values(array $row): array
    {
        if (!is_string($row['byok_enc'] ?? null) || $row['byok_enc'] === '') {
            return [];
        }
        try {
            $decoded = json_decode($this->crypto->decrypt($row['byok_enc']), true, 8, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach (is_array($decoded) ? $decoded : [] as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $out[$k] = $v;
            }
        }

        return $out;
    }
}
