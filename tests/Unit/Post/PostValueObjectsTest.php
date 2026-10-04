<?php

declare(strict_types=1);

namespace App\Tests\Unit\Post;

use App\Domain\Post\Post;
use App\Domain\Post\PostException;
use App\Domain\Post\PostOptions;
use App\Domain\Post\PostStatus;
use App\Domain\Post\PostStatusAggregator;
use App\Domain\Post\Publication;
use App\Domain\Post\PublicationStatus;
use App\Domain\Post\ScheduleTime;
use App\Domain\Post\TextFormatter;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PublicationStatus::class)]
#[CoversClass(PostStatusAggregator::class)]
#[CoversClass(PostOptions::class)]
#[CoversClass(ScheduleTime::class)]
#[CoversClass(TextFormatter::class)]
#[CoversClass(Post::class)]
#[CoversClass(PostStatus::class)]
final class PostValueObjectsTest extends TestCase
{
    /**
     * The whole state machine, written out: anything not listed here must be refused.
     */
    public function testEveryPublicationTransitionIsDecidedExplicitly(): void
    {
        $allowed = [
            'queued' => ['sending', 'cancelled'],
            'sending' => ['sent', 'queued', 'failed', 'unknown'],
            'failed' => ['queued'],
            'unknown' => ['queued', 'sent', 'cancelled'],
            'sent' => [],
            'cancelled' => [],
        ];
        foreach (PublicationStatus::cases() as $from) {
            foreach (PublicationStatus::cases() as $to) {
                self::assertSame(in_array($to->value, $allowed[$from->value], true), $from->canMoveTo($to), $from->value . ' → ' . $to->value);
            }
        }
        self::assertTrue(PublicationStatus::Sent->isFinal());
        self::assertTrue(PublicationStatus::Cancelled->isFinal());
        self::assertFalse(PublicationStatus::Unknown->isFinal());
    }

    public function testAnUnknownOutcomeCannotBecomeSendingByItself(): void
    {
        self::assertFalse(PublicationStatus::Unknown->canMoveTo(PublicationStatus::Sending), 'only a person may retry it, through "queued"');
    }

    private function publication(PublicationStatus $status, int $attempt = 0): Publication
    {
        $at = new DateTimeImmutable('2026-01-01');

        return new Publication(1, 'X', 1, 1, 1, 1, $status, $attempt, $at, $at, null, 'k', null, [], null, null, null, null, null, null, null, null, null, false);
    }

    /**
     * @return array<string, array{list<array{PublicationStatus, int}>, PostStatus, PostStatus}>
     */
    public static function aggregates(): array
    {
        return [
            'nothing planned yet' => [[], PostStatus::Draft, PostStatus::Draft],
            'all queued' => [[[PublicationStatus::Queued, 0], [PublicationStatus::Queued, 0]], PostStatus::Scheduled, PostStatus::Scheduled],
            'one is sending' => [[[PublicationStatus::Sending, 1], [PublicationStatus::Queued, 0]], PostStatus::Scheduled, PostStatus::Publishing],
            'one sent, one still queued' => [[[PublicationStatus::Sent, 1], [PublicationStatus::Queued, 0]], PostStatus::Scheduled, PostStatus::Publishing],
            'retry waiting' => [[[PublicationStatus::Queued, 1]], PostStatus::Publishing, PostStatus::Publishing],
            'all sent' => [[[PublicationStatus::Sent, 1], [PublicationStatus::Sent, 1]], PostStatus::Publishing, PostStatus::Published],
            'some sent, some failed' => [[[PublicationStatus::Sent, 1], [PublicationStatus::Failed, 5]], PostStatus::Publishing, PostStatus::PartiallyFailed],
            'some sent, one unknown' => [[[PublicationStatus::Sent, 1], [PublicationStatus::Unknown, 1]], PostStatus::Publishing, PostStatus::PartiallyFailed],
            'none sent' => [[[PublicationStatus::Failed, 5], [PublicationStatus::Failed, 1]], PostStatus::Publishing, PostStatus::Failed],
            'only unknown' => [[[PublicationStatus::Unknown, 1]], PostStatus::Publishing, PostStatus::Failed],
            'cancelled ones do not count' => [[[PublicationStatus::Sent, 1], [PublicationStatus::Cancelled, 0]], PostStatus::Publishing, PostStatus::Published],
            'everything cancelled' => [[[PublicationStatus::Cancelled, 0]], PostStatus::Scheduled, PostStatus::Cancelled],
            'everything cancelled, back to draft' => [[[PublicationStatus::Cancelled, 0]], PostStatus::Draft, PostStatus::Draft],
        ];
    }

