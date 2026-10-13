<?php

declare(strict_types=1);

namespace App\Tests\Integration\Automation;

use App\Domain\Automation\AutomationException;
use App\Domain\Automation\Recurring\RecurrenceRule;
use App\Domain\Automation\Recurring\RecurringRepository;
use App\Domain\Automation\Recurring\RecurringService;
use App\Domain\Automation\Trigger\FireTriggerJob;
use App\Domain\Automation\Trigger\TriggerAction;
use App\Domain\Automation\Trigger\TriggerEngine;
use App\Domain\Automation\Trigger\TriggerEvent;
use App\Domain\Automation\Trigger\TriggerRepository;
use App\Domain\Automation\Trigger\TriggerService;
use App\Domain\Post\PostStatus;
use App\Domain\Post\PublicationStatus;
use App\Domain\Workspace\Role;
use App\Integrations\Social\Contracts\ErrorKind;
use App\Tests\Support\AutomationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(RecurringService::class)]
#[CoversClass(RecurringRepository::class)]
#[CoversClass(TriggerService::class)]
#[CoversClass(TriggerEngine::class)]
#[CoversClass(TriggerRepository::class)]
#[CoversClass(FireTriggerJob::class)]
final class RecurringAndTriggerTest extends AutomationTestCase
{
    private function recurring(): RecurringService
    {
        return $this->app->container()->get(RecurringService::class);
    }

    private function triggers(): TriggerService
    {
        return $this->app->container()->get(TriggerService::class);
    }

    private function engine(): TriggerEngine
    {
        return $this->app->container()->get(TriggerEngine::class);
    }

    private function copies(): int
    {
        return (int) $this->db->select('SELECT COUNT(*) AS n FROM recurring_runs WHERE post_id IS NOT NULL')[0]['n'];
    }

    // ---- recurring posts -------------------------------------------------------------------------------------------

    public function testARecurringPostIsCopiedADayAheadAndNeverTwice(): void
    {
        $this->clock->set('2026-10-12 06:00:00'); // Monday 09:00 in Moscow
        $master = $this->draftPost('Каждый день');
        $rule = new RecurrenceRule(RecurrenceRule::DAILY, 1, [], null, '12:00', $this->context->timezone, '2026-10-12');
        $recurring = $this->recurring()->create($this->context, $master, $rule);
        self::assertSame('2026-10-12 09:00:00', $recurring->nextRunAt?->format('Y-m-d H:i:s'));

        self::assertSame(1, $this->recurring()->tick(), 'today 12:00 is within a day, tomorrow 12:00 is not yet');
        self::assertSame(0, $this->recurring()->tick(), 'a second tick makes nothing');
        self::assertSame(1, $this->copies());
        $posts = $this->db->select('SELECT status, scheduled_at FROM posts WHERE id <> ? ORDER BY scheduled_at', [$master->id]);
        self::assertSame(['scheduled'], array_column($posts, 'status'));
        self::assertSame(['2026-10-12 09:00:00.000000'], array_column($posts, 'scheduled_at'));
        self::assertSame(PostStatus::Draft, $this->fresh($master)->status, 'the master is never published');

        $this->at('2026-10-12 12:00:00');
        self::assertSame(1, $this->recurring()->tick(), 'a day before tomorrow\'s time the next copy is made');
        self::assertSame(2, $this->copies());
    }

    public function testMonthlyOnThe31stAndTheEndOfTheRule(): void
    {
        $this->clock->set('2026-01-30 06:00:00');
        $master = $this->draftPost('Отчёт');
        $rule = new RecurrenceRule(RecurrenceRule::MONTHLY, 1, [], 31, '09:00', $this->context->timezone, '2026-01-01', null, 3);
        $this->recurring()->create($this->context, $master, $rule);
        $when = [];
        foreach (['2026-01-30 06:00:00', '2026-02-27 06:00:00', '2026-03-30 06:00:00', '2026-04-29 06:00:00'] as $moment) {
            $this->at($moment);
            $this->recurring()->tick();
        }
        foreach ($this->db->select('SELECT scheduled_at FROM posts WHERE id <> ? ORDER BY scheduled_at', [$master->id]) as $row) {
            $when[] = substr((string) $row['scheduled_at'], 0, 10);
        }
        self::assertSame(['2026-01-31', '2026-02-28', '2026-03-31'], $when, 'February has no 31st: the last day is used; three repeats and then it stops');
        $row = $this->db->select('SELECT enabled, done_count, next_run_at FROM recurring_rules')[0];
        self::assertSame([0, 3, null], [(int) $row['enabled'], (int) $row['done_count'], $row['next_run_at']]);
    }

