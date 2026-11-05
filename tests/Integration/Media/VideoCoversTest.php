<?php

declare(strict_types=1);

namespace App\Tests\Integration\Media;

use App\Domain\Media\MediaException;
use App\Domain\Media\MediaKind;
use App\Domain\Media\MediaRepository;
use App\Domain\Media\MediaService;
use App\Domain\Media\FfprobeVideoProbe;
use App\Domain\Media\VideoProbe;
use App\Domain\Media\VideoCovers;
use App\Domain\Media\VideoVariantService;
use App\Tests\Support\PostTestCase;

final class VideoCoversTest extends PostTestCase
{
    public function testChosenFrameBecomesAWorkspaceImage(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $context = $this->contextFor($workspace, $owner);
        $bytes = file_get_contents(__DIR__ . '/../../Fixtures/short-video.mp4');
        self::assertNotFalse($bytes);
        $video = $this->app->container()->get(MediaService::class)->upload($context, $this->tempFile($bytes), 'short-video.mp4')->media;
        $cover = $this->app->container()->get(VideoCovers::class)->capture($context, $video, 500)->media;
        self::assertSame(MediaKind::Image, $cover->kind);
        self::assertGreaterThan(0, $cover->width);
        self::assertGreaterThan(0, $cover->height);
        $this->app->container()->instance(VideoProbe::class, new FfprobeVideoProbe());
        $variants = $this->app->container()->get(VideoVariantService::class);
        $first = $variants->get($context, $video);
        $saved = $this->app->container()->get(MediaRepository::class)->find($context, $video->publicId);
        self::assertNotNull($saved);
        self::assertSame($first, $variants->get($context, $saved));
        $this->expectException(MediaException::class);
        $this->app->container()->get(VideoCovers::class)->capture($context, $video, 999999);
    }
}
