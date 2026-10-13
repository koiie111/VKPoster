<?php

declare(strict_types=1);

namespace App\Tests\Integration\Post;

use App\Domain\Channel\ChannelMode;
use App\Domain\Channel\CredentialVault;
use App\Domain\Post\PostDraft;
use App\Domain\Post\PostOptions;
use App\Domain\Post\VariantInput;
use App\Integrations\Social\Contracts\Platform;
use App\Tests\Support\InstagramFixtures as Fx;
use App\Kernel\HttpClient\HttpClientInterface;
use App\Support\Clock;
use App\Tests\Support\PostTestCase;
use App\Tests\Support\TestEnv;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * A post goes from the editor to the Instagram API through the real pipeline (signed public links to the library files, JPEG renditions,
 * the container wait, the first comment), with only the HTTP client replaced.
 */
#[CoversNothing]
final class InstagramPublishingTest extends PostTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Instagram downloads files from public https links built on APP_URL.
        $this->app = TestEnv::app(['APP_URL' => 'https://ezposter.example']);
        $this->app->container()->instance(Clock::class, $this->clock);
        $this->app->container()->instance(HttpClientInterface::class, $this->http);
    }

    /**
     * @return array{\App\Domain\Workspace\WorkspaceContext, \App\Domain\Channel\Channel}
     */
    private function instagramChannel(): array
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $context = $this->contextFor($workspace, $owner);
        $credential = $this->app->container()->get(CredentialVault::class)->storeOAuth($context, Platform::Instagram, Fx::TOKEN, '', $this->clock->now()->modify('+30 days'), '', Fx::ACCOUNT, 'instagram_business_basic');
        $channel = $this->makeChannel($workspace, $owner, Fx::ACCOUNT, 'Зерно', ChannelMode::Account, $credential, ['rights' => ['post' => true]], Platform::Instagram);

        return [$context, $channel];
    }

    private function expectPublishing(): void
    {
        $this->http->expect('GET', Fx::GRAPH . Fx::ACCOUNT . '/content_publishing_limit', 200, Fx::raw('limit_ok'));
        $this->http->expect('POST', Fx::GRAPH . Fx::ACCOUNT . '/media', 200, Fx::raw('container'));
        $this->http->expect('GET', Fx::GRAPH . '17900000000000001', 200, Fx::raw('status_finished'));
        $this->http->expect('POST', Fx::GRAPH . Fx::ACCOUNT . '/media_publish', 200, Fx::raw('publish'));
        $this->http->expect('GET', Fx::GRAPH . '17950000000000009', 200, Fx::raw('permalink'));
    }

    public function testAPhotoGoesOutThroughASignedJpegLinkAndTheFirstCommentFollows(): void
    {
        [$context, $channel] = $this->instagramChannel();
        $picture = $this->libraryPicture($context, 'wide.jpg', 400, 100); // 4:1, wider than Instagram takes
        $this->expectPublishing();
        $this->http->expect('POST', Fx::GRAPH . '17950000000000009/comments', 200, Fx::raw('comment'));

        $post = $this->service()->schedule($context, null, new PostDraft('Новое меню', [$picture->publicId], new PostOptions(firstComment: 'Заказ по ссылке в профиле'), false, [new VariantInput($channel->publicId)]), $this->in('+10 minutes'));
        $this->clock->advance(11 * 60);
        $this->drain();

        $create = array_values(array_filter($this->http->requests, static fn (array $r): bool => $r['method'] === 'POST' && str_ends_with($r['url'], '/media')))[0];
        $url = (string) $create['options']['form_params']['image_url'];
        self::assertStringStartsWith('https://ezposter.example/media/' . $picture->publicId . '/191x100-jpg?', $url, 'cropped to the widest ratio Instagram takes, as JPEG');
        self::assertStringContainsString('signature=', $url);
        self::assertSame('Новое меню', $create['options']['form_params']['caption']);
        self::assertSame('published', $this->db->select('SELECT status FROM posts WHERE id = ?', [$post->id])[0]['status']);
        $publication = $this->db->select('SELECT external_post_id, external_url, status FROM publications')[0];
        self::assertSame('sent', $publication['status']);
        self::assertSame('17950000000000009', $publication['external_post_id']);
        self::assertSame('https://www.instagram.com/p/ABC123/', $publication['external_url']);
        $this->http->assertAllConsumed();
    }

    public function testThePostFormatChosenInTheEditorReachesTheApi(): void
    {
        [$context, $channel] = $this->instagramChannel();
        $picture = $this->libraryPicture($context, 'square.jpg', 100, 100);
        $this->expectPublishing();

        $this->service()->schedule($context, null, new PostDraft('', [$picture->publicId], new PostOptions(format: 'story'), false, [new VariantInput($channel->publicId)]), $this->in('+10 minutes'));
        $this->clock->advance(11 * 60);
        $this->drain();

        $create = array_values(array_filter($this->http->requests, static fn (array $r): bool => $r['method'] === 'POST' && str_ends_with($r['url'], '/media')))[0];
        self::assertSame('STORIES', $create['options']['form_params']['media_type']);
        self::assertStringContainsString('/original-jpg?', (string) $create['options']['form_params']['image_url']);
    }

    public function testAPostWithoutFilesIsRefusedBeforeItIsScheduled(): void
    {
        [$context, $channel] = $this->instagramChannel();

        $this->expectException(\App\Domain\Post\PostException::class);
        $this->service()->schedule($context, null, $this->draft('Только текст', [$channel]), $this->in('+10 minutes'));
    }

    public function testDeletingAfterAWhileIsNotOfferedToInstagram(): void
    {
        [$context, $channel] = $this->instagramChannel();
        $picture = $this->libraryPicture($context);

        $this->expectException(\App\Domain\Post\PostException::class);
        $this->service()->schedule($context, null, new PostDraft('x', [$picture->publicId], new PostOptions(deleteAfterMinutes: 60), false, [new VariantInput($channel->publicId)]), $this->in('+10 minutes'));
    }
}
