<?php

declare(strict_types=1);

namespace App\Tests\Integration\Post;

use App\Domain\Media\MediaException;
use App\Domain\Media\MediaService;
use App\Domain\Post\CalendarRepository;
use App\Domain\Post\PostDraft;
use App\Domain\Post\PostException;
use App\Domain\Post\PostOptions;
use App\Domain\Post\PostService;
use App\Domain\Post\PostStatus;
use App\Domain\Post\PostTemplateRepository;
use App\Domain\Post\PostUsageChecker;
use App\Domain\Post\PublicationStatus;
use App\Domain\Post\VariantInput;
use App\Domain\Workspace\Role;
use App\Integrations\Social\Contracts\ErrorKind;
use App\Tests\Support\PostTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(PostService::class)]
#[CoversClass(PostUsageChecker::class)]
#[CoversClass(CalendarRepository::class)]
#[CoversClass(PostTemplateRepository::class)]
final class PostServiceTest extends PostTestCase
{
    public function testAnAuthorSavesDraftsButCannotPlanOrPublish(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $channel = $this->fakeChannel($workspace, $owner);
        $author = $this->memberOf($workspace, 'author@example.com', Role::Author);
        $context = $this->contextFor($workspace, $author);

        $draft = $this->service()->saveDraft($context, null, $this->draft('Черновик автора', [$channel]));
        self::assertSame(PostStatus::Draft, $draft->status);

        foreach ([
            fn () => $this->service()->schedule($context, null, $this->draft('x', [$channel]), $this->in('+1 hour')),
            fn () => $this->service()->schedule($context, null, $this->draft('x', [$channel]), $this->clock->now(), true),
            fn () => $this->service()->schedule($context, $draft, $this->draft('x', [$channel]), $this->in('+1 hour')),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('an author must not be able to plan a post');
            } catch (PostException $e) {
                self::assertTrue($e->forbidden);
            }
        }
        self::assertSame(0, $this->db->select('SELECT COUNT(*) AS n FROM publications')[0]['n']);
    }

    public function testAnAuthorEditsOnlyTheirOwnDrafts(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $channel = $this->fakeChannel($workspace, $owner);
        $ownerContext = $this->contextFor($workspace, $owner);
        $author = $this->memberOf($workspace, 'author@example.com', Role::Author);
        $other = $this->memberOf($workspace, 'other@example.com', Role::Author);
        $authorContext = $this->contextFor($workspace, $author);

        $mine = $this->service()->saveDraft($authorContext, null, $this->draft('Мой', [$channel]));
        $editorsDraft = $this->service()->saveDraft($ownerContext, null, $this->draft('Чужой', [$channel]));
        [$scheduled] = $this->scheduled($ownerContext, [$channel]);

        self::assertTrue($this->service()->canEdit($authorContext, $mine));
        self::assertFalse($this->service()->canEdit($authorContext, $editorsDraft));
        self::assertFalse($this->service()->canEdit($authorContext, $scheduled));
        self::assertFalse($this->service()->canEdit($this->contextFor($workspace, $other), $mine));
        foreach ([
            fn () => $this->service()->saveDraft($authorContext, $editorsDraft, $this->draft('взлом', [$channel])),
            fn () => $this->service()->delete($authorContext, $scheduled),
            fn () => $this->service()->cancel($authorContext, $scheduled),
            fn () => $this->service()->saveDraft($this->contextFor($workspace, $other), $mine, $this->draft('чужой черновик', [$channel])),
        ] as $i => $attempt) {
            try {
                $attempt();
                self::fail('attempt ' . $i . ' must be refused');
            } catch (PostException $e) {
                self::assertTrue($e->forbidden, 'attempt ' . $i);
            }
        }
    }

    public function testAViewerAndAClientCannotTouchPosts(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $channel = $this->fakeChannel($workspace, $owner);
        foreach ([Role::Viewer, Role::Client] as $role) {
            $user = $this->memberOf($workspace, $role->value . '@example.com', $role);
            $this->expectException(PostException::class);
            $this->service()->saveDraft($this->contextFor($workspace, $user), null, $this->draft('x', [$channel]));
        }
    }

