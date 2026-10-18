<?php

declare(strict_types=1);

namespace App\Integrations\Payments;

use App\Kernel\Config;
use App\Kernel\Database\Connection;
use App\Kernel\Security\Crypto;
use App\Support\Clock;
use App\Support\DbTime;

/**
 * Which payment providers are on and what their keys are, as the owner set them in the admin area. A saved row wins; without one the
 * environment (`BILLING_GATEWAYS` and the provider variables) decides, so a fresh install works as before. Keys are stored encrypted
 * and are never handed back to a page: the form only learns whether a secret is set. Reads are cached for a few seconds so a
 * long-running worker follows a change without a restart.
 */
final class ProviderSettings
{
    private const TTL_SECONDS = 5;

    /** @var array<string, array{enabled: bool, values: array<string, string>}>|null */
    private ?array $rows = null;

    private int $loadedAt = 0;

    public function __construct(
        private readonly Connection $db,
        private readonly Crypto $crypto,
        private readonly Config $config,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return list<string> provider codes that are switched on, in the order of the catalogue
     */
    public function enabled(): array
    {
        $fromEnv = array_map('strval', $this->config->array('billing.gateways'));
        $out = [];
        foreach (ProviderCatalog::codes() as $code) {
            $row = $this->rows()[$code] ?? null;
            if ($row !== null ? $row['enabled'] : in_array($code, $fromEnv, true)) {
                $out[] = $code;
            }
        }
        // The test provider has no row: it follows the environment list (and the registry refuses it in production).
        if (in_array('fake', $fromEnv, true)) {
            $out[] = 'fake';
        }

        return $out;
    }

    public function isEnabled(string $code): bool
    {
        return in_array($code, $this->enabled(), true);
    }

    /**
     * A field of a provider: the saved value, else the environment, else empty.
     */
    public function value(string $code, string $key): string
    {
        $saved = $this->rows()[$code]['values'][$key] ?? '';
        if ($saved !== '') {
            return $saved;
        }
        $fromEnv = $this->config->get('billing.' . $code . '.' . $key);

        return is_scalar($fromEnv) ? (string) $fromEnv : '';
    }

    /**
     * Whether a field has a value (for the form, which must not show secrets).
     */
    public function isSet(string $code, string $key): bool
    {
        return $this->value($code, $key) !== '';
    }

    /**
     * @param array<string, mixed> $input the form: a text per field key; a secret left empty keeps what is saved
     * @return list<string> problems to show the owner (nothing is saved when there are some)
     */
    public function save(string $code, bool $enabled, array $input, ?int $actorId): array
    {
        $catalog = ProviderCatalog::all()[$code] ?? null;
        if ($catalog === null) {
            return ['Такого провайдера нет.'];
        }
        $current = $this->rows()[$code]['values'] ?? [];
        $values = $current;
        $errors = [];
        foreach ($catalog['fields'] as $field) {
            $raw = $input[$field['key']] ?? null;
            $text = is_string($raw) ? trim($raw) : '';
            if ($text === '') {
                // An empty secret means "keep"; an empty plain field clears it.
                if (!$field['secret']) {
                    unset($values[$field['key']]);
                }
                continue;
            }
            if (mb_strlen($text) > 300 || preg_match('/[\x00-\x1F\x7F]/', $text) === 1) {
                $errors[] = 'Поле «' . $field['label'] . '» заполнено неверно.';
                continue;
            }
            $values[$field['key']] = $text;
        }
        if ($errors !== []) {
            return $errors;
        }
        $json = $values === [] ? null : $this->crypto->encrypt(json_encode($values, JSON_THROW_ON_ERROR));
        $this->db->execute(
            'INSERT INTO payment_providers (code, enabled, config_enc, updated_by, updated_at) VALUES (?, ?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), config_enc = VALUES(config_enc), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)',
            [$code, $enabled ? 1 : 0, $json, $actorId, DbTime::format($this->clock->now())],
        );
        $this->rows = null;

        return [];
    }

    /**
     * @return array<string, array{enabled: bool, values: array<string, string>}>
     */
    private function rows(): array
    {
        if ($this->rows !== null && time() - $this->loadedAt < self::TTL_SECONDS) {
            return $this->rows;
        }
        $rows = [];
        foreach ($this->db->select('SELECT code, enabled, config_enc FROM payment_providers') as $row) {
            $values = [];
            if (is_string($row['config_enc']) && $row['config_enc'] !== '') {
                try {
                    $decoded = json_decode($this->crypto->decrypt($row['config_enc']), true, 512, JSON_THROW_ON_ERROR);
                } catch (\Throwable) {
                    $decoded = [];
                }
                foreach (is_array($decoded) ? $decoded : [] as $key => $value) {
                    if (is_string($key) && is_string($value)) {
                        $values[$key] = $value;
                    }
                }
            }
            $rows[(string) $row['code']] = ['enabled' => (int) $row['enabled'] === 1, 'values' => $values];
        }
        $this->loadedAt = time();

        return $this->rows = $rows;
    }
}