    /**
     * @param list<array{PublicationStatus, int}> $publications
     */
    #[DataProvider('aggregates')]
    public function testThePostStatusIsDerivedFromItsPublications(array $publications, PostStatus $current, PostStatus $expected): void
    {
        $list = array_map(fn (array $p): Publication => $this->publication($p[0], $p[1]), $publications);

        self::assertSame($expected, PostStatusAggregator::aggregate($current, $list));
    }

    /**
     * @return array<string, array{string, string, string, string}> input, html, plain, visible
     */
    public static function formats(): array
    {
        return [
            'bold' => ['Это **важно**', 'Это <b>важно</b>', 'Это важно', 'Это важно'],
            'italic star and underscore' => ['*раз* и _два_', '<i>раз</i> и <i>два</i>', 'раз и два', 'раз и два'],
            'strike' => ['~~старое~~', '<s>старое</s>', 'старое', 'старое'],
            'link' => ['Смотри [сайт](https://example.com/a?b=1&c=2)', 'Смотри <a href="https://example.com/a?b=1&amp;c=2">сайт</a>', 'Смотри сайт (https://example.com/a?b=1&c=2)', 'Смотри сайт'],
            'nesting' => ['**жирный *и курсив***', '<b>жирный <i>и курсив</i></b>', 'жирный и курсив', 'жирный и курсив'],
            'html is escaped' => ['<script>alert(1)</script> & "q"', '&lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;q&quot;', '<script>alert(1)</script> & "q"', '<script>alert(1)</script> & "q"'],
            'unmatched markers stay' => ['2 * 3 = 6, a * b', '2 * 3 = 6, a * b', '2 * 3 = 6, a * b', '2 * 3 = 6, a * b'],
            'underscores inside words stay' => ['snake_case_name', 'snake_case_name', 'snake_case_name', 'snake_case_name'],
            'italic touching the next word' => ['_курсив_слово', '<i>курсив</i>слово', 'курсивслово', 'курсивслово'],
            'escaped marker' => ['\\*не курсив\\*', '*не курсив*', '*не курсив*', '*не курсив*'],
            'markup never crosses a line' => ["**раз\nдва**", "**раз\nдва**", "**раз\nдва**", "**раз\nдва**", ''],
            'only http links' => ['[x](javascript:alert(1))', '[x](javascript:alert(1))', '[x](javascript:alert(1))', '[x](javascript:alert(1))'],
            'link text equal to address' => ['[https://a.ru](https://a.ru)', '<a href="https://a.ru">https://a.ru</a>', 'https://a.ru', 'https://a.ru'],
            'quote in url is escaped' => ['[x](https://a.ru/"onclick="1)', '<a href="https://a.ru/&quot;onclick=&quot;1">x</a>', 'x (https://a.ru/"onclick="1)', 'x'],
        ];
    }

    #[DataProvider('formats')]
    public function testTheEditorsMarkupBecomesWhatEachNetworkNeeds(string $input, string $html, string $plain, string $visible): void
    {
        self::assertSame($html, TextFormatter::toHtml($input));
        self::assertSame($plain, TextFormatter::toPlain($input));
        self::assertSame($visible, TextFormatter::visible($input));
        self::assertSame(mb_strlen($visible), TextFormatter::visibleLength($input));
    }

    public function testThePostTitleIsTheFirstLineWithoutMarkup(): void
    {
        $at = new DateTimeImmutable('2026-01-01');
        $post = new Post(1, 'P', 1, null, PostStatus::Draft, "\n\n**Новое меню** уже [здесь](https://a.ru)\nвторая строка", [], new PostOptions(), false, null, 'UTC', null, $at, $at);
        self::assertSame('Новое меню уже здесь', $post->title());
        self::assertSame('Новое меню…', $post->title(12));
        $empty = new Post(1, 'P', 1, null, PostStatus::Draft, '', [], new PostOptions(), false, null, 'UTC', null, $at, $at);
        self::assertSame('Пустой пост', $empty->title());
    }

    public function testOptionsKeepOnlyValidValues(): void
    {
        $options = PostOptions::fromArray([
            'buttons' => [['text' => ' Купить ', 'url' => 'https://shop.example'], ['text' => '', 'url' => ''], 'junk'],
            'silent' => '1',
            'pin' => 'on',
            'disable_preview' => false,
            'delete_after_minutes' => '90',
            'first_comment' => '  #хэштег  ',
        ]);
        self::assertSame([['text' => 'Купить', 'url' => 'https://shop.example']], $options->buttons);
        self::assertTrue($options->silent);
        self::assertTrue($options->pin);
        self::assertFalse($options->disablePreview);
        self::assertSame(90, $options->deleteAfterMinutes);
        self::assertSame('#хэштег', $options->firstComment);
        self::assertSame([], $options->problems());
        self::assertEquals($options, PostOptions::fromArray($options->toArray()));

        self::assertNull(PostOptions::fromArray(['delete_after_minutes' => '-5'])->deleteAfterMinutes);
        self::assertNull(PostOptions::fromArray(['delete_after_minutes' => 'abc'])->deleteAfterMinutes);
        self::assertSame(PostOptions::MAX_DELETE_MINUTES, PostOptions::fromArray(['delete_after_minutes' => 99999999])->deleteAfterMinutes);
    }

