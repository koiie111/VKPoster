<?php

declare(strict_types=1);

namespace App\Tests\Integration\Post;

use App\Domain\Post\PostDraft;
use App\Domain\Post\PostOptions;
use App\Domain\Post\VariantInput;
use App\Integrations\Social\Contracts\Platform;
use App\Tests\Support\MaxFixtures;
use App\Tests\Support\PostTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * A post goes from the editor's markup to the MAX API through the real pipeline (preflight check, variants, adapter), with only the
 * HTTP client replaced.
 */
#[CoversNothing]
final class MaxPublishingTest extends PostTestCase
{
    public function testAPostWithAPhotoAndATextReachesMaxAndItsLinkIsStored(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $channel = $this->makeChannel($workspace, $owner, (string) MaxFixtures::CHANNEL, 'Мой канал', platform: Platform::Max);
        $context = $this->contextFor($workspace, $owner);
        $picture = $this->libraryPicture($context);

        // The upload (slot, file) and the message.
        $this->max('POST', '/uploads', 'upload_slot_image');
        $this->http->expect('POST', 'https://upload.max.example/upload?type=image&id=1', 200, MaxFixtures::raw('upload_image_done'));
        $this->max('POST', '/messages', 'message_sent');

        $post = $this->service()->schedule($context, null, new PostDraft('**Привет**, [сайт](https://example.com)', [$picture->publicId], new PostOptions(), false, [new VariantInput($channel->publicId)]), $this->in('+10 minutes'));
        $this->clock->advance(11 * 60);
        $this->drain();

        $message = array_values(array_filter($this->http->requests, static fn (array $r): bool => $r['method'] === 'POST' && str_ends_with($r['url'], '/messages')))[0];
        self::assertSame('<b>Привет</b>, <a href="https://example.com">сайт</a>', $message['options']['json']['text']);
        self::assertSame('html', $message['options']['json']['format']);
        self::assertSame([['type' => 'image', 'payload' => ['token' => 'photo-token-xyz']]], $message['options']['json']['attachments']);
        self::assertSame(MaxFixtures::CHANNEL, $message['options']['query']['chat_id']);
        self::assertSame('published', $this->db->select('SELECT status FROM posts WHERE id = ?', [$post->id])[0]['status']);
        $this->http->assertAllConsumed();
        $publication = $this->db->select('SELECT external_post_id, external_url, status FROM publications')[0];
        self::assertSame('sent', $publication['status']);
        self::assertSame('mid.0000000000000001', $publication["external_post_id"]);
        self::assertSame('https://max.ru/mychannel/AZ1234', $publication['external_url']);
    }

    public function testARevokedTokenFailsThePublicationAndMarksTheChannel(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $channel = $this->makeChannel($workspace, $owner, (string) MaxFixtures::CHANNEL, 'Мой канал', platform: Platform::Max);
        $context = $this->contextFor($workspace, $owner);
        $this->max('POST', '/messages', 'error_401', 401);

        $this->service()->schedule($context, null, $this->draft('Текст', [$channel]), $this->in('+10 minutes'));
        $this->clock->advance(11 * 60);
        $this->drain();

        $publication = $this->db->select('SELECT status, error_code FROM publications')[0];
        self::assertSame('failed', $publication['status']);
        self::assertSame('auth', $publication['error_code']);
        self::assertSame('error', $this->db->select('SELECT status FROM channels')[0]['status']);
        self::assertNotSame('', (string) $this->db->select('SELECT last_error FROM channels')[0]['last_error']);
    }
}
