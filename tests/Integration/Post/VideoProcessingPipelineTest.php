<?php

declare(strict_types=1);

namespace App\Tests\Integration\Post;

use App\Domain\Media\MediaService;
use App\Domain\Media\VideoInfo;
use App\Domain\Post\PostDraft;
use App\Domain\Post\PostKind;
use App\Domain\Post\PostOptions;
use App\Domain\Post\PublicationScheduler;
use App\Domain\Post\ShortVideoOptions;
use App\Domain\Post\VariantInput;
use App\Domain\Post\VideoProviderSessions;
use App\Kernel\Queue\Worker;
use App\Tests\Support\PostTestCase;

final class VideoProcessingPipelineTest extends PostTestCase
{
    public function testAcceptedVideoWaitsForNetworkThenBecomesSentWithoutSecondUpload(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $context = $this->contextFor($workspace, $owner);
        $channel = $this->fakeChannel($workspace, $owner);
        $bytes = file_get_contents(__DIR__ . '/../../Fixtures/short-video.mp4');
        self::assertNotFalse($bytes);
        $video = $this->app->container()->get(MediaService::class)->upload($context, $this->tempFile($bytes), 'short-video.mp4')->media;
        $draft = new PostDraft('Ролик', [$video->publicId], new PostOptions(shortVideo: new ShortVideoOptions(title: 'Ролик', privacy: 'private', madeForKids: false)), false, [new VariantInput($channel->publicId)], PostKind::ShortVideo);
        $post = $this->service()->schedule($context, null, $draft, $this->clock->now(), true);
        $publication = $this->publications()->forPost($context, $post)[0];
        $this->app->container()->get(VideoProviderSessions::class)->begin($publication->idempotencyKey, 'fake', '1', null);
        $this->fake->processingNext = true;
        $this->probe->info = new VideoInfo(1080, 1920, 3000, 'h264', false);
        $worker = $this->app->container()->get(Worker::class);
        self::assertTrue($worker->runNext('video', 'video-test'));
        self::assertSame('processing', $this->db->select('SELECT status FROM publications WHERE id=?', [$publication->id])[0]['status']);
        self::assertCount(1, $this->fake->published);
        $sessions = $this->app->container()->get(VideoProviderSessions::class);
        self::assertSame([], $sessions->dueProcessing());
        $this->clock->advance(31);
        self::assertContains($publication->id, $sessions->dueProcessing());
        $sessions->markPollEnqueued($publication->id);
        self::assertSame([], $sessions->dueProcessing());
        self::assertTrue($worker->runNext('video', 'video-test'));
        self::assertSame('processing', $this->db->select('SELECT status FROM publications WHERE id=?', [$publication->id])[0]['status']);
        $this->fake->processingDone = true;
        $this->clock->advance(121);
        $this->app->container()->get(PublicationScheduler::class)->enqueueDue();
        self::assertTrue($worker->runNext('video', 'video-test'));
        self::assertSame('sent', $this->db->select('SELECT status FROM publications WHERE id=?', [$publication->id])[0]['status']);
        self::assertCount(1, $this->fake->published);
    }
}
