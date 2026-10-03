<?php

declare(strict_types=1);

namespace App\Kernel\Queue;

use App\Kernel\Database\Connection;
use App\Support\Clock;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * MySQL-backed job queue. Workers reserve rows with `SELECT … FOR UPDATE SKIP LOCKED`, so concurrent
 * workers never take the same job and never block each other. A reservation older than the
 * visibility timeout is considered abandoned (worker crash) and the job becomes available again.
 */
final class Queue
{
    public const FORMAT = 'Y-m-d H:i:s.u';

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly int $visibilityTimeout = 900,
    ) {
    }

    /**
     * Enqueue a job.
     *
     * @param int $delaySeconds do not run before now + delay
     * @return int job id
     */
    public function dispatch(Job $job, int $delaySeconds = 0, string $queue = 'default'): int
    {
        if (preg_match('/^[a-z0-9_.-]{1,64}$/', $queue) !== 1) {
            throw new InvalidArgumentException('Invalid queue name.');
        }
        $now = $this->clock->now();

        return (int) $this->db->table('jobs')->insert([
            'queue' => $queue,
            'payload_json' => json_encode(['class' => $job::class, 'data' => $job->toPayload()], JSON_THROW_ON_ERROR),
            'available_at' => $now->modify(sprintf('+%d seconds', max(0, $delaySeconds)))->format(self::FORMAT),
            'attempts' => 0,
            'max_attempts' => $job->maxAttempts(),
            'created_at' => $now->format(self::FORMAT),
        ]);
    }

    /**
     * Take the next available job, or null. The attempt counter is incremented on reservation.
     */
    public function reserve(string $queue, string $workerId): ?ReservedJob
    {
        $now = $this->clock->now();

        return $this->db->transaction(function (Connection $db) use ($queue, $workerId, $now): ?ReservedJob {
            $rows = $db->select(
                'SELECT id, queue, payload_json, attempts, max_attempts FROM jobs
                 WHERE queue = ? AND available_at <= ? AND (reserved_at IS NULL OR reserved_at < ?)
                 ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED',
                [$queue, $now->format(self::FORMAT), $now->modify(sprintf('-%d seconds', $this->visibilityTimeout))->format(self::FORMAT)],
            );
            if ($rows === []) {
                return null;
            }
            $row = $rows[0];
            $attempts = (int) $row['attempts'] + 1;
            $db->table('jobs')->where('id', '=', (int) $row['id'])->update([
                'reserved_at' => $now->format(self::FORMAT),
                'reserved_by' => $workerId,
                'attempts' => $attempts,
            ]);
            $payload = json_decode((string) $row['payload_json'], true, 32, JSON_THROW_ON_ERROR);

            return new ReservedJob(
                (int) $row['id'],
                (string) $row['queue'],
                is_array($payload) ? $payload : [],
                $attempts,
                (int) $row['max_attempts'],
            );
        });
    }

    public function complete(ReservedJob $job): void
    {
        $this->db->table('jobs')->where('id', '=', $job->id)->delete();
    }

    /**
     * Put a failed job back with a delay and record the error.
     */
    public function release(ReservedJob $job, int $delaySeconds, string $error): void
    {
        $this->db->table('jobs')->where('id', '=', $job->id)->update([
            'reserved_at' => null,
            'reserved_by' => null,
            'available_at' => $this->clock->now()->modify(sprintf('+%d seconds', $delaySeconds))->format(self::FORMAT),
            'last_error' => $error,
        ]);
    }

    /**
     * Move a job that ran out of attempts to `failed_jobs`.
     */
    public function fail(ReservedJob $job, string $error): void
    {
        $this->db->transaction(function (Connection $db) use ($job, $error): void {
            $db->table('failed_jobs')->insert([
                'queue' => $job->queue,
                'payload_json' => json_encode($job->payload, JSON_THROW_ON_ERROR),
                'attempts' => $job->attempts,
                'error' => $error,
                'failed_at' => $this->clock->now()->format(self::FORMAT),
            ]);
            $db->table('jobs')->where('id', '=', $job->id)->delete();
        });
    }

    public function size(string $queue = 'default'): int
    {
        return $this->db->table('jobs')->where('queue', '=', $queue)->count();
    }

    public function now(): DateTimeImmutable
    {
        return $this->clock->now();
    }
}