    public function testSchedulingNeedsAChannelAndAWorkingPost(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $channel = $this->fakeChannel($workspace, $owner);
        $context = $this->contextFor($workspace, $owner);

        try {
            $this->service()->schedule($context, null, $this->draft('Текст', []), $this->in('+1 hour'));
            self::fail();
        } catch (PostException $e) {
            self::assertStringContainsString('хотя бы один канал', $e->getMessage());
        }
        try {
            $this->service()->schedule($context, null, $this->draft('', [$channel]), $this->in('+1 hour'));
            self::fail();
        } catch (PostException $e) {
            self::assertStringContainsString('пустой пост', implode(' ', $e->channelProblems[$channel->publicId]));
        }
        try {
            $this->service()->schedule($context, null, $this->draft(str_repeat('я', 5000), [$channel]), $this->in('+1 hour'));
            self::fail();
        } catch (PostException $e) {
            self::assertStringContainsString('лимита', implode(' ', $e->channelProblems[$channel->publicId]));
        }
        try {
            $this->service()->schedule($context, null, $this->draft('Текст', [$channel]), $this->in('-1 minute'));
            self::fail();
        } catch (PostException $e) {
            self::assertStringContainsString('уже прошло', $e->getMessage());
        }
        self::assertSame(0, $this->db->select('SELECT COUNT(*) AS n FROM posts')[0]['n'], 'a refused post leaves nothing behind');
    }

    public function testEveryChannelIsValidatedAndTheProblemsAreReportedPerChannel(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $fine = $this->fakeChannel($workspace, $owner, 'fake-a', 'A');
        $paused = $this->fakeChannel($workspace, $owner, 'fake-b', 'B');
        $this->db->execute('UPDATE channels SET status = ? WHERE id = ?', ['paused', $paused->id]);
        $paused = $this->app->container()->get(\App\Domain\Channel\ChannelRepository::class)->find($this->contextFor($workspace, $owner), $paused->publicId) ?? $paused;

        try {
            $this->service()->schedule($this->contextFor($workspace, $owner), null, $this->draft('Текст', [$fine, $paused]), $this->in('+1 hour'));
            self::fail();
        } catch (PostException $e) {
            self::assertArrayNotHasKey($fine->publicId, $e->channelProblems);
            self::assertStringContainsString('на паузе', $e->channelProblems[$paused->publicId][0]);
        }
    }

    public function testEachNetworkCanHaveItsOwnVariantWhichFollowsTheCommonTextUntilChanged(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $a = $this->fakeChannel($workspace, $owner, 'fake-a', 'A');
        $b = $this->fakeChannel($workspace, $owner, 'fake-b', 'B');
        $context = $this->contextFor($workspace, $owner);
        $draft = new PostDraft('Общий', [], new PostOptions(), true, [
            new VariantInput($a->publicId),
            new VariantInput($b->publicId, 'Свой для B', null, new PostOptions(silent: true)),
        ]);

        $post = $this->service()->saveDraft($context, null, $draft);
        $variants = $this->posts()->variants($context, $post);

        self::assertSame('Общий', $variants[0]->resolve($post)->text);
        self::assertSame('Свой для B', $variants[1]->resolve($post)->text);
        self::assertTrue($variants[1]->resolve($post)->options->silent);
        self::assertFalse($variants[0]->resolve($post)->options->silent);

        $changed = $this->service()->saveDraft($context, $post, new PostDraft('Общий v2', [], new PostOptions(), true, [new VariantInput($a->publicId), new VariantInput($b->publicId, 'Свой для B')]));
        $after = $this->posts()->variants($context, $changed);
        self::assertSame('Общий v2', $after[0]->resolve($changed)->text, 'the variant that was never changed follows the post');
        self::assertSame('Свой для B', $after[1]->resolve($changed)->text);

        $off = $this->service()->saveDraft($context, $changed, new PostDraft('Общий v3', [], new PostOptions(), false, [new VariantInput($a->publicId), new VariantInput($b->publicId, 'Свой для B')]));
        self::assertSame('Общий v3', $this->posts()->variants($context, $off)[1]->resolve($off)->text, 'switching "separately" off drops the own values');
    }

    public function testEditingAPlannedPostMovesItsPublication(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $channel = $this->fakeChannel($workspace, $owner);
        $context = $this->contextFor($workspace, $owner);
        [$post, $publications] = $this->scheduled($context, [$channel], '+1 hour');

        $edited = $this->service()->schedule($context, $post, $this->draft('Новый текст', [$channel]), $this->in('+3 hours'));

        $after = $this->publications()->forPost($context, $edited);
        self::assertCount(1, $after, 'the same publication is reused, not duplicated');
        self::assertSame($publications[0]->id, $after[0]->id);
        self::assertEqualsWithDelta($this->in('+3 hours')->getTimestamp(), $after[0]->runAt->getTimestamp(), 1);
        self::assertSame('Новый текст', $edited->baseText);
    }

