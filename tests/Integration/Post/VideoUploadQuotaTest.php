<?php

declare(strict_types=1);

namespace App\Tests\Integration\Post;

use App\Domain\Post\VideoUploadQuota;
use App\Tests\Support\PostTestCase;

final class VideoUploadQuotaTest extends PostTestCase
{
    public function testProjectQuotaIsReservedOncePerPublicationAndResetsOnPacificDay(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $context = $this->contextFor($workspace, $owner);
        $channel = $this->fakeChannel($workspace, $owner);
        [, $first] = $this->scheduled($context, [$channel], '+1 minute');
        [, $second] = $this->scheduled($context, [$channel], '+2 minutes');
        $quota = $this->app->container()->get(VideoUploadQuota::class);

        self::assertTrue($quota->reserveYouTube($first[0]->idempotencyKey, 1));
        self::assertFalse($quota->reserveYouTube($first[0]->idempotencyKey, 1), 'a second videos.insert call would spend more quota');
        self::assertFalse($quota->reserveYouTube($second[0]->idempotencyKey, 1));
        self::assertSame(0, $quota->remainingYouTube($this->clock->now(), 1));

        $this->clock->advance($quota->secondsUntilReset() + 1);
        self::assertTrue($quota->reserveYouTube($second[0]->idempotencyKey, 1));
        self::assertSame(0, $quota->remainingYouTube($this->clock->now(), 1));
    }
}
