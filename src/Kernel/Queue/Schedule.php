<?php

declare(strict_types=1);

namespace App\Kernel\Queue;

use App\Kernel\Container;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Registry of periodic tasks (defined in code, `config/schedule.php`) and the runner used by
 * `schedule:run`. A failing task is logged and does not stop the others. Run a single scheduler
 * instance: tasks are not locked across processes.
 */
final class Schedule
{
    /** @var list<ScheduledTask> */
    private array $tasks = [];

    public function __construct(private readonly Container $container, private readonly LoggerInterface $logger)
    {
    }

    /**
     * Run a callback (autowired) on a cron schedule.
     *
     * @param callable(): mixed $callback
     */
    public function call(string $name, string $cron, callable $callback): void
    {
        $container = $this->container;
        $this->tasks[] = new ScheduledTask($name, $cron, static function () use ($container, $callback): void {
            $container->call($callback);
        });
    }

    /**
     * Dispatch a job on a cron schedule.
     */
    public function job(string $name, string $cron, Job $job, Queue $queue): void
    {
        $this->tasks[] = new ScheduledTask($name, $cron, static function () use ($queue, $job): void {
            $queue->dispatch($job);
        });
    }

    /**
     * @return list<ScheduledTask>
     */
    public function tasks(): array
    {
        return $this->tasks;
    }

    /**
     * Run every task due at `$at`. Returns the names of tasks that ran.
     *
     * @return list<string>
     */
    public function runDue(DateTimeImmutable $at): array
    {
        $ran = [];
        foreach ($this->tasks as $task) {
            if (!$task->isDue($at)) {
                continue;
            }
            try {
                $task->run();
                $ran[] = $task->name;
            } catch (Throwable $e) {
                $this->logger->error('schedule.task.failed', ['task' => $task->name, 'error' => $e::class . ': ' . $e->getMessage()]);
            }
        }

        return $ran;
    }
}