    public function testAPostBeingSentCannotBeEditedMovedCancelledOrDeleted(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $channel = $this->fakeChannel($workspace, $owner);
        $context = $this->contextFor($workspace, $owner);
        [$post, $publications] = $this->scheduled($context, [$channel], '+1 minute');
        $this->clock->advance(61);
        self::assertNotNull($this->system()->claim($publications[0]->id));
        $this->system()->syncPostStatus($post->id);
        $post = $this->posts()->find($context, $post->publicId) ?? $post;
        self::assertSame(PostStatus::Publishing, $post->status);

        foreach ([
            fn () => $this->service()->schedule($context, $post, $this->draft('Правка', [$channel]), $this->in('+1 hour')),
            fn () => $this->service()->reschedule($context, $post, $this->in('+1 hour')),
            fn () => $this->service()->cancel($context, $post),
            fn () => $this->service()->saveDraft($context, $post, $this->draft('Правка', [$channel])),
            fn () => $this->service()->delete($context, $post),
        ] as $i => $attempt) {
            try {
                $attempt();
                self::fail('attempt ' . $i . ' should be refused');
            } catch (PostException) {
                self::assertSame(1, $this->db->select('SELECT COUNT(*) AS n FROM publications')[0]['n']);
            }
        }
        self::assertSame('sending', $this->db->select('SELECT status FROM publications WHERE id = ?', [$publications[0]->id])[0]['status']);
    }

    public function testMovingInTheCalendarValidatesAgain(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $channel = $this->fakeChannel($workspace, $owner);
        $context = $this->contextFor($workspace, $owner);
        [$post] = $this->scheduled($context, [$channel], '+1 hour');

        $moved = $this->service()->reschedule($context, $post, $this->in('+2 days'));
        self::assertEqualsWithDelta($this->in('+2 days')->getTimestamp(), (int) $moved->scheduledAt?->getTimestamp(), 1);

        // The content became invalid for the network since it was planned (here: the channel is paused).
        $this->db->execute('UPDATE channels SET status = ? WHERE id = ?', ['paused', $channel->id]);
        try {
            $this->service()->reschedule($context, $moved, $this->in('+3 days'));
            self::fail('a post that no channel accepts must not be moved');
        } catch (PostException $e) {
            self::assertStringContainsString('на паузе', $e->getMessage());
        }
        $unchanged = $this->posts()->find($context, $post->publicId);
        self::assertEqualsWithDelta($this->in('+2 days')->getTimestamp(), (int) $unchanged?->scheduledAt?->getTimestamp(), 1);

        $this->db->execute('UPDATE channels SET status = ? WHERE id = ?', ['active', $channel->id]);
        try {
            $this->service()->reschedule($context, $moved, $this->in('-5 minutes'));
            self::fail();
        } catch (PostException $e) {
            self::assertStringContainsString('уже прошло', $e->getMessage());
        }
    }

    public function testBackToDraftsCancelsThePlan(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $channel = $this->fakeChannel($workspace, $owner);
        $context = $this->contextFor($workspace, $owner);
        [$post, $publications] = $this->scheduled($context, [$channel], '+1 hour');

        $draft = $this->service()->saveDraft($context, $post, $this->draft('Пока не готов', [$channel]));

        self::assertSame(PostStatus::Draft, $draft->status);
        self::assertNull($draft->scheduledAt);
        self::assertSame('cancelled', $this->db->select('SELECT status FROM publications WHERE id = ?', [$publications[0]->id])[0]['status']);
        $this->clock->advance(7200);
        $this->drain();
        self::assertSame([], $this->fake->published, 'nothing goes out after the plan was withdrawn');
    }