    public function testAnOccurrenceMissedWhileTheServiceWasDownIsSkippedNotSentLate(): void
    {
        $this->clock->set('2026-10-12 06:00:00');
        $master = $this->draftPost('Скучный');
        $this->recurring()->create($this->context, $master, new RecurrenceRule(RecurrenceRule::DAILY, 1, [], null, '12:00', $this->context->timezone, '2026-10-12'));
        $this->at('2026-10-15 07:00:00'); // three days later
        self::assertSame(1, $this->recurring()->tick(), 'only the occurrence of today is made, the three days in between are skipped');
        $first = $this->db->select('SELECT MIN(scheduled_at) AS t FROM posts WHERE id <> ?', [$master->id])[0]['t'];
        self::assertSame('2026-10-15 09:00:00.000000', $first);
    }

    public function testRecurringRulesAreCheckedAndNeedThePlanAndTheRole(): void
    {
        $master = $this->draftPost();
        $bad = new RecurrenceRule(RecurrenceRule::WEEKDAYS, 1, [], null, '10:00', 'UTC', '2026-01-01');
        try {
            $this->recurring()->create($this->context, $master, $bad);
            self::fail('a weekly rule needs days');
        } catch (AutomationException $e) {
            self::assertStringContainsString('день недели', $e->getMessage());
        }
        $author = $this->contextFor($this->workspace, $this->memberOf($this->workspace, 'author@example.com', Role::Author));
        try {
            $this->recurring()->create($author, $master, new RecurrenceRule(RecurrenceRule::DAILY, 1, [], null, '10:00', 'UTC', '2026-12-01'));
            self::fail('an author may not set up repeats');
        } catch (AutomationException $e) {
            self::assertTrue($e->forbidden);
        }
        // Over: ends before it begins to run again.
        try {
            $this->recurring()->create($this->context, $master, new RecurrenceRule(RecurrenceRule::DAILY, 1, [], null, '10:00', 'UTC', '2020-01-01', '2020-02-01'));
            self::fail('nothing would ever be posted');
        } catch (AutomationException $e) {
            self::assertStringContainsString('больше не выйдет', $e->getMessage());
        }
    }

    // ---- triggers --------------------------------------------------------------------------------------------------

    public function testATriggerFiresOnceAfterThePostIsPublishedAndTheDelay(): void
    {
        $this->clock->set('2026-10-12 06:00:00');
        $next = $this->draftPost('Следующий');
        [$first] = $this->scheduled($this->context, [$this->channel], '+10 minutes', 'Первый');
        $this->triggers()->create($this->context, $first, null, TriggerEvent::Published, 30, TriggerAction::PublishNext, ['next_post' => $next->publicId]);

        $this->at('2026-10-12 06:10:05');
        $this->drain();
        self::assertSame(PostStatus::Published, $this->fresh($first)->status);
        self::assertSame(PostStatus::Draft, $this->fresh($next)->status, 'the delay of 30 minutes has not passed');
        self::assertSame(0, $this->engine()->tick());

        $this->at('2026-10-12 06:41:00');
        self::assertSame(1, $this->engine()->tick());
        self::assertNotSame(PostStatus::Draft, $this->fresh($next)->status);
        self::assertSame(0, $this->engine()->tick(), 'a trigger fires once');
        $row = $this->db->select('SELECT status, result FROM triggers')[0];
        self::assertSame('done', $row['status']);
        self::assertStringContainsString('Следующий пост', (string) $row['result']);
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS n FROM publications WHERE post_id = ?', [$next->id])[0]['n']);
    }

    public function testFiringTwiceAtOnceActsOnce(): void
    {
        $this->clock->set('2026-10-12 06:00:00');
        $next = $this->draftPost('Следующий');
        [$first] = $this->scheduled($this->context, [$this->channel], '+10 minutes');
        $this->triggers()->create($this->context, $first, null, TriggerEvent::Published, 0, TriggerAction::PublishNext, ['next_post' => $next->publicId]);
        $this->at('2026-10-12 06:10:05');
        $this->drain();
        $id = (int) $this->db->select('SELECT id FROM triggers')[0]['id'];
        self::assertTrue($this->engine()->fire($id));
        self::assertFalse($this->engine()->fire($id), 'the second caller loses');
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS n FROM publications WHERE post_id = ?', [$next->id])[0]['n']);
        // The event that arms it can come again (a second channel, a re-delivery): nothing arms a fired trigger.
        $this->engine()->onPublished($this->fresh($first), $this->posts()->variants($this->context, $first)[0]);
        self::assertFalse($this->engine()->fire($id));
        self::assertSame('done', $this->db->select('SELECT status FROM triggers')[0]['status']);
    }