    public function testButtonProblemsAreReportedForThePerson(): void
    {
        $bad = PostOptions::fromArray(['buttons' => [['text' => 'Ссылка', 'url' => 'javascript:alert(1)']]]);
        self::assertStringContainsString('http', $bad->problems()[0]);
        $noText = PostOptions::fromArray(['buttons' => [['text' => '', 'url' => 'https://a.ru']]]);
        self::assertStringContainsString('надпись', $noText->problems()[0]);
        $many = PostOptions::fromArray(['buttons' => array_fill(0, 4, ['text' => 'A', 'url' => 'https://a.ru'])]);
        self::assertStringContainsString('не больше', $many->problems()[0]);
    }

    private function parse(string $date, string $time, string $zone, string $now): DateTimeImmutable
    {
        return ScheduleTime::parse($date, $time, $zone, new DateTimeImmutable($now, new DateTimeZone('UTC')));
    }

    public function testWallClockTimeIsConvertedInTheWorkspaceTimeZone(): void
    {
        self::assertSame('2026-06-01 09:30:00', $this->parse('2026-06-01', '12:30', 'Europe/Moscow', '2026-05-01 00:00:00')->format('Y-m-d H:i:s'));
        self::assertSame('2026-06-01 10:30:00', $this->parse('2026-06-01', '12:30', 'Europe/Berlin', '2026-05-01 00:00:00')->format('Y-m-d H:i:s'), 'summer time: UTC+2');
        self::assertSame('2026-12-01 11:30:00', $this->parse('2026-12-01', '12:30', 'Europe/Berlin', '2026-05-01 00:00:00')->format('Y-m-d H:i:s'), 'winter time: UTC+1');
    }

    public function testTheSpringClockChangeSkipsAnHourAndThatTimeIsRefused(): void
    {
        // 2026-03-29: at 02:00 Berlin clocks jump to 03:00, so 02:30 does not exist.
        $this->expectException(PostException::class);
        $this->expectExceptionMessage('часы переводятся');
        $this->parse('2026-03-29', '02:30', 'Europe/Berlin', '2026-03-01 00:00:00');
    }

    public function testTimesAroundTheClockChangeAreExact(): void
    {
        self::assertSame('2026-03-29 00:59:00', $this->parse('2026-03-29', '01:59', 'Europe/Berlin', '2026-03-01 00:00:00')->format('Y-m-d H:i:s'));
        self::assertSame('2026-03-29 01:00:00', $this->parse('2026-03-29', '03:00', 'Europe/Berlin', '2026-03-01 00:00:00')->format('Y-m-d H:i:s'));
    }

    public function testTheAutumnHourThatHappensTwiceTakesTheFirstOccurrence(): void
    {
        // 2026-10-25: 03:00 summer time goes back to 02:00, so 02:30 happens twice; the first one is UTC+2.
        self::assertSame('2026-10-25 00:30:00', $this->parse('2026-10-25', '02:30', 'Europe/Berlin', '2026-10-01 00:00:00')->format('Y-m-d H:i:s'));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function badTimes(): array
    {
        return [
            'in the past' => ['2026-01-01', '10:00', 'уже прошло'],
            'right now is already past' => ['2026-05-01', '00:00', 'уже прошло'],
            'no such date' => ['2026-02-30', '10:00', 'не существует'],
            'no such hour' => ['2026-06-01', '25:00', 'не существует'],
            'empty date' => ['', '10:00', 'Укажите'],
            'garbage time' => ['2026-06-01', 'noon', 'Укажите'],
        ];
    }

    #[DataProvider('badTimes')]
    public function testBadTimesAreRefusedWithAHumanMessage(string $date, string $time, string $message): void
    {
        try {
            $this->parse($date, $time, 'Europe/Moscow', '2026-05-01 00:00:00');
            self::fail('expected a refusal');
        } catch (PostException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
    }

    public function testTheTimeZoneLabelIsExplicit(): void
    {
        $at = new DateTimeImmutable('2026-06-01', new DateTimeZone('UTC'));
        self::assertSame('Europe/Moscow, UTC+3', ScheduleTime::label('Europe/Moscow', $at));
        self::assertSame('Asia/Kolkata, UTC+5:30', ScheduleTime::label('Asia/Kolkata', $at));
        self::assertSame('America/New_York, UTC−4', ScheduleTime::label('America/New_York', $at));
        self::assertSame('Nowhere/City', ScheduleTime::label('Nowhere/City', $at));
    }
}