    public function testACancelledPostCanBePlannedAgainWithoutDuplicates(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $channel = $this->fakeChannel($workspace, $owner);
        $context = $this->contextFor($workspace, $owner);
        [$post] = $this->scheduled($context, [$channel], '+1 hour');
        $this->service()->cancel($context, $post);
        $post = $this->posts()->find($context, $post->publicId) ?? $post;
        self::assertSame(PostStatus::Cancelled, $post->status);

        $again = $this->service()->schedule($context, $post, $this->draft('Снова', [$channel]), $this->in('+2 hours'));

        self::assertSame(PostStatus::Scheduled, $again->status);
        $statuses = array_map(static fn ($p) => $p->status, $this->publications()->forPost($context, $again));
        self::assertSame([PublicationStatus::Cancelled, PublicationStatus::Queued], $statuses);
    }

    public function testDuplicateMakesADraftCopy(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $a = $this->fakeChannel($workspace, $owner, 'fake-a', 'A');
        $context = $this->contextFor($workspace, $owner);
        [$post] = $this->scheduled($context, [$a], '+1 hour', 'Оригинал');

        $copy = $this->service()->duplicate($context, $post);

        self::assertNotSame($post->id, $copy->id);
        self::assertSame(PostStatus::Draft, $copy->status);
        self::assertSame('Оригинал', $copy->baseText);
        self::assertNull($copy->scheduledAt);
        self::assertCount(1, $this->posts()->variants($context, $copy));
        self::assertSame([], $this->publications()->forPost($context, $copy));
    }

    public function testAPublishedPostCannotBeDeletedButAPlannedOneCan(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $channel = $this->fakeChannel($workspace, $owner);
        $context = $this->contextFor($workspace, $owner);
        [$planned] = $this->scheduled($context, [$channel], '+1 day');
        [$soon] = $this->scheduled($context, [$channel], '+1 minute', 'Скоро');

        $this->service()->delete($context, $planned);
        self::assertNull($this->posts()->find($context, $planned->publicId));
        self::assertSame(1, $this->db->select('SELECT COUNT(*) AS n FROM publications')[0]['n'], 'its publication went with it');

        $this->clock->advance(61);
        $this->drain();
        $soon = $this->posts()->find($context, $soon->publicId) ?? $soon;
        self::assertSame(PostStatus::Published, $soon->status);
        $this->expectException(PostException::class);
        $this->service()->delete($context, $soon);
    }

    public function testRetryAndSettlingAnUncertainPublication(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $channel = $this->fakeChannel($workspace, $owner);
        $context = $this->contextFor($workspace, $owner);
        [$post, $publications] = $this->scheduled($context, [$channel], '+1 minute');
        $this->clock->advance(61);
        $this->fake->failNext(ErrorKind::UnknownOutcome, 'reset');
        $this->drain();
        $unknown = $this->publications()->find($context, $publications[0]->publicId);
        self::assertSame(PublicationStatus::Unknown, $unknown?->status);

        // A person who saw that the post did not appear tries again.
        $this->service()->retry($context, $unknown);
        $this->app->container()->get(\App\Kernel\Queue\Worker::class)->runNext(\App\Domain\Post\PublishJob::QUEUE, 'w');
        self::assertSame(PublicationStatus::Sent, $this->publications()->find($context, $publications[0]->publicId)?->status);
        self::assertSame(PostStatus::Published, $this->posts()->find($context, $post->publicId)?->status);

        // And a second uncertain one that the person confirms went out.
        [$post2, $second] = $this->scheduled($context, [$channel], '+1 minute', 'Второй');
        $this->clock->advance(61);
        $this->fake->failNext(ErrorKind::UnknownOutcome, 'reset');
        $this->drain();
        $uncertain = $this->publications()->find($context, $second[0]->publicId);
        self::assertNotNull($uncertain);
        $this->service()->settleUnknown($context, $uncertain, true);
        self::assertSame(PublicationStatus::Sent, $this->publications()->find($context, $second[0]->publicId)?->status);
        self::assertSame(PostStatus::Published, $this->posts()->find($context, $post2->publicId)?->status);
        $this->expectException(PostException::class);
        $this->service()->settleUnknown($context, $this->publications()->find($context, $second[0]->publicId) ?? $uncertain, true);
    }

