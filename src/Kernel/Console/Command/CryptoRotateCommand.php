<?php

declare(strict_types=1);

namespace App\Kernel\Console\Command;

use App\Kernel\Console\Command;
use App\Kernel\Console\Output;
use App\Kernel\Database\Connection;
use App\Kernel\Security\Crypto;

/**
 * Bounded, resumable CAS rotation through Crypto: remote rewrap in production, explicit offline
 * private-keyring migration for legacy secretbox. Never retire keys required by historical backups.
 * COLUMNS plus exact durable mail payloads enumerate stored encrypted values.
 */
final class CryptoRotateCommand implements Command
{
    /** @var list<array{table: string, column: string, key?: string}> `key` is the unique column rows are walked by (default `id`) */
    private const COLUMNS = [
        ['table' => 'oauth_refresh_attempts', 'column' => 'recovery_key_enc'],
        ['table' => 'users', 'column' => 'totp_secret_enc'],
        ['table' => 'referral_payouts', 'column' => 'details'],
        ['table' => 'webhook_subscriptions', 'column' => 'secret_enc'],
        ['table' => 'platform_credentials', 'column' => 'secret_enc'],
        ['table' => 'platform_credentials', 'column' => 'refresh_enc'],
        ['table' => 'payment_providers', 'column' => 'config_enc', 'key' => 'code'],
        ['table' => 'ai_providers', 'column' => 'config_enc', 'key' => 'code'],
        ['table' => 'ai_workspace_settings', 'column' => 'byok_enc', 'key' => 'workspace_id'],
    ];

    public function __construct(private readonly Connection $db, private readonly Crypto $crypto, private readonly ?\App\Kernel\Config $config = null)
    {
    }

    public function name(): string
    {
        return 'crypto:rotate';
    }

    public function description(): string
    {
        return 'Re-encrypt stored secrets with the active crypto backend (--dry-run; offline --legacy-keyring-file=PATH)';
    }

    public function run(array $args, Output $out): int
    {
        $legacy = null;
        foreach ($args as $argument) {
            if (!str_starts_with($argument, '--legacy-keyring-file=')) {
                continue;
            }
            if ($this->config === null || $this->config->isProduction() || PHP_SAPI !== 'cli') {
                throw new \RuntimeException('Legacy key migration requires the isolated non-production CLI maintenance container.');
            }
            $path = substr($argument, strlen('--legacy-keyring-file='));
            clearstatcache(true, $path);
            if (!is_file($path) || is_link($path) || !in_array(fileperms($path) & 0777, [0400, 0600], true)) {
                throw new \RuntimeException('Legacy keyring must be a private regular file.');
            }
            $json = file_get_contents($path, false, null, 0, 65537);
            if (!is_string($json) || strlen($json) > 65536) {
                throw new \RuntimeException('Invalid legacy keyring.');
            }
            try {
                $keys = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
                if (!is_array($keys) || $keys === [] || array_is_list($keys)) {
                    throw new \RuntimeException();
                }
                $validated = [];
                foreach ($keys as $id => $key) {
                    if (!is_string($id) || !is_string($key)) {
                        throw new \RuntimeException();
                    }
                    $validated[$id] = $key;
                }
                $legacy = new Crypto(array_key_first($validated), $validated);
            } catch (\Throwable) {
                throw new \RuntimeException('Invalid legacy keyring.');
            }
        }
        $dry = in_array('--dry-run', $args, true);
        $total = 0;
        foreach (self::COLUMNS as $entry) {
            ['table' => $table, 'column' => $column] = $entry;
            $key = $entry['key'] ?? 'id';
            $purpose = $table === 'webhook_subscriptions' ? 'webhook' : 'application';
            $count = 0;
            $lastId = $key === 'id' ? 0 : '';
            do {
                $rows = $this->db->select(sprintf('SELECT %s AS k, %s AS secret FROM %s WHERE %s > ? AND %s IS NOT NULL ORDER BY %s LIMIT 200', $key, $column, $table, $key, $column, $key), [$lastId]);
                foreach ($rows as $row) {
                    $lastId = $key === 'id' ? (int) $row['k'] : (string) $row['k'];
                    $payload = (string) $row['secret'];
                    if ($payload === '' || !$this->crypto->needsRotation($payload, $purpose)) {
                        continue;
                    }
                    if (!$dry) {
                        $this->db->execute(sprintf('UPDATE %s SET %s = ? WHERE %s = ? AND BINARY %s = BINARY ?', $table, $column, $key, $column), [$legacy !== null && str_starts_with($payload, 'v1:')
                            ? $this->crypto->encrypt($legacy->decrypt($payload), $purpose) : $this->crypto->rotate($payload, $purpose), $lastId, $payload]);
                    }
                    $count++;
                }
            } while (count($rows) === 200);
            $out->line(sprintf('%s.%s: %d %s', $table, $column, $count, $dry ? 'to rotate' : 'rotated'));
            $total += $count;
        }
        // Durable mail payloads must remain recoverable after retiring legacy keys, including dead letters.
        foreach (['jobs', 'failed_jobs'] as $table) {
            $last = 0;
            do {
                $rows = $this->db->select('SELECT id, payload_json FROM ' . $table . ' WHERE id > ? ORDER BY id LIMIT 200', [$last]);
                foreach ($rows as $row) {
                    $last = (int) $row['id'];
                    $raw = (string) $row['payload_json'];
                    $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
                    if (!is_array($data) || ($data['class'] ?? '') !== \App\Domain\Notification\SendMailJob::class
                        || !is_array($data['data'] ?? null) || !is_string($data['data']['data'] ?? null)) {
                        continue;
                    }
                    $payload = $data['data']['data'];
                    if (!$this->crypto->needsRotation($payload)) {
                        continue;
                    }
                    if (!$dry) {
                        $data['data']['data'] = $legacy !== null && str_starts_with($payload, 'v1:')
                            ? $this->crypto->encrypt($legacy->decrypt($payload)) : $this->crypto->rotate($payload);
                        $this->db->execute(
                            'UPDATE ' . $table . ' SET payload_json=? WHERE id=? AND SHA2(CAST(payload_json AS CHAR),256)=?',
                            [json_encode($data, JSON_THROW_ON_ERROR), $last, hash('sha256', $raw)]
                        );
                    }
                    $total++;
                }
            } while (count($rows) === 200);
        }
        $out->line(sprintf('%s: %d secret(s).', $dry ? 'Dry run' : 'Done', $total));

        return 0;
    }
}
