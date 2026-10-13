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
use App\Integrations\Social\Instagram\InstagramAccounts;
use App\Integrations\Social\Instagram\InstagramAdapter;
use App\Integrations\Social\Instagram\InstagramApi;
use App\Tests\Support\InstagramFixtures as Fx;
use App\Tests\Support\MockHttpClient;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InstagramAdapter::class)]
#[CoversClass(InstagramApi::class)]
#[CoversClass(InstagramAccounts::class)]
final class InstagramAdapterTest extends TestCase
{
    private MockHttpClient $http;
    private InstagramAdapter $adapter;
    private Credential $credential;

    /** @var list<int> pauses the adapter asked for */
    private array $pauses = [];

    protected function setUp(): void
    {
        $this->http = new MockHttpClient();
        $api = new InstagramApi($this->http);
        $this->adapter = new InstagramAdapter($api, new InstagramAccounts($api), 100, function (int $seconds): void {
            $this->pauses[] = $seconds;
        });
        $this->credential = new Credential(Platform::Instagram, Fx::TOKEN);
    }

    private function image(string $n = '1'): PublishMedia
    {
        return new PublishMedia(MediaKind::Image, '', 'photo' . $n . '.jpg', 'image/jpeg', 'https://ezposter.example/media/IMG' . $n . '/4x5-jpg?signature=s');
    }

    private function video(): PublishMedia
    {
        return new PublishMedia(MediaKind::Video, '', 'clip.mp4', 'video/mp4', 'https://ezposter.example/media/VID1/original?signature=s');
    }

    private function get(string $path, string $fixture): void
    {
        $this->http->expect('GET', Fx::GRAPH . $path, 200, Fx::raw($fixture));
    }

    private function post(string $path, string $fixture, int $status = 200): void
    {
        $this->http->expect('POST', Fx::GRAPH . $path, $status, Fx::raw($fixture));
    }

    /**
     * @return array<string, mixed> the form of the n-th POST to a path
     */
    private function form(string $path, int $nth = 0): array
    {
        $found = array_values(array_filter($this->http->requests, static fn (array $r): bool => $r['method'] === 'POST' && $r['url'] === Fx::GRAPH . $path));

        return $found[$nth]['options']['form_params'];
    }

    private function publish(PublishRequest $request): PublishResult
    {
        return $this->adapter->publish($request, Fx::ACCOUNT, $this->credential, 'idem-1');
    }

    public function testCapabilitiesDescribeWhatTheApiCanDo(): void
    {
        $caps = $this->adapter->capabilities();

        self::assertSame(2200, $caps->maxCaption);
        self::assertSame(10, $caps->maxMedia);
        self::assertTrue($caps->publicMediaUrls);
        self::assertTrue($caps->postFormats);
        self::assertTrue($caps->firstComment);
        self::assertFalse($caps->delete);
        self::assertFalse($caps->pin);
        self::assertFalse($caps->buttons);
        self::assertFalse($caps->polls);
        self::assertSame(100, $caps->maxPostsPerDay);
    }

    public function testAPhotoBecomesAContainerThenAPost(): void
    {
        $this->get(Fx::ACCOUNT . '/content_publishing_limit', 'limit_ok');
        $this->post(Fx::ACCOUNT . '/media', 'container');
        $this->get('17900000000000001', 'status_finished');
        $this->post(Fx::ACCOUNT . '/media_publish', 'publish');
        $this->get('17950000000000009', 'permalink');

        $result = $this->publish(new PublishRequest('Привет, #кофе', [$this->image()]));

        self::assertSame('17950000000000009', $result->externalId);
        self::assertSame('https://www.instagram.com/p/ABC123/', $result->url);
        $form = $this->form(Fx::ACCOUNT . '/media');
        self::assertSame('Привет, #кофе', $form['caption']);
        self::assertSame('https://ezposter.example/media/IMG1/4x5-jpg?signature=s', $form['image_url']);
        self::assertArrayNotHasKey('media_type', $form);
        self::assertSame('17900000000000001', $this->form(Fx::ACCOUNT . '/media_publish')['creation_id']);
        $this->http->assertAllConsumed();
        // The token only ever travels in the header.
        foreach ($this->http->requests as $request) {
            self::assertSame('Bearer ' . Fx::TOKEN, $request['options']['headers']['Authorization']);
            self::assertStringNotContainsString(Fx::TOKEN, $request['url'] . json_encode($request['options']['query'] ?? []) . json_encode($request['options']['form_params'] ?? []));
        }
    }

