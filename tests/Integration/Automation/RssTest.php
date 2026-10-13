<?php

declare(strict_types=1);

namespace App\Tests\Integration\Automation;

use App\Domain\Automation\AutomationException;
use App\Domain\Automation\Rss\FeedFetcher;
use App\Domain\Automation\Rss\RssFeedJob;
use App\Domain\Automation\Rss\RssPoller;
use App\Domain\Automation\Rss\RssRepository;
use App\Domain\Automation\Rss\RssService;
use App\Domain\Post\PostStatus;
use App\Tests\Support\AutomationTestCase;
use App\Tests\Support\MediaFixtures;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(RssService::class)]
#[CoversClass(RssPoller::class)]
#[CoversClass(RssRepository::class)]
#[CoversClass(FeedFetcher::class)]
#[CoversClass(RssFeedJob::class)]
final class RssTest extends AutomationTestCase
{
    private const URL = 'https://blog.example/feed.xml';

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock->set('2026-10-12 06:00:00');
    }

    private function feedXml(string ...$entries): string
    {
        return '<?xml version="1.0"?><rss version="2.0"><channel><title>Blog</title>' . implode('', $entries) . '</channel></rss>';
    }

    private function entry(string $id, string $title, string $extra = ''): string
    {
        return '<item><title>' . $title . '</title><link>https://blog.example/' . $id . '</link><guid>' . $id . '</guid><description>Кратко про ' . $id . '</description>' . $extra . '</item>';
    }

    private function rss(): RssService
    {
        return $this->app->container()->get(RssService::class);
    }

    private function poller(): RssPoller
    {
        return $this->app->container()->get(RssPoller::class);
    }

    private function createFeed(string $mode = 'draft', ?string $queue = null, string $template = ''): \App\Domain\Automation\Rss\RssFeed
    {
        $this->http->expect('GET', self::URL, 200, $this->feedXml($this->entry('old', 'Старая запись')), ['ETag' => '"v1"']);

        return $this->rss()->create($this->context, 'Блог', self::URL, $template, $mode, $queue, 60, [$this->channel->publicId]);
    }

    private function posts_(): array
    {
        return $this->db->select('SELECT * FROM posts ORDER BY id');
    }

    public function testNewEntriesBecomePostsOnceAndTheFirstReadingOnlyNotesWhatIsThere(): void
    {
        $feed = $this->createFeed();
        $this->http->expect('GET', self::URL, 200, $this->feedXml($this->entry('old', 'Старая запись')), ['ETag' => '"v1"']);
        self::assertSame(0, $this->poller()->poll($feed->id), 'the archive is not published');
        self::assertSame([], $this->posts_());

        $two = $this->feedXml($this->entry('b', 'Вторая *звёздная*'), $this->entry('a', 'Первая'), $this->entry('old', 'Старая запись'));
        $this->http->expect('GET', self::URL, 200, $two, ['ETag' => '"v2"']);
        self::assertSame(2, $this->poller()->poll($feed->id));
        $posts = $this->posts_();
        self::assertCount(2, $posts);
        self::assertSame("Первая\n\nКратко про a\n\nhttps://blog.example/a", $posts[0]['base_text'], 'oldest entry first, the default template');
        self::assertStringContainsString('Вторая \\*звёздная\\*', (string) $posts[1]['base_text'], 'markup in a title is escaped');

        // The same feed again: nothing new, nothing doubled.
        $this->http->expect('GET', self::URL, 200, $two);
        self::assertSame(0, $this->poller()->poll($feed->id));
        self::assertCount(2, $this->posts_());
        self::assertSame(3, (int) $this->db->select('SELECT COUNT(*) AS n FROM rss_items')[0]['n']);
    }

    public function testConditionalRequestsAndANotModifiedAnswer(): void
    {
        $feed = $this->createFeed();
        $this->http->expect('GET', self::URL, 200, $this->feedXml($this->entry('old', 'Старая')), ['ETag' => '"v1"', 'Last-Modified' => 'Mon, 12 Oct 2026 05:00:00 GMT']);
        $this->poller()->poll($feed->id);
        $this->http->expect('GET', self::URL, 304);
        self::assertSame(0, $this->poller()->poll($feed->id));
        $last = end($this->http->requests);
        self::assertSame('"v1"', $last['options']['headers']['If-None-Match']);
        self::assertSame('Mon, 12 Oct 2026 05:00:00 GMT', $last['options']['headers']['If-Modified-Since']);
        self::assertTrue($last['options']['user_url'], 'a user URL always goes through the SSRF guard');
        self::assertNull($this->db->select('SELECT last_error FROM rss_feeds')[0]['last_error']);
    }

    public function testTheTemplateAndThePictureOfAnEntry(): void
    {
        $feed = $this->createFeed(template: "{title}\n{link}");
        $this->http->expect('GET', self::URL, 200, $this->feedXml($this->entry('old', 'Старая')));
        $this->poller()->poll($feed->id);
        $image = '<enclosure url="https://cdn.example/pic.jpg" type="image/jpeg" length="1"/>';
        $this->http->expect('GET', self::URL, 200, $this->feedXml($this->entry('new', 'С картинкой', $image), $this->entry('old', 'Старая')));
        $this->http->expect('GET', 'https://cdn.example/pic.jpg', 200, MediaFixtures::jpeg(), ['Content-Type' => 'image/jpeg']);
        self::assertSame(1, $this->poller()->poll($feed->id));
        $post = $this->posts_()[0];
        self::assertSame("С картинкой\nhttps://blog.example/new", $post['base_text']);
        self::assertCount(1, json_decode((string) $post['media_ids_json'], true));
        $this->http->assertAllConsumed();
    }

    public function testAPictureThatCannotBeFetchedDoesNotStopThePost(): void
    {
        $feed = $this->createFeed();
        $this->http->expect('GET', self::URL, 200, $this->feedXml($this->entry('old', 'Старая')));
        $this->poller()->poll($feed->id);
        $this->http->expect('GET', self::URL, 200, $this->feedXml($this->entry('new', 'Без картинки', '<enclosure url="https://cdn.example/gone.jpg" type="image/jpeg"/>'), $this->entry('old', 'Старая')));
        $this->http->expect('GET', 'https://cdn.example/gone.jpg', 404);
        self::assertSame(1, $this->poller()->poll($feed->id));
        self::assertSame([], json_decode((string) $this->posts_()[0]['media_ids_json'], true));
    }

    public function testQueueModePutsEntriesIntoTheQueue(): void
    {
        $queue = $this->weekdayQueue();
        $feed = $this->createFeed('queue', $queue->publicId);
        $this->http->expect('GET', self::URL, 200, $this->feedXml($this->entry('old', 'Старая')));
        $this->poller()->poll($feed->id);
        $this->http->expect('GET', self::URL, 200, $this->feedXml($this->entry('new', 'Новая'), $this->entry('old', 'Старая')));
        $this->poller()->poll($feed->id);
        $post = $this->posts()->findById($this->context, (int) $this->posts_()[0]['id']);
        self::assertSame(PostStatus::Scheduled, $post?->status);
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS n FROM queue_entries')[0]['n']);
    }

    public function testAFailingFeedIsRetriedLaterAndLaterAndTheErrorIsShown(): void
    {
        $feed = $this->createFeed();
        foreach ([1, 2, 3] as $attempt) {
            $this->http->expect('GET', self::URL, 500);
            $this->poller()->poll($feed->id);
            $row = $this->db->select('SELECT last_error, error_count, next_check_at FROM rss_feeds')[0];
            self::assertSame($attempt, (int) $row['error_count']);
            self::assertStringContainsString('500', (string) $row['last_error']);
        }
        $minutes = (strtotime((string) $row['next_check_at'] . ' UTC') - strtotime('2026-10-12 06:00:00 UTC')) / 60;
        self::assertSame(240.0, (float) $minutes, 'the wait doubles with every failure: 60, 120, 240 minutes');
        $this->http->expect('GET', self::URL, 200, $this->feedXml());
        $this->poller()->poll($feed->id);
        self::assertSame(0, (int) $this->db->select('SELECT error_count FROM rss_feeds')[0]['error_count']);
    }

    public function testTheSchedulerQueuesDueFeedsOnceAndTheJobReadsThem(): void
    {
        $feed = $this->createFeed();
        self::assertSame(1, $this->poller()->tick());
        self::assertSame(0, $this->poller()->tick(), 'its next time moved on at once');
        $this->http->expect('GET', self::URL, 200, $this->feedXml($this->entry('old', 'Старая')));
        $this->runJobs();
        self::assertNotNull($this->db->select('SELECT last_checked_at FROM rss_feeds WHERE id = ?', [$feed->id])[0]['last_checked_at']);
        $this->at('2026-10-12 07:01:00');
        self::assertSame(1, $this->poller()->tick(), 'an hour later it is due again');
    }

    public function testFeedsAreCheckedAndLimited(): void
    {
        foreach ([
            ['', self::URL, 'Название'],
            ['Блог', 'ftp://blog.example/feed', 'url'],
            ['Блог', 'javascript:alert(1)', 'url'],
        ] as [$name, $url]) {
            try {
                $this->rss()->create($this->context, $name, $url, '', 'draft', null, 60, [$this->channel->publicId]);
                self::fail('must be refused: ' . $url);
            } catch (AutomationException $e) {
                self::assertFalse($e->forbidden);
            }
        }
        $this->http->expect('GET', self::URL, 200, '<html>not a feed</html>');
        try {
            $this->rss()->create($this->context, 'Блог', self::URL, '', 'draft', null, 60, [$this->channel->publicId]);
            self::fail('a page that is not a feed');
        } catch (AutomationException $e) {
            self::assertStringContainsString('RSS', $e->getMessage());
        }
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS n FROM rss_feeds')[0]['n']);

        $this->givePlan($this->workspace, 'start');
        $first = $this->createFeed();
        $this->http->expect('GET', 'https://other.example/feed', 200, $this->feedXml());
        try {
            $this->rss()->create($this->context, 'Второй', 'https://other.example/feed', '', 'draft', null, 60, [$this->channel->publicId]);
            self::fail('the Start plan has one feed');
        } catch (AutomationException $e) {
            self::assertTrue($e->planLimit);
        }
        $this->givePlan($this->workspace, 'free');
        $this->expectException(AutomationException::class);
        $this->rss()->setEnabled($this->context, $first, true);
        $this->rss()->create($this->context, 'Третий', self::URL, '', 'draft', null, 60, [$this->channel->publicId]);
    }

    public function testPreviewShowsTheLatestEntriesWithoutMakingAnything(): void
    {
        $this->http->expect('GET', self::URL, 200, $this->feedXml($this->entry('a', 'Первая'), $this->entry('b', 'Вторая'), $this->entry('c', 'Третья'), $this->entry('d', 'Четвёртая')));
        $preview = $this->poller()->preview(self::URL, "{title}!");
        self::assertCount(3, $preview);
        self::assertSame('Первая!', $preview[0]['text']);
        self::assertSame([], $this->posts_());
    }
}