    public function testATimeTriggerPausesAQueue(): void
    {
        $this->clock->set('2026-10-12 06:00:00');
        $queue = $this->weekdayQueue();
        $this->triggers()->create($this->context, $this->draftPost('Любой'), null, TriggerEvent::AtTime, 0, TriggerAction::PauseQueue, ['date' => '2026-10-12', 'time' => '13:00', 'queue' => $queue->publicId]);
        $this->at('2026-10-12 09:59:00');
        self::assertSame(0, $this->engine()->tick());
        $this->at('2026-10-12 10:00:30');
        self::assertSame(1, $this->engine()->tick());
        self::assertSame(1, (int) $this->db->select('SELECT paused FROM queues')[0]['paused']);
    }

    public function testAChannelTriggerReactsToAFailedPublicationAndNotifies(): void
    {
        $this->clock->set('2026-10-12 06:00:00');
        $this->triggers()->create($this->context, null, $this->channel, TriggerEvent::Failed, 0, TriggerAction::Notify, ['message' => 'Канал подвёл']);
        [$post] = $this->scheduled($this->context, [$this->channel], '+10 minutes');
        $this->fake->failNext(ErrorKind::Permanent, 'rejected');
        $this->at('2026-10-12 06:10:05');
        $this->drain();
        self::assertSame(PostStatus::Failed, $this->fresh($post)->status);
        $this->at('2026-10-12 06:11:00');
        self::assertSame(1, $this->engine()->tick());
        self::assertSame('done', $this->db->select('SELECT status FROM triggers')[0]['status']);
        self::assertGreaterThan(0, (int) $this->db->select('SELECT COUNT(*) AS n FROM notifications WHERE type = ?', ['automation'])[0]['n']);
    }

    public function testDeletePinAndUnpinAct_onThePublishedCopies(): void
    {
        $this->clock->set('2026-10-12 06:00:00');
        [$post] = $this->scheduled($this->context, [$this->channel], '+10 minutes');
        $this->triggers()->create($this->context, $post, null, TriggerEvent::Published, 0, TriggerAction::Pin, []);
        $this->triggers()->create($this->context, $post, null, TriggerEvent::Published, 60, TriggerAction::Unpin, []);
        $this->triggers()->create($this->context, $post, null, TriggerEvent::Published, 120, TriggerAction::Delete, []);
        $this->at('2026-10-12 06:10:05');
        $this->drain();
        self::assertSame(1, $this->engine()->tick());
        self::assertNotSame([], $this->fake->pinned);
        self::assertSame(1, (int) $this->db->select('SELECT pinned FROM publications WHERE post_id = ?', [$post->id])[0]['pinned']);

        $this->at('2026-10-12 07:11:00');
        self::assertSame(1, $this->engine()->tick());
        self::assertSame(0, (int) $this->db->select('SELECT pinned FROM publications WHERE post_id = ?', [$post->id])[0]['pinned']);

        $this->at('2026-10-12 08:11:00');
        self::assertSame(1, $this->engine()->tick());
        $this->app->container()->get(\App\Domain\Post\PublicationScheduler::class)->enqueueDeletions();
        $this->runJobs('publish');
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS n FROM publications WHERE post_id = ? AND deleted_at IS NOT NULL', [$post->id])[0]['n']);
        self::assertSame(PublicationStatus::Sent->value, $this->db->select('SELECT status FROM publications WHERE post_id = ?', [$post->id])[0]['status']);
    }

    public function testTriggerRulesAreValidated(): void
    {
        $post = $this->draftPost();
        $cases = [
            'delete on failure' => [TriggerEvent::Failed, 0, TriggerAction::Delete, []],
            'time in the past' => [TriggerEvent::AtTime, 0, TriggerAction::Notify, ['date' => '2020-01-01', 'time' => '10:00']],
            'no queue chosen' => [TriggerEvent::Published, 0, TriggerAction::PauseQueue, []],
            'next post is itself' => [TriggerEvent::Published, 0, TriggerAction::PublishNext, ['next_post' => $post->publicId]],
            'delay too long' => [TriggerEvent::Published, 999999999, TriggerAction::Notify, []],
        ];
        foreach ($cases as $name => [$event, $delay, $action, $input]) {
            try {
                $this->triggers()->create($this->context, $post, null, $event, $delay, $action, $input);
                self::fail($name . ' must be refused');
            } catch (AutomationException $e) {
                self::assertFalse($e->forbidden, $name);
            }
        }
        try {
            $this->triggers()->create($this->context, null, null, TriggerEvent::Published, 0, TriggerAction::Notify, []);
            self::fail('a trigger needs a post or a channel');
        } catch (AutomationException) {
            $this->addToAssertionCount(1);
        }
        // Another workspace's channel or post is not reachable by id.
        $author = $this->contextFor($this->workspace, $this->memberOf($this->workspace, 'author@example.com', Role::Author));
        $this->expectException(AutomationException::class);
        $this->triggers()->create($author, $post, null, TriggerEvent::Published, 0, TriggerAction::Notify, []);
    }
}