    public function testASingleVideoIsPublishedAsAReelAndWaitsForProcessing(): void
    {
        $this->get(Fx::ACCOUNT . '/content_publishing_limit', 'limit_ok');
        $this->post(Fx::ACCOUNT . '/media', 'container');
        $this->get('17900000000000001', 'status_in_progress');
        $this->get('17900000000000001', 'status_in_progress');
        $this->get('17900000000000001', 'status_finished');
        $this->post(Fx::ACCOUNT . '/media_publish', 'publish');
        $this->get('17950000000000009', 'permalink');

        $this->publish(new PublishRequest('Ролик', [$this->video()]));

        $form = $this->form(Fx::ACCOUNT . '/media');
        self::assertSame('REELS', $form['media_type']);
        self::assertSame('https://ezposter.example/media/VID1/original?signature=s', $form['video_url']);
        self::assertSame('true', $form['share_to_feed']);
        self::assertSame([2, 3], $this->pauses, 'growing pauses between looks at the container');
        $this->http->assertAllConsumed();
    }

    public function testACarouselCreatesChildrenFirst(): void
    {
        $this->get(Fx::ACCOUNT . '/content_publishing_limit', 'limit_ok');
        $this->post(Fx::ACCOUNT . '/media', 'container');
        $this->post(Fx::ACCOUNT . '/media', 'container_2');
        $this->post(Fx::ACCOUNT . '/media', 'container_3');
        $this->get('17900000000000003', 'status_finished');
        $this->post(Fx::ACCOUNT . '/media_publish', 'publish');
        $this->get('17950000000000009', 'permalink');

        $this->publish(new PublishRequest('Альбом', [$this->image('1'), $this->image('2')]));

        $first = $this->form(Fx::ACCOUNT . '/media', 0);
        self::assertSame('true', $first['is_carousel_item']);
        self::assertArrayNotHasKey('caption', $first, 'the caption belongs to the carousel');
        $carousel = $this->form(Fx::ACCOUNT . '/media', 2);
        self::assertSame('CAROUSEL', $carousel['media_type']);
        self::assertSame('17900000000000001,17900000000000002', $carousel['children']);
        self::assertSame('Альбом', $carousel['caption']);
        $this->http->assertAllConsumed();
    }

    public function testAStoryHasNoCaption(): void
    {
        $this->get(Fx::ACCOUNT . '/content_publishing_limit', 'limit_ok');
        $this->post(Fx::ACCOUNT . '/media', 'container');
        $this->get('17900000000000001', 'status_finished');
        $this->post(Fx::ACCOUNT . '/media_publish', 'publish');
        $this->get('17950000000000009', 'permalink');

        $this->publish(new PublishRequest('', [$this->image()], mode: 'story'));

        $form = $this->form(Fx::ACCOUNT . '/media');
        self::assertSame('STORIES', $form['media_type']);
        self::assertArrayNotHasKey('caption', $form);
        self::assertArrayHasKey('image_url', $form);
    }

    public function testAnErroredContainerIsPermanentAndNothingIsPublished(): void
    {
        $this->get(Fx::ACCOUNT . '/content_publishing_limit', 'limit_ok');
        $this->post(Fx::ACCOUNT . '/media', 'container');
        $this->get('17900000000000001', 'status_error');

        try {
            $this->publish(new PublishRequest('x', [$this->image()]));
            self::fail('expected an error');
        } catch (PlatformError $e) {
            self::assertSame(ErrorKind::Permanent, $e->kind);
            self::assertStringContainsString('формат', $e->forUser());
        }
        $this->http->assertAllConsumed();
    }

    public function testAContainerThatStaysInProgressIsTemporary(): void
    {
        $this->get(Fx::ACCOUNT . '/content_publishing_limit', 'limit_ok');
        $this->post(Fx::ACCOUNT . '/media', 'container');
        for ($i = 0; $i < 14; ++$i) {
            $this->get('17900000000000001', 'status_in_progress');
        }

        try {
            $this->publish(new PublishRequest('x', [$this->video()]));
            self::fail('expected an error');
        } catch (PlatformError $e) {
            self::assertSame(ErrorKind::Temporary, $e->kind, 'nothing was published: a retry is safe');
        }
        self::assertGreaterThan(200, array_sum($this->pauses));
    }

