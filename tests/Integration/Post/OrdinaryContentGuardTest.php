<?php

declare(strict_types=1);

namespace App\Tests\Integration\Post;

use App\Tests\Support\PostTestCase;

/** Ordinary Telegram/VK/MAX posts retain the original scheduler, Queue and real adapter path. */
final class OrdinaryContentGuardTest extends PostTestCase
{
    private function executedQueries(): int
    {
        $query = $this->db->pdo()->query("SHOW SESSION STATUS LIKE 'Com_stmt_execute'");
        if ($query === false) {
            throw new \LogicException('Missing query counter');
        }
        return (int) $query->fetch()['Value'];
    }

    /** @return list<array{\App\Integrations\Social\Contracts\Platform}> */
    public static function destinationPlatforms(): array
    {
        return [[\App\Integrations\Social\Contracts\Platform::Telegram], [\App\Integrations\Social\Contracts\Platform::Vk], [\App\Integrations\Social\Contracts\Platform::Max]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('destinationPlatforms')]
    public function testOrdinaryDraftSchedulesAndPublishesWithNoSourceLookups(\App\Integrations\Social\Contracts\Platform $platform): void
    {
        [$owner, $ws] = $this->ownerWithWorkspace();
        $ctx = $this->contextFor($ws, $owner);
        $c = $this->app->container();
        $ws = $c->get(\App\Domain\Workspace\WorkspaceRepository::class)->findById($ctx->workspaceId) ?? throw new \LogicException('Missing workspace');
        $owner = $c->get(\App\Domain\User\UserRepository::class)->find($ctx->userId) ?? throw new \LogicException('Missing user');
        if ($platform === \App\Integrations\Social\Contracts\Platform::Vk) {
            $credential = $c->get(\App\Domain\Channel\CredentialVault::class)->storeOAuth($ctx, $platform, \App\Tests\Support\VkFixtures::TOKEN, 'test-refresh-not-real', $this->in('+1 day'), 'device', 'account', 'wall');
            $channel = $this->makeChannel($ws, $owner, '123', mode: \App\Domain\Channel\ChannelMode::Account, credentialId: $credential, platform: $platform);
            $this->http->expect('POST', \App\Tests\Support\VkFixtures::API . 'wall.post', 200, \App\Tests\Support\VkFixtures::raw('wall_post'));
        } elseif ($platform === \App\Integrations\Social\Contracts\Platform::Telegram) {
            $channel = $this->makeChannel($ws, $owner, platform: $platform);
            $this->tg('sendMessage', 'send_message');
        } else {
            $channel = $this->makeChannel($ws, $owner, (string) \App\Tests\Support\MaxFixtures::CHANNEL, platform: $platform);
            $this->max('POST', '/messages', 'message_sent');
        }
        $post = $this->service()->saveDraft($ctx, null, $this->draft('Ordinary post', [$channel]));
        $guard = $c->get(\App\Domain\Content\Publishing\ContentOriginGuard::class);
        $before = $this->executedQueries();
        self::assertNull($guard->problem($post));
        $after = $this->executedQueries();
        self::assertSame(1, $after - $before, 'One indexed existence lookup, no material/processing chain');
        self::assertSame(0, $this->db->table('content_post_origins')->count());
        $this->service()->schedule($ctx, $post, $this->draft($post->baseText, [$channel]), $this->in('+1 minute'));
        $this->clock->advance(61);
        $this->drain();
        self::assertSame('published', $this->posts()->find($ctx, $post->publicId)?->status->value);
        self::assertCount(1, $this->http->requests);
        $this->http->assertAllConsumed();
        self::assertCount(0, $this->fake->published);
    }

}
