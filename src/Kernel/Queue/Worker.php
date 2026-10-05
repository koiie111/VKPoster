<?php

declare(strict_types=1);

namespace App\Kernel\Queue;

use App\Kernel\Container;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Executes queued jobs. `runNext()` processes one job; `work()` loops until SIGTERM/SIGINT, finishing
 * the job in flight first (graceful shutdown). Failures are retried with the job's backoff until
 * `maxAttempts`, then moved to `failed_jobs`.
 */
final class Worker
{
    private bool $stop = false;

    private ?\Closure $pulse = null;

    public function __construct(
        private readonly Queue $queue,
        private readonly Container $container,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Something to call on every pass of `work()` (a heartbeat for monitoring).
     */
    public function onPulse(\Closure $pulse): void
    {
        $this->pulse = $pulse;
    }

    /**
     * @return bool true when a job was processed (successfully or not)
     */
    public function runNext(string $queueName = 'default', string $workerId = 'worker'): bool
    {
        $reserved = $this->queue->reserve($queueName, $workerId);
        if ($reserved === null) {
            return false;
        }
        try {
            $job = $this->instantiate($reserved);
            $this->container->call([$job, 'handle']);
            $this->queue->complete($reserved);
            $this->logger->info('queue.job.done', ['job' => $job::class, 'id' => $reserved->id]);
        } catch (Throwable $e) {
            $this->handleFailure($reserved, $e);
        }

        return true;
    }

    /**
     * Loop until a stop signal arrives (or `$maxJobs` jobs were processed, for tests). `$queueName` may list several queues,
     * comma-separated, in priority order (`publish,default`): a lower queue is only read when the ones before it are empty.
     */
    public function work(string $queueName = 'default', int $sleepSeconds = 2, ?int $maxJobs = null): void
    {
        $workerId = gethostname() . ':' . getmypid();
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->stop());
            pcntl_signal(SIGINT, fn () => $this->stop());
        }
        $processed = 0;
        $queues = array_values(array_filter(array_map('trim', explode(',', $queueName)), static fn (string $q): bool => $q !== ''));
        while (!$this->shouldStop() && ($maxJobs === null || $processed < $maxJobs)) {
            if ($this->pulse !== null) {
                ($this->pulse)();
            }
            $worked = false;
            foreach ($queues as $name) {
                if ($this->runNext($name, $workerId)) {
                    $worked = true;
                    break;
                }
            }
            if ($worked) {
                ++$processed;
                continue;
            }
            for ($i = 0; $i < $sleepSeconds * 10 && !$this->shouldStop(); ++$i) {
                usleep(100_000);
            }
        }
    }

    public function stop(): void
    {
        $this->stop = true;
    }

    /**
     * @phpstan-impure
     */
    private function shouldStop(): bool
    {
        pcntl_signal_dispatch();

        return $this->stop;
    }

    private function instantiate(ReservedJob $reserved): Job
    {
        $class = $reserved->payload['class'] ?? null;
        $data = $reserved->payload['data'] ?? [];
        if (!is_string($class) || !is_a($class, Job::class, true) || !is_array($data)) {
            throw new \RuntimeException('Malformed job payload.');
        }

        /** @var array<string, mixed> $data */
        return $class::fromPayload($data);
    }

    private function handleFailure(ReservedJob $reserved, Throwable $e): void
    {
        $error = $e::class . ': ' . $e->getMessage();
        $context = ['id' => $reserved->id, 'attempt' => $reserved->attempts, 'error' => $error];
        if ($reserved->attempts >= $reserved->maxAttempts) {
            $this->queue->fail($reserved, $error);
            $this->logger->error('queue.job.failed', $context);

            return;
        }
        $delay = 60;
        try {
            $delay = $this->instantiate($reserved)->backoff($reserved->attempts);
        } catch (Throwable) {
            // payload cannot even be instantiated: keep the default delay
        }
        $this->queue->release($reserved, $delay, $error);
        $this->logger->warning('queue.job.retry', $context + ['delay' => $delay]);
    }
}
