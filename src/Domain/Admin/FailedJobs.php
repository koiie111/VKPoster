<?php

declare(strict_types=1);

namespace App\Domain\Admin;

use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;

/**
 * Looks at the jobs that ran out of attempts (`failed_jobs`) and puts them back in the queue or throws them away.
 * The payload is never shown (it may hold personal data): only the class of the job, the queue and the error text.
 */
final class FailedJobs
{
    public const PAGE_SIZE = 25;

    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    /**
     * @return array{rows: list<array{id: int, queue: string, job: string, attempts: int, error: string, failed_at: \DateTimeImmutable|null}>, total: int, pages: int, page: int}
     */
    public function page(int $page): array
    {
        $total = (int) ($this->db->select('SELECT COUNT(*) AS c FROM failed_jobs')[0]['c'] ?? 0);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min(max(1, $page), $pages);
        $rows = [];
        foreach ($this->db->select('SELECT id, queue, payload_json, attempts, error, failed_at FROM failed_jobs ORDER BY id DESC LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . (($page - 1) * self::PAGE_SIZE)) as $row) {
            $payload = json_decode((string) $row['payload_json'], true);
            $class = is_array($payload) && is_string($payload['class'] ?? null) ? $payload['class'] : '?';
            $rows[] = [
                'id' => (int) $row['id'],
                'queue' => (string) $row['queue'],
                'job' => str_contains($class, '\\') ? substr($class, (int) strrpos($class, '\\') + 1) : $class,
                'attempts' => (int) $row['attempts'],
                'error' => mb_substr((string) $row['error'], 0, 300),
                'failed_at' => DbTime::parse($row['failed_at']),
            ];
        }

        return ['rows' => $rows, 'total' => $total, 'pages' => $pages, 'page' => $page];
    }

    /**
     * Put a failed job back in its queue with fresh attempts.
     *
     * @return bool false when there is no such job (already retried by somebody else)
     */
    public function retry(int $id): bool
    {
        $moved = $this->db->transaction(function (Connection $db) use ($id): bool {
            $rows = $db->select('SELECT queue, payload_json FROM failed_jobs WHERE id = ? FOR UPDATE', [$id]);
            if ($rows === []) {
                return false;
            }
            $now = $this->clock->now()->format('Y-m-d H:i:s.u');
            $db->table('jobs')->insert([
                'queue' => $rows[0]['queue'],
                'payload_json' => $rows[0]['payload_json'],
                'available_at' => $now,
                'attempts' => 0,
                'max_attempts' => 5,
                'created_at' => $now,
            ]);
            $db->execute('DELETE FROM failed_jobs WHERE id = ?', [$id]);

            return true;
        });

        return $moved === true;
    }

    public function discard(int $id): bool
    {
        return $this->db->execute('DELETE FROM failed_jobs WHERE id = ?', [$id]) > 0;
    }
}
