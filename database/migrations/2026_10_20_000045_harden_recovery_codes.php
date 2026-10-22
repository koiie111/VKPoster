<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Migration;

/** One-way strengthening preserves existing code usability without retaining a cheap unsalted verifier. */
return new class () implements Migration {
    public function up(Connection $db): void
    {
        $db->execute('ALTER TABLE recovery_codes MODIFY code_hash VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL');
        $after = 0;
        do {
            $rows = $db->select('SELECT id, code_hash FROM recovery_codes WHERE id > ? ORDER BY id LIMIT 100', [$after]);
            foreach ($rows as $row) {
                $after = (int) $row['id'];
                $hash = (string) $row['code_hash'];
                if (preg_match('/^[a-f0-9]{64}$/D', $hash) === 1) {
                    // Random per-record Argon2 salt; existing SHA256 is preimage input, never retained beside it.
                    $strong = password_hash($hash, PASSWORD_ARGON2ID, ['memory_cost' => 65536, 'time_cost' => 3, 'threads' => 1]);
                    $db->execute('UPDATE recovery_codes SET code_hash = ? WHERE id = ? AND code_hash = ?', [$strong, $after, $hash]);
                }
            }
        } while (count($rows) === 100);
    }

    public function down(Connection $db): void
    {
        if ((int) ($db->select('SELECT COUNT(*) AS n FROM recovery_codes')[0]['n'] ?? 0) !== 0) {
            throw new RuntimeException('Recovery-code hardening is one-way. Revoke codes in a reviewed recovery procedure before schema rollback.');
        }
        $db->execute('ALTER TABLE recovery_codes MODIFY code_hash CHAR(64) NOT NULL');
    }
};
