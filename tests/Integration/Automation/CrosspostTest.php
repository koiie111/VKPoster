<?php

declare(strict_types=1);

namespace App\Tests\Integration\Automation;

use App\Domain\Automation\AutomationException;
use App\Domain\Automation\Crosspost\CrosspostFilter;
use App\Domain\Automation\Crosspost\CrosspostJob;
use App\Domain\Automation\Crosspost\CrosspostProcessor;
use App\Domain\Automation\Crosspost\CrosspostRepository;
use App\Domain\Automation\Crosspost\CrosspostRule;
use App\Domain\Automation\Crosspost\CrosspostService;
use App\Domain\Automation\Crosspost\CrosspostSystem;
use App\Domain\Automation\Crosspost\CrosspostTransform;
use App\Domain\Channel\ChannelMode;
use App\Domain\Post\PostStatus;
use App\Tests\Support\AutomationTestCase;
use App\Tests\Support\MediaFixtures;
use App\Tests\Support\TestEnv;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(CrosspostProcessor::class)]
#[CoversClass(CrosspostService::class)]
#[CoversClass(CrosspostSystem::class)]
#[CoversClass(CrosspostRepository::class)]
#[CoversClass(CrosspostJob::class)]
final class CrosspostTest extends AutomationTestCase
{
    private const CHAT = -1001234567890;