    public function testTheDailyLimitStopsBeforeAnythingIsCreated(): void
    {
        $this->get(Fx::ACCOUNT . '/content_publishing_limit', 'limit_full');

        try {
            $this->publish(new PublishRequest('x', [$this->image()]));
            self::fail('expected an error');
        } catch (PlatformError $e) {
            self::assertSame(ErrorKind::RateLimited, $e->kind);
            self::assertSame(3600, $e->retryAfter);
        }
        self::assertCount(1, $this->http->requests);
    }

    public function testALostAnswerOfMediaPublishIsAnUnknownOutcome(): void
    {
        $this->get(Fx::ACCOUNT . '/content_publishing_limit', 'limit_ok');
        $this->post(Fx::ACCOUNT . '/media', 'container');
        $this->get('17900000000000001', 'status_finished');
        $this->http->expect('POST', Fx::GRAPH . Fx::ACCOUNT . '/media_publish', 502, '<html>bad gateway</html>');

        try {
            $this->publish(new PublishRequest('x', [$this->image()]));
            self::fail('expected an error');
        } catch (PlatformError $e) {
            self::assertSame(ErrorKind::UnknownOutcome, $e->kind);
        }
    }

    public function testFilesWithoutAPublicHttpsLinkAreRefused(): void
    {
        $local = new PublishMedia(MediaKind::Image, '/tmp/x.jpg', 'x.jpg', 'image/jpeg', 'http://localhost/media/x');

        try {
            $this->publish(new PublishRequest('x', [$local]));
            self::fail('expected an error');
        } catch (PlatformError $e) {
            self::assertSame(ErrorKind::Permanent, $e->kind);
            self::assertStringContainsString('APP_URL', $e->forUser());
        }
        self::assertSame([], $this->http->requests);
    }

    public function testValidateExplainsWhatInstagramCannotTake(): void
    {
        $many = implode(' ', array_map(static fn (int $i): string => '#t' . $i, range(1, 31)));
        $problems = $this->adapter->validate(new PublishRequest(
            $many . str_repeat('я', 2200),
            [new PublishMedia(MediaKind::Document, '', 'a.pdf', 'application/pdf')],
            [['text' => 'Кнопка', 'url' => 'https://example.com']],
            ['question' => 'Q', 'options' => ['a', 'b'], 'anonymous' => true, 'multiple' => false],
        ));
        $text = implode(' | ', $problems);

        self::assertStringContainsString('2200', $text);
        self::assertStringContainsString('хэштегов', $text);
        self::assertStringContainsString('кнопки', $text);
        self::assertStringContainsString('Опросы', $text);
        self::assertStringContainsString('документы', $text);

        self::assertNotEmpty($this->adapter->validate(new PublishRequest('Только текст')), 'a post needs a file');
        self::assertNotEmpty($this->adapter->validate(new PublishRequest('x', [$this->image(), $this->video()], mode: 'reel')), 'Reels are one video');
        self::assertNotEmpty($this->adapter->validate(new PublishRequest('x', [$this->image(), $this->image('2')], mode: 'story')), 'a story is one file');
        self::assertNotEmpty($this->adapter->validate(new PublishRequest('x', array_map(fn (int $i): PublishMedia => $this->image((string) $i), range(1, 11)))));
        self::assertSame([], $this->adapter->validate(new PublishRequest('Ок', [$this->image(), $this->video()])));
        self::assertSame([], $this->adapter->validate(new PublishRequest('Ок', [$this->video()])));
    }

    public function testDeletingAndPinningAreNotPossible(): void
    {
        $published = new PublishResult('1', null);

        foreach ([
            fn () => $this->adapter->delete($published, Fx::ACCOUNT, $this->credential),
            fn () => $this->adapter->pin($published, Fx::ACCOUNT, $this->credential, true),
        ] as $call) {
            try {
                $call();
                self::fail('expected an error');
            } catch (PlatformError $e) {
                self::assertSame(ErrorKind::Permanent, $e->kind);
                self::assertStringContainsString('Instagram', $e->forUser());
            }
        }
        self::assertSame([], $this->http->requests);
    }

