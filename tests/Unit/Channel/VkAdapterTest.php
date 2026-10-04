<?php

declare(strict_types=1);

namespace App\Tests\Unit\Channel;

use App\Domain\Media\MediaKind;
use App\Integrations\Social\Contracts\Credential;
use App\Integrations\Social\Contracts\ErrorKind;
use App\Integrations\Social\Contracts\Platform;
use App\Integrations\Social\Contracts\PlatformError;
use App\Integrations\Social\Contracts\PublishMedia;
use App\Integrations\Social\Contracts\PublishRequest;
use App\Integrations\Social\Contracts\PublishResult;
use App\Integrations\Social\Vk\VkAdapter;
use App\Integrations\Social\Vk\VkApi;
use App\Integrations\Social\Vk\VkCommunities;
use App\Integrations\Social\Vk\VkRateGate;
use App\Tests\Support\MockHttpClient;
use App\Tests\Support\VkFixtures;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(VkAdapter::class)]
#[CoversClass(VkCommunities::class)]
final class VkAdapterTest extends TestCase
{
    private const GROUP = '777';

    private MockHttpClient $http;
    private VkAdapter $adapter;
    private Credential $credential;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        $this->http = new MockHttpClient();
        $api = new VkApi($this->http, new VkRateGate());
        $this->adapter = new VkAdapter($api, new VkCommunities($api));
        $this->credential = new Credential(Platform::Vk, VkFixtures::TOKEN);
    }

    protected function tearDown(): void
    {
        array_map('unlink', $this->files);
    }

    private function api(string $method, string $fixture): void
    {
        $this->http->expect('POST', VkFixtures::API . $method, 200, VkFixtures::raw($fixture));
    }

    private function media(MediaKind $kind, string $name): PublishMedia
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'vka');
        file_put_contents($path, 'content');
        $this->files[] = $path;

        return new PublishMedia($kind, $path, $name, 'application/octet-stream');
    }

    /**
     * @return array<string, mixed> the form of the n-th request to a method
     */
    private function form(string $method, int $nth = 0): array
    {
        $found = array_values(array_filter($this->http->requests, static fn (array $r): bool => str_ends_with($r['url'], '/' . $method)));

        return $found[$nth]['options']['form_params'];
    }

    public function testCapabilities(): void
    {
        $caps = $this->adapter->capabilities();
        self::assertSame(16384, $caps->maxText);
        self::assertSame(10, $caps->maxMedia);
        self::assertFalse($caps->buttons);
        self::assertFalse($caps->silent);
        self::assertTrue($caps->firstComment && $caps->pin && $caps->delete && $caps->polls);
        self::assertSame('plain', $caps->textFormat);
        self::assertSame(50, $caps->maxPostsPerDay);
    }

    public function testATextPostAsTheCommunity(): void
    {
        $this->api('wall.post', 'wall_post');
        $result = $this->adapter->publish(new PublishRequest('Привет!'), self::GROUP, $this->credential, 'idem-key');

        self::assertSame('4321', $result->externalId);
        self::assertSame('https://vk.com/wall-777_4321', $result->url);
        $form = $this->form('wall.post');
        self::assertSame('-777', (string) $form['owner_id']);
        self::assertSame('1', $form['from_group']);
        self::assertSame('Привет!', $form['message']);
        self::assertSame(md5('idem-key'), $form['guid'], 'VK drops a repeated post with the same guid');
        self::assertArrayNotHasKey('attachments', $form);
    }

    public function testPhotosAreUploadedInSeveralSteps(): void
    {
        for ($i = 0; $i < 2; ++$i) {
            $this->api('photos.getWallUploadServer', 'upload_server');
            $this->http->expect('POST', 'https://pu.vk.com/c123/upload.php?act=do_add', 200, VkFixtures::raw('photo_uploaded'));
            $this->api('photos.saveWallPhoto', 'save_wall_photo');
        }
        $this->api('wall.post', 'wall_post');

        $this->adapter->publish(new PublishRequest('Фото', [$this->media(MediaKind::Image, 'a.jpg'), $this->media(MediaKind::Image, 'b.jpg')]), self::GROUP, $this->credential, 'k');

        self::assertSame('photo-777_457_ak1,photo-777_457_ak1', $this->form('wall.post')['attachments']);
        self::assertSame('777', $this->form('photos.getWallUploadServer')['group_id']);
        $save = $this->form('photos.saveWallPhoto');
        self::assertSame('abc123', $save['hash']);
        self::assertSame('123', $save['server']);
        $this->http->assertAllConsumed();
    }

    public function testAVideoAndADocumentAndAPoll(): void
    {
        $this->api('video.save', 'video_save');
        $this->http->expect('POST', 'https://vu.vk.com/upload?x=1', 200, VkFixtures::raw('video_uploaded'));
        $this->api('docs.getWallUploadServer', 'upload_server');
        $this->http->expect('POST', 'https://pu.vk.com/c123/upload.php?act=do_add', 200, VkFixtures::raw('doc_uploaded'));
        $this->api('docs.save', 'docs_save');
        $this->api('polls.create', 'polls_create');
        $this->api('wall.post', 'wall_post');

        $request = new PublishRequest('Всё сразу', [$this->media(MediaKind::Video, 'clip.mp4'), $this->media(MediaKind::Document, 'file.pdf')], poll: ['question' => 'Q?', 'options' => ['да', 'нет'], 'anonymous' => true, 'multiple' => false]);
        $this->adapter->publish($request, self::GROUP, $this->credential, 'k');

        self::assertSame('video-777_99_vkey,doc-777_55_dk,poll-777_88', $this->form('wall.post')['attachments']);
        self::assertSame('0', $this->form('video.save')['wallpost']);
        $poll = $this->form('polls.create');
        self::assertSame('1', $poll['is_anonymous']);
        self::assertSame('0', $poll['is_multiple']);
        self::assertSame('["да","нет"]', $poll['add_answers']);
        $this->http->assertAllConsumed();
    }

    public function testNothingIsPostedWhenAnUploadFails(): void
    {
        $this->api('photos.getWallUploadServer', 'upload_server');
        $this->http->expect('POST', 'https://pu.vk.com/c123/upload.php?act=do_add', 200, '{"error":"too big"}');

        try {
            $this->adapter->publish(new PublishRequest('x', [$this->media(MediaKind::Image, 'a.jpg')]), self::GROUP, $this->credential, 'k');
            self::fail();
        } catch (PlatformError $e) {
            self::assertSame(ErrorKind::Permanent, $e->kind);
        }
        self::assertCount(2, $this->http->requests, 'wall.post was never called');
    }

    public function testWallPostWithoutAnIdIsAnUnknownOutcome(): void
    {
        $this->http->expect('POST', VkFixtures::API . 'wall.post', 200, '{"response":{}}');
        try {
            $this->adapter->publish(new PublishRequest('x'), self::GROUP, $this->credential, 'k');
            self::fail();
        } catch (PlatformError $e) {
            self::assertSame(ErrorKind::UnknownOutcome, $e->kind);
        }
    }

    public function testABadCommunityIdIsRefusedBeforeAnyCall(): void
    {
        $this->expectException(PlatformError::class);
        try {
            $this->adapter->publish(new PublishRequest('x'), '-1001', $this->credential, 'k');
        } finally {
            self::assertSame([], $this->http->requests);
        }
    }

    public function testValidation(): void
    {
        self::assertSame([], $this->adapter->validate(new PublishRequest('Ок')));
        self::assertNotEmpty($this->adapter->validate(new PublishRequest('')), 'empty post');
        self::assertStringContainsString('16384', implode(' ', $this->adapter->validate(new PublishRequest(str_repeat('я', 16385)))));
        self::assertStringContainsString('кнопки', implode(' ', $this->adapter->validate(new PublishRequest('x', buttons: [['text' => 'a', 'url' => 'https://a.b']]))));
        $many = array_map(fn (int $i): PublishMedia => $this->media(MediaKind::Image, "$i.jpg"), range(1, 10));
        self::assertSame([], $this->adapter->validate(new PublishRequest('x', $many)));
        self::assertStringContainsString('10 вложений', implode(' ', $this->adapter->validate(new PublishRequest('x', $many, poll: ['question' => 'Q', 'options' => ['a', 'b'], 'anonymous' => false, 'multiple' => false]))), 'the poll counts as an attachment');
        self::assertNotEmpty($this->adapter->validate(new PublishRequest('x', poll: ['question' => 'Q', 'options' => ['only'], 'anonymous' => false, 'multiple' => false])));
    }

    public function testDeletePinUnpinAndFirstComment(): void
    {
        $published = new PublishResult('4321', null, ['4321']);
        $this->api('wall.delete', 'ok_one');
        $this->api('wall.pin', 'ok_one');
        $this->api('wall.unpin', 'ok_one');
        $this->api('wall.createComment', 'comment');

        $this->adapter->delete($published, self::GROUP, $this->credential);
        $this->adapter->pin($published, self::GROUP, $this->credential, true);
        $this->adapter->pin($published, self::GROUP, $this->credential, false);
        $this->adapter->comment($published, self::GROUP, $this->credential, 'Ссылка в комментарии');

        self::assertSame(['owner_id' => '-777', 'post_id' => '4321'], array_map('strval', array_intersect_key($this->form('wall.delete'), ['owner_id' => 1, 'post_id' => 1])));
        $comment = $this->form('wall.createComment');
        self::assertSame('777', $comment['from_group'], 'the comment is written as the community');
        self::assertSame('Ссылка в комментарии', $comment['message']);
        $this->http->assertAllConsumed();
    }

    public function testEditKeepsTheAttachmentsOfThePost(): void
    {
        $this->api('wall.getById', 'wall_get_by_id');
        $this->api('wall.edit', 'ok_one');
        $this->adapter->edit(new PublishResult('4321', null), self::GROUP, $this->credential, new PublishRequest('Новый текст'), true);

        $edit = $this->form('wall.edit');
        self::assertSame('Новый текст', $edit['message']);
        self::assertSame('photo-777_457_ak1,video-777_99', $edit['attachments'], 'links are rebuilt by VK from the text, the rest is handed back');
    }

    public function testHealthOfAnAdministeredCommunity(): void
    {
        $this->api('groups.get', 'groups_get');
        $status = $this->adapter->healthCheck('777', $this->credential);
        self::assertTrue($status->ok);
        self::assertSame('Моё сообщество', $status->title);
        self::assertSame('admin,editor', $this->form('groups.get')['filter']);
    }

    public function testHealthWhenNoLongerAnAdministrator(): void
    {
        $this->api('groups.get', 'groups_get');
        $status = $this->adapter->healthCheck('555', $this->credential);
        self::assertFalse($status->ok);
        self::assertTrue($status->revoked);
        self::assertStringContainsString('администратор', $status->message);
    }

    public function testHealthOfADeletedCommunity(): void
    {
        $this->api('groups.get', 'groups_get');
        $status = $this->adapter->healthCheck('999', $this->credential);
        self::assertFalse($status->ok);
        self::assertTrue($status->revoked);
    }

    public function testHealthWithARevokedTokenAndWithVkDown(): void
    {
        $this->http->expect('POST', VkFixtures::API . 'groups.get', 200, VkFixtures::error(5, 'User authorization failed'));
        $broken = $this->adapter->healthCheck('777', $this->credential);
        self::assertFalse($broken->ok);
        self::assertFalse($broken->transient);

        $this->http->expect('POST', VkFixtures::API . 'groups.get', 200, VkFixtures::error(10));
        $unknown = $this->adapter->healthCheck('777', $this->credential);
        self::assertTrue($unknown->transient, 'a VK outage says nothing about the channel');
    }

    public function testAnUnknownOutcomeIsResolvedByLookingAtTheWall(): void
    {
        $this->api('wall.get', 'wall_get');
        $since = new DateTimeImmutable('@1700000000');
        // The text matches after whitespace is collapsed, and the post is newer than the attempt.
        $found = $this->adapter->findPublished(new PublishRequest('Привет, мир!'), self::GROUP, $this->credential, $since);

        self::assertNotNull($found);
        self::assertSame('4400', $found->externalId);
        self::assertSame('https://vk.com/wall-777_4400', $found->url);
        self::assertSame('-777', $this->form('wall.get')['owner_id']);
    }

    public function testNothingIsFoundForAnotherTextOrAnOlderPost(): void
    {
        $this->api('wall.get', 'wall_get');
        self::assertNull($this->adapter->findPublished(new PublishRequest('Совсем другой текст'), self::GROUP, $this->credential, new DateTimeImmutable('@1700000000')));

        // "Старый пост" exists but is far older than the attempt: it must not be taken for the new one.
        $this->api('wall.get', 'wall_get');
        self::assertNull($this->adapter->findPublished(new PublishRequest('Старый пост'), self::GROUP, $this->credential, new DateTimeImmutable('@1700000000')));
    }
}