    private \App\Domain\Channel\Channel $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock->set('2026-10-12 06:00:00');
        $this->source = $this->makeChannel($this->workspace, $this->owner, (string) self::CHAT, 'Мой канал');
    }

    private function rules(): CrosspostService
    {
        return $this->app->container()->get(CrosspostService::class);
    }

    private function rule(string $mode = 'draft', int $delay = 0, bool $sync = true, ?CrosspostFilter $filter = null, ?CrosspostTransform $transform = null): CrosspostRule
    {
        return $this->rules()->create($this->context, $this->source->publicId, 'ТГ в ВК', [$this->channel->publicId], $filter ?? new CrosspostFilter(), $transform ?? new CrosspostTransform(), $delay, $mode, $sync);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function channelPost(int $messageId, string $text, array $extra = [], string $kind = 'channel_post'): array
    {
        $body = ['message_id' => $messageId, 'chat' => ['id' => self::CHAT, 'type' => 'channel', 'title' => 'Мой канал'], 'date' => 1790000000];
        $body += isset($extra['photo']) || isset($extra['video']) ? ['caption' => $text] : ['text' => $text];

        return ['update_id' => 5000 + $messageId, $kind => $body + $extra];
    }

    /**
     * @param array<string, mixed> $update
     */
    private function ingest(array $update): void
    {
        $this->app->container()->get(CrosspostProcessor::class)->ingest($update);
    }

    private function expectPhotoDownload(string $fileId, int $size = 40): void
    {
        $this->http->expect('POST', 'https://api.telegram.org/bot' . TestEnv::SHARED_BOT_TOKEN . '/getFile', 200, json_encode(['ok' => true, 'result' => ['file_id' => $fileId, 'file_path' => 'photos/' . $fileId . '.jpg']], JSON_THROW_ON_ERROR));
        $this->http->expect('GET', 'https://api.telegram.org/file/bot' . TestEnv::SHARED_BOT_TOKEN . '/photos/' . $fileId . '.jpg', 200, MediaFixtures::jpeg($size, 20, 'blue'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function madePosts(): array
    {
        return $this->db->select('SELECT * FROM posts ORDER BY id');
    }

    private function postById(int $id): \App\Domain\Post\Post
    {
        return $this->posts()->findById($this->context, $id) ?? throw new \LogicException('No such post.');
    }

    public function testAChannelPostBecomesADraftWithFormattingAndTransformations(): void
    {
        $this->rule(transform: new CrosspostTransform(['@mychannel'], [['from' => 'https://t.me/mychannel', 'to' => 'https://example.com']], 'Больше на example.com'));
        $this->ingest($this->channelPost(10, "Новость дня\nПодробности @mychannel тут", ['entities' => [
            ['type' => 'bold', 'offset' => 0, 'length' => 11],
            ['type' => 'text_link', 'offset' => 35, 'length' => 3, 'url' => 'https://t.me/mychannel/7'],
        ]]));
        self::assertSame(1, $this->runJobs());

        $posts = $this->madePosts();
        self::assertCount(1, $posts);
        self::assertSame('draft', $posts[0]['status']);
        self::assertSame("**Новость дня**\nПодробности [тут](https://example.com/7)\n\nБольше на example.com", $posts[0]['base_text']);
        $variants = $this->posts()->variants($this->context, $this->postById((int) $posts[0]['id']));
        self::assertSame([$this->channel->id], array_map(static fn ($v): ?int => $v->channelId, $variants));
        $rule = $this->app->container()->get(CrosspostRepository::class)->all($this->context)[0];
        self::assertSame(1, $rule->postsCreated);
        self::assertSame('Пост сохранён в черновики.', $rule->lastNote);
    }

    public function testARedeliveredUpdateMakesNoSecondPost(): void
    {
        $this->rule();
        $update = $this->channelPost(11, 'Один раз');
        $this->ingest($update);
        $this->ingest($update);
        $this->ingest($update);
        $this->runJobs();
        self::assertCount(1, $this->madePosts());
        // Even a rule that sees the same message again (a lost buffer) does not post twice.
        $this->db->table('crosspost_buffer')->where('id', '>', 0)->delete();
        $this->ingest($update);
        $this->runJobs();
        self::assertCount(1, $this->madePosts());
    }

    public function testAnAlbumIsCollectedIntoOnePostAfterAShortWait(): void
    {
        $this->rule();
        $this->expectPhotoDownload('big1');
        $this->expectPhotoDownload('big2', 50);
        $photo = static fn (string $id): array => ['photo' => [['file_id' => 'small-' . $id, 'file_size' => 10], ['file_id' => $id, 'file_size' => 900]], 'media_group_id' => 'album-1'];
        $this->ingest($this->channelPost(20, 'Подпись альбома', $photo('big1')));
        $this->ingest($this->channelPost(21, '', $photo('big2')));
        self::assertSame(0, $this->runJobs(), 'the jobs wait for the rest of the album');

        $this->at('2026-10-12 06:00:06');
        self::assertSame(2, $this->runJobs());
        $posts = $this->madePosts();
        self::assertCount(1, $posts);
        self::assertSame('Подпись альбома', $posts[0]['base_text']);
        self::assertCount(2, json_decode((string) $posts[0]['media_ids_json'], true));
        $this->http->assertAllConsumed();
    }

    public function testFiltersSkipForwardedPostsAndUnwantedHashtags(): void
    {
        $this->rule(filter: new CrosspostFilter(['новости'], ['реклама'], true));
        $this->ingest($this->channelPost(30, 'Без тега'));
        $this->ingest($this->channelPost(31, '#новости #реклама'));
        $this->ingest($this->channelPost(32, '#новости пересланное', ['forward_origin' => ['type' => 'channel']]));
        $this->ingest($this->channelPost(33, 'Хорошая #Новости'));
        $this->runJobs();
        $posts = $this->madePosts();
        self::assertCount(1, $posts);
        self::assertSame('Хорошая #Новости', $posts[0]['base_text']);
    }

    public function testImmediateRulesPlanThePostAfterTheDelay(): void
    {
        $this->rule('immediate', 30);
        $this->ingest($this->channelPost(40, 'Через полчаса'));
        $this->runJobs();
        $post = $this->postById((int) $this->madePosts()[0]['id']);
        self::assertSame(PostStatus::Scheduled, $post->status);
        self::assertSame('2026-10-12 06:30:00', $post->scheduledAt?->format('Y-m-d H:i:s'));
        $this->at('2026-10-12 06:30:05');
        $this->drain();
        self::assertSame(PostStatus::Published, $this->postById($post->id)->status);
    }

    public function testAPostThatCannotBePlannedIsKeptAsADraftWithTheReason(): void
    {
        $this->rule('immediate', 0);
        $this->db->table('channels')->where('id', '=', $this->channel->id)->update(['status' => 'error']);
        $this->ingest($this->channelPost(41, 'Канал сломан'));
        $this->runJobs();
        $posts = $this->madePosts();
        self::assertCount(1, $posts);
        self::assertSame('draft', $posts[0]['status'], 'nothing is lost');
        self::assertStringContainsString('сохранено в черновики', (string) $this->app->container()->get(CrosspostRepository::class)->all($this->context)[0]->lastNote);
    }

    public function testEditsOfTheSourceAreFollowedInDraftsAndPublishedPosts(): void
    {
        $this->rule();
        $this->ingest($this->channelPost(50, 'Старый текст'));
        $this->runJobs();
        $id = (int) $this->madePosts()[0]['id'];
        $this->ingest($this->channelPost(50, 'Новый текст', [], 'edited_channel_post'));
        self::assertSame('Новый текст', $this->postById($id)->baseText);

        // Published: the text is edited in the network too.
        $this->service()->schedule($this->context, $this->postById($id), $this->service()->draftOf($this->context, $this->postById($id)), $this->clock->now(), true);
        $this->drain();
        self::assertSame(PostStatus::Published, $this->postById($id)->status);
        $this->ingest($this->channelPost(50, 'Ещё новее', [], 'edited_channel_post'));
        self::assertNotSame([], $this->fake->edited);
        self::assertSame('Ещё новее', $this->postById($id)->baseText);
    }

    public function testRulesWithoutEditSyncIgnoreEdits(): void
    {
        $this->rule(sync: false);
        $this->ingest($this->channelPost(51, 'Как было'));
        $this->runJobs();
        $this->ingest($this->channelPost(51, 'Правка', [], 'edited_channel_post'));
        self::assertSame('Как было', $this->postById((int) $this->madePosts()[0]['id'])->baseText);
    }

    public function testChannelsWithoutRulesAndOtherChatsAreIgnored(): void
    {
        $this->ingest($this->channelPost(60, 'Правил нет'));
        $this->rule();
        $update = $this->channelPost(61, 'Не канал');
        $update['channel_post']['chat']['type'] = 'supergroup';
        $this->ingest($update);
        $elsewhere = $this->channelPost(62, 'Другой чат');
        $elsewhere['channel_post']['chat']['id'] = -100999;
        $this->ingest($elsewhere);
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS n FROM crosspost_buffer')[0]['n']);
    }

    public function testAnUpdateFromTelegramReachesTheRuleThroughTheWebhook(): void
    {
        $this->rule();
        $response = $this->webhook($this->channelPost(70, 'Через вебхук'));
        self::assertSame(200, $response->status);
        $this->runJobs();
        self::assertSame('Через вебхук', $this->madePosts()[0]['base_text']);
    }

    public function testRulesAreLimitedByThePlanTheSourceAndTheRole(): void
    {
        $this->givePlan($this->workspace, 'start');
        $first = $this->rule();
        try {
            $this->rule();
            self::fail('the Start plan allows one rule');
        } catch (AutomationException $e) {
            self::assertTrue($e->planLimit);
        }
        // Switching the first one off frees the place.
        $this->rules()->setEnabled($this->context, $first, false);
        $second = $this->rule();
        try {
            $this->rules()->setEnabled($this->context, $this->app->container()->get(CrosspostRepository::class)->find($this->context, $first->publicId) ?? $first, true);
            self::fail('only one rule may be on');
        } catch (AutomationException) {
            self::assertTrue($second->enabled);
        }

        $this->givePlan($this->workspace, 'free');
        $this->expectException(AutomationException::class);
        $this->rule();
    }

    public function testTheSourceMustBeATelegramChannelOfTheServicesBot(): void
    {
        $own = $this->makeChannel($this->workspace, $this->owner, '-1005555', 'Свой бот', ChannelMode::OwnBot);
        $fake = $this->channel;
        foreach ([$own->publicId, $fake->publicId] as $badSource) {
            try {
                $this->rules()->create($this->context, $badSource, 'Плохо', [$fake->publicId], new CrosspostFilter(), new CrosspostTransform(), 0, 'draft', false);
                self::fail('this source must be refused');
            } catch (AutomationException $e) {
                self::assertStringContainsString('бота сервиса', $e->getMessage());
            }
        }
        $this->expectException(AutomationException::class);
        $this->rules()->create($this->context, $this->source->publicId, 'В себя', [$this->source->publicId], new CrosspostFilter(), new CrosspostTransform(), 0, 'draft', false);
    }
}