    public function testTheFirstCommentIsPostedUnderTheMedia(): void
    {
        $this->post('17950000000000009/comments', 'comment');

        $this->adapter->comment(new PublishResult('17950000000000009', null), Fx::ACCOUNT, $this->credential, 'Ссылка в профиле');

        self::assertSame('Ссылка в профиле', $this->form('17950000000000009/comments')['message']);
    }

    public function testHealthCheckReadsTheAccount(): void
    {
        $this->get('me', 'me');
        $ok = $this->adapter->healthCheck(Fx::ACCOUNT, $this->credential);
        self::assertTrue($ok->ok);
        self::assertSame('Кофейня «Зерно»', $ok->title);
        self::assertFalse($ok->rights['delete']);

        $this->get('me', 'me_personal');
        $personal = $this->adapter->healthCheck('17841400000000002', $this->credential);
        self::assertFalse($personal->ok);
        self::assertTrue($personal->revoked);

        $this->get('me', 'me');
        $other = $this->adapter->healthCheck('555', $this->credential);
        self::assertFalse($other->ok);
        self::assertTrue($other->revoked, 'the token belongs to another account');
    }

    public function testHealthCheckSeparatesARevokedTokenFromAnOutage(): void
    {
        $this->http->expect('GET', Fx::GRAPH . 'me', 400, Fx::error(190, 460, 'Error validating access token'));
        $revoked = $this->adapter->healthCheck(Fx::ACCOUNT, $this->credential);
        self::assertFalse($revoked->ok);
        self::assertFalse($revoked->transient);
        self::assertTrue($revoked->revoked);

        $this->http->expect('GET', Fx::GRAPH . 'me', 500, Fx::error(2));
        $down = $this->adapter->healthCheck(Fx::ACCOUNT, $this->credential);
        self::assertTrue($down->transient, 'an outage must not mark the channel broken');
    }

    public function testAnUnknownOutcomeIsLookedUpByCaptionAndTime(): void
    {
        $this->get(Fx::ACCOUNT . '/media', 'media_list');

        $found = $this->adapter->findPublished(new PublishRequest('Новое меню уже здесь', [$this->image()]), Fx::ACCOUNT, $this->credential, new DateTimeImmutable('2026-10-14T10:00:00+00:00'));

        self::assertNotNull($found);
        self::assertSame('17950000000000009', $found->externalId);

        $this->get(Fx::ACCOUNT . '/media', 'media_list');
        self::assertNull($this->adapter->findPublished(new PublishRequest('Другой текст', [$this->image()]), Fx::ACCOUNT, $this->credential, new DateTimeImmutable('2026-10-14T10:00:00+00:00')));

        $this->get(Fx::ACCOUNT . '/media', 'media_list');
        self::assertNull($this->adapter->findPublished(new PublishRequest('Старый пост', [$this->image()]), Fx::ACCOUNT, $this->credential, new DateTimeImmutable('2026-10-14T10:00:00+00:00')), 'older than the attempt');
    }

    public function testErrorCodesAreClassified(): void
    {
        $cases = [
            [190, 0, ErrorKind::Auth],
            [200, 0, ErrorKind::Auth],
            [4, 0, ErrorKind::RateLimited],
            [9, 2207042, ErrorKind::RateLimited],
            [2, 0, ErrorKind::Temporary],
            [9007, 0, ErrorKind::Temporary],
            [100, 0, ErrorKind::Permanent],
            [36003, 2207026, ErrorKind::Permanent],
        ];
        foreach ($cases as [$code, $sub, $kind]) {
            $api = new InstagramApi($client = new MockHttpClient());
            $client->expect('GET', Fx::GRAPH . 'me', 400, Fx::error($code, $sub));
            try {
                $api->get('me', [], Fx::TOKEN);
                self::fail('expected an error');
            } catch (PlatformError $e) {
                self::assertSame($kind, $e->kind, sprintf('code %d/%d', $code, $sub));
                self::assertStringNotContainsString(Fx::TOKEN, $e->getMessage());
            }
        }
    }
}
