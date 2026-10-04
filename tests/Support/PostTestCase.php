<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\Channel\Channel;
use App\Domain\Post\Post;
use App\Domain\Post\PostDraft;
use App\Domain\Post\PostOptions;
use App\Domain\Post\PostRepository;
use App\Domain\Post\PostService;
use App\Domain\Post\Publication;
use App\Domain\Post\PublicationRepository;
use App\Domain\Post\PublicationScheduler;
use App\Domain\Post\PublicationSystem;
use App\Domain\Post\PublishJob;
use App\Domain\Post\VariantInput;
use App\Domain\User\User;
use App\Domain\Workspace\Workspace;
use App\Domain\Workspace\WorkspaceContext;
use App\Integrations\Social\Contracts\Platform;
use App\Integrations\Social\Fake\FakeAdapter;
use App\Kernel\Queue\Worker;
use DateTimeImmutable;

/**
 * Base class for post and publishing tests: channels of the test network, a ready `PostService`, drafts built from plain values,
 * and a way to run the queue the way the worker does. Nothing here reaches a real network.
 */
abstract class PostTestCase extends ChannelTestCase
{
    protected FakeAdapter $fake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = $this->app->container()->get(FakeAdapter::class);
    }

    protected function fakeChannel(Workspace $workspace, User $by, string $externalId = 'fake-1', string $title = 'Тестовый канал'): Channel
    {
        return $this->makeChannel($workspace, $by, $externalId, $title, platform: Platform::Fake);
    }

    protected function service(): PostService
    {
        return $this->app->container()->get(PostService::class);
    }

    protected function posts(): PostRepository
    {
        return $this->app->container()->get(PostRepository::class);
    }

    protected function publications(): PublicationRepository
    {
        return $this->app->container()->get(PublicationRepository::class);
    }

    protected function system(): PublicationSystem
    {
        return $this->app->container()->get(PublicationSystem::class);
    }

    /**
     * @param list<Channel> $channels
     * @param list<string> $media
     */
    protected function draft(string $text = 'Привет, **мир**!', array $channels = [], array $media = [], ?PostOptions $options = null): PostDraft
    {
        return new PostDraft($text, $media, $options ?? new PostOptions(), false, array_map(static fn (Channel $c): VariantInput => new VariantInput($c->publicId), $channels));
    }

    /**
     * A real picture in the library of the workspace.
     */
    protected function libraryPicture(WorkspaceContext $context, string $name = 'photo.jpg', int $width = 40, int $height = 20): \App\Domain\Media\Media
    {
        return $this->app->container()->get(\App\Domain\Media\MediaService::class)->upload($context, $this->tempFile(MediaFixtures::jpeg($width, $height)), $name)->media;
    }

    protected function in(string $modifier): DateTimeImmutable
    {
        return $this->clock->now()->modify($modifier);
    }

    /**
     * Plan a post for `$modifier` from now (a fresh post) and return it with its publications.
     *
     * @param list<Channel> $channels
     * @return array{Post, list<Publication>}
     */
    protected function scheduled(WorkspaceContext $context, array $channels, string $modifier = '+10 minutes', string $text = 'Привет, **мир**!'): array
    {
        $post = $this->service()->schedule($context, null, $this->draft($text, $channels), $this->in($modifier));

        return [$post, $this->publications()->forPost($context, $post)];
    }

    /**
     * Let the scheduler create the jobs of the minute, then run everything in the `publish` queue (the worker's job), at the current time.
     */
    protected function drain(): int
    {
        $this->app->container()->get(PublicationScheduler::class)->enqueueDue();
        $worker = $this->app->container()->get(Worker::class);
        $count = 0;
        while ($worker->runNext(PublishJob::QUEUE, 'test')) {
            ++$count;
        }

        return $count;
    }

    /**
     * Make jobs that were delayed available: the queue compares `available_at` with the clock, so tests move the clock.
     */
    protected function at(string $time): void
    {
        $this->clock->set($time);
    }
}
