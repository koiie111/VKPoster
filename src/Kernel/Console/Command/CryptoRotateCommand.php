<?php

declare(strict_types=1);

namespace App\Kernel\Console\Command;

use App\Kernel\Console\Command;
use App\Kernel\Console\Output;
use App\Kernel\Database\Connection;
use App\Kernel\Security\Crypto;

/**
 * `crypto:rotate [--dry-run]`: re-encrypt every stored secret that was sealed with an old `APP_KEY`, so the old key can be dropped
 * from `APP_KEYS`. Run it after changing `APP_KEY`/`APP_KEY_ID` (keep the previous key in `APP_KEYS` until it finishes).
 * Add a line to `COLUMNS` whenever a new `*_enc` column appears.
 */
final class CryptoRotateCommand implements Command
{
    /** @var list<array{table: string, column: string}> */
    private const COLUMNS = [
        ['table' => 'users', 'column' => 'totp_secret_enc'],
        ['table' => 'platform_credentials', 'column' => 'secret_enc'],
        ['table' => 'platform_credentials', 'column' => 'refresh_enc'],
    ];

    public function __construct(private readonly Connection $db, private readonly Crypto $crypto)
    {
    }

    public function name(): string
    {
        return 'crypto:rotate';
    }

    public function description(): string
    {
        return 'Re-encrypt stored secrets with the current APP_KEY (--dry-run only counts)';
    }

    public function run(array $args, Output $out): int
    {
        $dry = in_array('--dry-run', $args, true);
        $total = 0;
        foreach (self::COLUMNS as ['table' => $table, 'column' => $column]) {
            $count = 0;
            $lastId = 0;
            do {
                $rows = $this->db->select(sprintf('SELECT id, %s AS secret FROM %s WHERE id > ? AND %s IS NOT NULL ORDER BY id LIMIT 200', $column, $table, $column), [$lastId]);
                foreach ($rows as $row) {
                    $lastId = (int) $row['id'];
                    $payload = (string) $row['secret'];
                    if ($payload === '' || !$this->crypto->needsRotation($payload)) {
                        continue;
                    }
                    if (!$dry) {
                        $this->db->execute(sprintf('UPDATE %s SET %s = ? WHERE id = ?', $table, $column), [$this->crypto->rotate($payload), $lastId]);
                    }
                    $count++;
                }
            } while (count($rows) === 200);
            $out->line(sprintf('%s.%s: %d %s', $table, $column, $count, $dry ? 'to rotate' : 'rotated'));
            $total += $count;
        }
        $out->line(sprintf('%s: %d secret(s).', $dry ? 'Dry run' : 'Done', $total));

        return 0;
    }
}
