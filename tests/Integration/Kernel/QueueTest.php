<?php

declare(strict_types=1);

namespace App\Tests\Integration\Kernel;

use App\Kernel\Container;
use App\Kernel\Database\Connection;
use App\Kernel\Queue\AbstractJob;
use App\Kernel\Queue\Queue;
use App\Kernel\Queue\Worker;
use App\Tests\Support\FakeClock;
use App\Tests\Support\TestEnv;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Test job: records its runs in a static list, or fails on demand.
 */
final class QueueTestJob extends AbstractJob
{
    /** @var list<string> */
    public static array $ran = [];

    public function __construct(public readonly string $label, public readonly bool $fail = false)
    {
    }

    public static function fromPayload(array $payload): static
    {
        return new static((string) ($payload['label'] ?? ''), (bool) ($payload['fail'] ?? false));
    }

    public function toPayload(): array
    {
        return ['label' => $this->label, 'fail' => $this->fail];
    }

    public function handle(): void
    {
        if ($this->fail) {
            throw new RuntimeException('job failed: ' . $this->label);
        }
        self::$ran[] = $this->label;
    }
}

/**
 * Queue semantics against real MySQL: delays, SKIP LOCKED, retries, backoff, failed_jobs.
 */
#[CoversClass(Queue::class)]
#[CoversClass(Worker::class)]
#[CoversClass(AbstractJob::class)]
final class QueueTest extends TestCase
{
    private Connection $db;
    private FakeClock $clock;
    private Queue $queue;

    protected function setUp(): void
    {
        $this->db = TestEnv::connection();
        $this->db->execute('DELETE FROM jobs');
        $this->db->execute('DELETE FROM failed_jobs');
        $this->clock = new FakeClock('2026-05-05 10:00:00');
        $this->queue = new Queue($this->db, $this->clock, 900);
        QueueTestJob::$ran = [];
    }

    protected function tearDown(): void
    {
        $this->db->execute('DELETE FROM jobs');
        $this->db->execute('DELETE FROM failed_jobs');
    }

    public function testDispatchedJobIsReservedOnceAndCompleted(): void
    {
        $id = $this->queue->dispatch(new QueueTestJob('a'));

        $job = $this->queue->reserve('default', 'w1');

        self::assertNotNull($job);
        self::assertSame($id, $job->id);
        self::assertSame(1, $job->attempts);
        self::assertNull($this->queue->reserve('default', 'w2'), 'a reserved job is invisible to others');
        $this->queue->complete($job);
        self::assertSame(0, $this->queue->size());
    }

    public function testDelayedJobWaitsUntilDue(): void
    {
        $this->queue->dispatch(new QueueTestJob('later'), 120);

        self::assertNull($this->queue->reserve('default', 'w'));
        $this->clock->advance(121);
        self::assertNotNull($this->queue->reserve('default', 'w'));
    }

    public function testQueuesAreIsolated(): void
    {
        $this->queue->dispatch(new QueueTestJob('mail'), 0, 'mail');

        self::assertNull($this->queue->reserve('default', 'w'));
        self::assertNotNull($this->queue->reserve('mail', 'w'));
    }

    public function testInvalidQueueNameIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->queue->dispatch(new QueueTestJob('x'), 0, "bad name'; --");
    }

    public function testConcurrentWorkersNeverTakeTheSameJob(): void
    {
        $first = $this->queue->dispatch(new QueueTestJob('one'));
        $second = $this->queue->dispatch(new QueueTestJob('two'));

        // Worker A holds a row lock inside an open transaction (as it does while reserving).
        $a = TestEnv::connection();
        $a->pdo()->beginTransaction();
        $locked = $a->select('SELECT id FROM jobs WHERE queue = ? ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED', ['default']);
        self::assertSame($first, (int) $locked[0]['id']);

        // Worker B must skip the locked row instead of waiting for it or taking it.
        $b = new Queue(TestEnv::connection(), $this->clock);
        $taken = $b->reserve('default', 'b');
        self::assertNotNull($taken);
        self::assertSame($second, $taken->id);
        self::assertNull($b->reserve('default', 'b'), 'nothing else is free while A holds the first row');

        $a->pdo()->rollBack();
    }

    public function testAbandonedReservationBecomesAvailableAfterVisibilityTimeout(): void
    {
        $this->queue->dispatch(new QueueTestJob('x'));
        $this->queue->reserve('default', 'crashed-worker');

        $this->clock->advance(901);
        $again = $this->queue->reserve('default', 'rescuer');

        self::assertNotNull($again);
        self::assertSame(2, $again->attempts);
    }

    private function worker(): Worker
    {
        return new Worker($this->queue, new Container(), new NullLogger());
    }

    public function testWorkerRunsJobAndRemovesIt(): void
    {
        $this->queue->dispatch(new QueueTestJob('ok'));
        $worker = $this->worker();

        self::assertTrue($worker->runNext());
        self::assertFalse($worker->runNext());
        self::assertSame(['ok'], QueueTestJob::$ran);
        self::assertSame(0, $this->queue->size());
    }

    public function testFailingJobIsRetriedWithBackoffThenMovedToFailedJobs(): void
    {
        $this->queue->dispatch(new QueueTestJob('bad', true));
        $worker = $this->worker();
        $delays = [60, 300, 900, 3600];

        foreach ($delays as $attempt => $delay) {
            self::assertTrue($worker->runNext(), 'attempt ' . ($attempt + 1));
            self::assertFalse($worker->runNext(), 'job is backing off');
            $this->clock->advance($delay - 1);
            self::assertFalse($worker->runNext(), 'still before the backoff expires');
            $this->clock->advance(1);
        }

        self::assertTrue($worker->runNext(), 'fifth and last attempt');
        self::assertSame(0, $this->queue->size());
        $failed = $this->db->table('failed_jobs')->get();
        self::assertCount(1, $failed);
        self::assertSame(5, (int) $failed[0]['attempts']);
        self::assertStringContainsString('job failed: bad', (string) $failed[0]['error']);
    }

    public function testMalformedPayloadEndsUpInFailedJobs(): void
    {
        $this->db->table('jobs')->insert([
            'queue' => 'default',
            'payload_json' => json_encode(['class' => 'Nope\\Missing', 'data' => []], JSON_THROW_ON_ERROR),
            'available_at' => $this->clock->now()->format(Queue::FORMAT),
            'attempts' => 0,
            'max_attempts' => 1,
            'created_at' => $this->clock->now()->format(Queue::FORMAT),
        ]);

        self::assertTrue($this->worker()->runNext());
        self::assertSame(1, $this->db->table('failed_jobs')->count());
    }

    public function testWorkLoopStopsAfterMaxJobs(): void
    {
        $this->queue->dispatch(new QueueTestJob('1'));
        $this->queue->dispatch(new QueueTestJob('2'));
        $this->queue->dispatch(new QueueTestJob('3'));

        $this->worker()->work('default', 1, 2);

        self::assertSame(['1', '2'], QueueTestJob::$ran);
        self::assertSame(1, $this->queue->size());
    }

    public function testStopFlagEndsLoopImmediately(): void
    {
        $this->queue->dispatch(new QueueTestJob('1'));
        $worker = $this->worker();
        $worker->stop();

        $worker->work('default', 1);

        self::assertSame([], QueueTestJob::$ran);
    }
}