    public function testReplanningIsRefusedWhileAnOutcomeIsUnknown(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $channel = $this->fakeChannel($workspace, $owner);
        $context = $this->contextFor($workspace, $owner);
        [$post, $publications] = $this->scheduled($context, [$channel], '+1 minute');
        $this->clock->advance(61);
        $this->fake->failNext(ErrorKind::UnknownOutcome, 'reset');
        $this->drain();
        $post = $this->posts()->find($context, $post->publicId) ?? $post;
        self::assertSame(PublicationStatus::Unknown, $this->publications()->find($context, $publications[0]->publicId)?->status);

        $this->expectException(PostException::class);
        $this->expectExceptionMessage('не уверены');
        $this->service()->schedule($context, $post, $this->draft('Ещё раз', [$channel]), $this->in('+1 hour'));
    }

    public function testTheTextOfAPublishedPostCanBeEditedWhereTheNetworkAllowsIt(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $channel = $this->fakeChannel($workspace, $owner);
        $context = $this->contextFor($workspace, $owner);
        $post = $this->service()->schedule($context, null, $this->draft('Опечатка', [$channel]), $this->clock->now(), true);
        $this->app->container()->get(\App\Kernel\Queue\Worker::class)->runNext(\App\Domain\Post\PublishJob::QUEUE, 'w');

        $report = $this->service()->editPublished($context, $this->posts()->find($context, $post->publicId) ?? $post, 'Исправлено');

        self::assertTrue($report[0]['ok']);
        self::assertSame([['id' => '1', 'text' => 'Исправлено']], $this->fake->edited);
        self::assertSame('Исправлено', $this->posts()->find($context, $post->publicId)?->baseText);
    }

    public function testRestrictedMembersWorkOnlyWithTheirChannels(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $mine = $this->fakeChannel($workspace, $owner, 'fake-a', 'Мой');
        $theirs = $this->fakeChannel($workspace, $owner, 'fake-b', 'Чужой');
        $ownerContext = $this->contextFor($workspace, $owner);
        $editor = $this->memberOf($workspace, 'editor@example.com', Role::Editor);
        $this->app->container()->get(\App\Domain\Workspace\ChannelAccessRepository::class)->set($ownerContext, $editor->id, [$mine->id]);
        $context = $this->contextFor($workspace, $editor);
        [$ownersPost] = $this->scheduled($ownerContext, [$theirs], '+1 hour');
        [$sharedPost] = $this->scheduled($ownerContext, [$mine, $theirs], '+2 hours');

        self::assertFalse($this->service()->canSee($context, $ownersPost), 'a post of other channels is invisible');
        self::assertTrue($this->service()->canSee($context, $sharedPost));
        try {
            $this->service()->schedule($context, null, $this->draft('Хочу в чужой', [$theirs]), $this->in('+1 hour'));
            self::fail();
        } catch (PostException) {
            self::assertSame(2, $this->db->select('SELECT COUNT(*) AS n FROM posts')[0]['n']);
        }
        $created = $this->service()->schedule($context, null, $this->draft('В свой', [$mine]), $this->in('+1 hour'));
        self::assertSame(PostStatus::Scheduled, $created->status);

        $items = $this->app->container()->get(CalendarRepository::class)->items($context, $this->in('-1 day'), $this->in('+1 week'), null, [], null, $this->service()->allowedChannels($context));
        self::assertCount(2, $items, 'the calendar shows only their channel');
        foreach ($items as $item) {
            self::assertSame('Мой', $item->channelName);
        }
    }

    public function testNothingLeaksAcrossWorkspaces(): void
    {
        [$ownerA, $workspaceA] = $this->ownerWithWorkspace('a@example.com', 'Анна');
        [$ownerB, $workspaceB] = $this->ownerWithWorkspace('b@example.com', 'Борис');
        $channelA = $this->fakeChannel($workspaceA, $ownerA);
        $channelB = $this->fakeChannel($workspaceB, $ownerB);
        $contextA = $this->contextFor($workspaceA, $ownerA);
        $contextB = $this->contextFor($workspaceB, $ownerB);
        [$postA, $publicationsA] = $this->scheduled($contextA, [$channelA]);

        self::assertNull($this->posts()->find($contextB, $postA->publicId));
        self::assertNull($this->publications()->find($contextB, $publicationsA[0]->publicId));
        self::assertSame([], $this->posts()->variants($contextB, $postA));
        self::assertSame([], $this->publications()->forPost($contextB, $postA));
        self::assertSame([], $this->posts()->drafts($contextB, null));
        self::assertSame([], $this->app->container()->get(CalendarRepository::class)->items($contextB, $this->in('-1 day'), $this->in('+1 week'), null, [], null, null));
        try {
            $this->service()->schedule($contextB, null, $this->draft('x', [$channelA]), $this->in('+1 hour'));
            self::fail('a channel of another workspace must not be usable');
        } catch (PostException) {
            self::assertSame(1, $this->db->select('SELECT COUNT(*) AS n FROM posts')[0]['n']);
        }
        try {
            $this->service()->cancel($contextB, $postA);
            self::fail();
        } catch (PostException) {
            self::assertSame('scheduled', $this->db->select('SELECT status FROM posts WHERE id = ?', [$postA->id])[0]['status']);
        }
        self::assertNull($this->posts()->lock($contextB, $postA));
    }

    public function testAFileUsedByAPlannedPostCannotBeDeletedFromTheLibrary(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $channel = $this->fakeChannel($workspace, $owner);
        $context = $this->contextFor($workspace, $owner);
        $media = $this->libraryPicture($context);
        $post = $this->service()->schedule($context, null, $this->draft('С картинкой', [$channel], [$media->publicId]), $this->in('+1 day'));

        try {
            $this->app->container()->get(MediaService::class)->delete($context, $media);
            self::fail('the file is still needed');
        } catch (MediaException $e) {
            self::assertStringContainsString('запланированном посте', $e->getMessage());
        }

        $this->service()->cancel($context, $post);
        $this->app->container()->get(MediaService::class)->delete($context, $media);
        self::assertNull($this->app->container()->get(\App\Domain\Media\MediaRepository::class)->find($context, $media->publicId));
    }

    public function testAPostWithAFileThatWasDeletedIsRefused(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $channel = $this->fakeChannel($workspace, $owner);
        $context = $this->contextFor($workspace, $owner);

        $this->expectException(PostException::class);
        $this->expectExceptionMessage('удалён из медиатеки');
        $this->service()->schedule($context, null, $this->draft('Текст', [$channel], ['01ARZ3NDEKTSV4RRFFQ69G5FAV']), $this->in('+1 day'));
    }

    public function testTheMediaGoesOutAsLocalFiles(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $channel = $this->fakeChannel($workspace, $owner);
        $context = $this->contextFor($workspace, $owner);
        $a = $this->libraryPicture($context, 'a.jpg');
        $b = $this->libraryPicture($context, 'b.jpg', 60, 30);
        $this->service()->schedule($context, null, $this->draft('Альбом', [$channel], [$b->publicId, $a->publicId]), $this->clock->now(), true);

        $this->app->container()->get(\App\Kernel\Queue\Worker::class)->runNext(\App\Domain\Post\PublishJob::QUEUE, 'w');

        self::assertCount(1, $this->fake->published);
        self::assertSame(2, $this->fake->published[0]['media']);
        self::assertSame(['b.jpg', 'a.jpg'], array_map(static fn ($m) => $m->filename, $this->fake->requests[0]->media), 'the order of the editor is kept');
        foreach ($this->fake->requests[0]->media as $file) {
            self::assertFileDoesNotExist($file->path, 'temporary files are removed after the call');
        }
    }

    public function testTemplatesAreKeptPerWorkspace(): void
    {
        [$ownerA, $workspaceA] = $this->ownerWithWorkspace('a@example.com', 'Анна');
        [$ownerB, $workspaceB] = $this->ownerWithWorkspace('b@example.com', 'Борис');
        $channel = $this->fakeChannel($workspaceA, $ownerA);
        $contextA = $this->contextFor($workspaceA, $ownerA);
        $contextB = $this->contextFor($workspaceB, $ownerB);
        $templates = $this->app->container()->get(PostTemplateRepository::class);

        $id = $templates->create($contextA, 'Анонс', new PostDraft('Шаблон', [], new PostOptions(pin: true), true, [new VariantInput($channel->publicId, 'Свой', null, new PostOptions(silent: true))]));

        $found = $templates->find($contextA, $id);
        self::assertNotNull($found);
        self::assertSame('Шаблон', $found['draft']->text);
        self::assertTrue($found['draft']->options->pin);
        self::assertSame('Свой', $found['draft']->variants[0]->text);
        self::assertTrue($found['draft']->variants[0]->options?->silent);
        self::assertNull($templates->find($contextB, $id));
        self::assertSame([], $templates->all($contextB));
        self::assertFalse($templates->delete($contextB, $id));
        self::assertTrue($templates->delete($contextA, $id));
    }
}
