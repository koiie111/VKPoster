<?php

declare(strict_types=1);

namespace App\Tests\Unit\Automation;

use App\Domain\Automation\Crosspost\CrosspostFilter;
use App\Domain\Automation\Crosspost\CrosspostTransform;
use App\Domain\Automation\Crosspost\SourceMessage;
use App\Domain\Automation\Crosspost\TelegramText;
use App\Domain\Automation\Import\CsvWriter;
use App\Domain\Automation\Import\ImportParser;
use App\Domain\Automation\Import\ImportRow;
use App\Domain\Automation\Import\SheetReader;
use App\Domain\Automation\Rss\FeedException;
use App\Domain\Automation\Rss\FeedParser;
use App\Domain\Post\TextFormatter;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SourceMessage::class)]
#[CoversClass(TelegramText::class)]
#[CoversClass(CrosspostFilter::class)]
#[CoversClass(CrosspostTransform::class)]
#[CoversClass(FeedParser::class)]
#[CoversClass(CsvWriter::class)]
#[CoversClass(ImportParser::class)]
#[CoversClass(ImportRow::class)]
#[CoversClass(SheetReader::class)]
final class CrosspostAndImportTest extends TestCase
{
    public function testTelegramEntitiesBecomeEditorMarkupWithUtf16Offsets(): void
    {
        // "🔥" takes two UTF-16 units, so "жирный" starts at offset 3.
        $text = "🔥 жирный и курсив\nссылка тут";
        $markup = TelegramText::toMarkup($text, [
            ['type' => 'bold', 'offset' => 3, 'length' => 6],
            ['type' => 'italic', 'offset' => 12, 'length' => 6],
            ['type' => 'text_link', 'offset' => 19, 'length' => 6, 'url' => 'https://example.com/a_(b)'],
            ['type' => 'code', 'offset' => 0, 'length' => 2],
        ]);
        self::assertSame("🔥 **жирный** и _курсив_\n[ссылка](https://example.com/a_%28b%29) тут", $markup);
        // What the editor's formatter makes of it is what was meant.
        self::assertSame('🔥 <b>жирный</b> и <i>курсив</i>' . "\n" . '<a href="https://example.com/a_%28b%29">ссылка</a> тут', TextFormatter::toHtml($markup));
    }

    public function testMarkersInPlainTextAreEscapedAndMultilineEntitiesAreCutPerLine(): void
    {
        self::assertSame('2 \* 3 \_ x \[a\]', TelegramText::toMarkup('2 * 3 _ x [a]', []));
        $markup = TelegramText::toMarkup("первая\nвторая", [['type' => 'bold', 'offset' => 0, 'length' => 13]]);
        self::assertSame("**первая**\n**вторая**", $markup);
        self::assertSame('x', TelegramText::toMarkup('x', [['type' => 'bold', 'offset' => 5, 'length' => 2], ['type' => 'text_link', 'offset' => 0, 'length' => 1, 'url' => 'javascript:alert(1)']]));
    }

    public function testMessagesAreReadFromBotApiPayloads(): void
    {
        $photo = SourceMessage::fromTelegram(['message_id' => 7, 'chat' => ['id' => -100], 'caption' => 'Подпись #новости', 'photo' => [['file_id' => 'small', 'file_size' => 10], ['file_id' => 'big', 'file_size' => 900]], 'media_group_id' => 'g1', 'forward_origin' => ['type' => 'channel']]);
        self::assertNotNull($photo);
        self::assertSame('big', $photo->media[0]['file_id']);
        self::assertSame('g1', $photo->groupId);
        self::assertTrue($photo->forwarded);
        self::assertFalse($photo->unsupported);
        self::assertEquals($photo, SourceMessage::fromArray($photo->toArray()));

        self::assertTrue((SourceMessage::fromTelegram(['message_id' => 8, 'chat' => ['id' => -100], 'sticker' => ['file_id' => 'x']]) ?? self::fail('a sticker is still a message'))->unsupported);
        self::assertNull(SourceMessage::fromTelegram(['chat' => ['id' => -100]]));
        self::assertNull(SourceMessage::fromArray(['text' => 'no ids']));
    }

    public function testFilterByHashtagsAndForwardedPosts(): void
    {
        $post = static fn (string $text, bool $forwarded = false): SourceMessage => new SourceMessage(-1, 1, null, $text, [], [], $forwarded);
        $filter = new CrosspostFilter(['новости', 'акции'], ['реклама'], true);
        self::assertNull($filter->reason($post('Скидки #Акции'), 'Скидки #Акции'));
        self::assertNotNull($filter->reason($post('Без тегов'), 'Без тегов'));
        self::assertNotNull($filter->reason($post('#новости #реклама'), '#новости #реклама'));
        self::assertNotNull($filter->reason($post('#новости', true), '#новости'), 'forwarded posts are skipped');
        self::assertNull((new CrosspostFilter([], [], false))->reason($post('anything', true), 'anything'));
        self::assertSame(['новости', 'акции', 'a_1'], CrosspostFilter::tags("#Новости, акции\n#a_1 акции"));
        self::assertSame([], CrosspostFilter::tags('#bad-tag <b>'));
        self::assertSame(['тест', 'x1'], CrosspostFilter::hashtagsOf('текст #Тест и #x1, не#тег'));
        self::assertEquals($filter, CrosspostFilter::fromArray($filter->toArray()));
    }

    public function testTransformsCutSignatureReplaceLinksAndAddATail(): void
    {
        $transform = CrosspostTransform::fromArray([
            'signatures' => "@mychannel\nПодписаться на канал",
            'links' => [['from' => 'https://t.me/mychannel', 'to' => 'https://example.com/shop'], ['from' => '', 'to' => 'x']],
            'tail' => 'Читайте нас: example.com',
        ]);
        $result = $transform->apply("Новость дня [тут](https://t.me/mychannel/5)\n\n📢 Подписаться на канал @mychannel\nещё строка @MyChannel");
        self::assertSame("Новость дня [тут](https://example.com/shop/5)\n\nещё строка\n\nЧитайте нас: example.com", $result);
        self::assertSame('Только хвост', (new CrosspostTransform([], [], 'Только хвост'))->apply(''));
        self::assertSame('Как есть', (new CrosspostTransform())->apply("Как есть\n"));
        self::assertEquals($transform, CrosspostTransform::fromArray($transform->toArray()));
    }

    public function testRssAndAtomFeedsAreParsed(): void
    {
        $rss = '<?xml version="1.0"?><rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:media="http://search.yahoo.com/mrss/"><channel><title>Blog</title>'
            . '<item><title>Первый</title><link>https://blog.example/1</link><guid>g-1</guid><description><![CDATA[<p>Привет, <b>мир</b>&nbsp;!</p><p>Вторая</p>]]></description><enclosure url="https://blog.example/a.jpg" type="image/jpeg"/></item>'
            . '<item><title>Второй</title><link>https://blog.example/2</link><content:encoded><![CDATA[<img src="https://blog.example/b.png?x=1&amp;y=2"> текст]]></content:encoded></item>'
            . '<item><title>Опасный</title><link>javascript:alert(1)</link><media:content url="ftp://x/y.jpg" type="image/jpeg"/></item>'
            . '<item></item></channel></rss>';
        $items = FeedParser::parse($rss);
        self::assertCount(3, $items);
        self::assertSame('g-1', $items[0]->guid);
        self::assertSame("Привет, мир !\nВторая", $items[0]->summary);
        self::assertSame('https://blog.example/a.jpg', $items[0]->imageUrl);
        self::assertSame('https://blog.example/2', $items[1]->guid, 'without a guid the link identifies the entry');
        self::assertSame('https://blog.example/b.png?x=1&y=2', $items[1]->imageUrl);
        self::assertSame('', $items[2]->link, 'only http(s) links survive');
        self::assertNull($items[2]->imageUrl);
        self::assertSame(hash('sha256', 'g-1'), $items[0]->key());

        $atom = '<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"><entry><id>tag:x,1</id><title>Запись</title><link rel="alternate" href="https://a.example/p"/><link rel="enclosure" type="image/png" href="https://a.example/i.png"/><summary>Кратко</summary></entry></feed>';
        $entry = FeedParser::parse($atom)[0];
        self::assertSame(['tag:x,1', 'Запись', 'https://a.example/p', 'Кратко', 'https://a.example/i.png'], [$entry->guid, $entry->title, $entry->link, $entry->summary, $entry->imageUrl]);
    }

    public function testHostileOrBrokenFeedsAreRefused(): void
    {
        foreach (['', '<html><body>nope</body></html>', 'not xml at all', '<?xml version="1.0"?><!DOCTYPE rss [<!ENTITY x SYSTEM "file:///etc/passwd">]><rss><channel><item><title>&x;</title></item></channel></rss>'] as $body) {
            try {
                FeedParser::parse($body);
                self::fail('must refuse: ' . substr($body, 0, 30));
            } catch (FeedException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testCsvCellsThatLookLikeFormulasStayText(): void
    {
        $csv = CsvWriter::render([['Дата', 'Текст'], ['2026-10-14', "=cmd|' /C calc'!A0"], ['x', '+1+1'], ['y', '-2'], ['z', '@SUM(1)'], ['w', "\tTab"], ['ok', 'Обычный; текст']]);
        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
        self::assertStringContainsString("\"'=cmd|' /C calc'!A0\"", $csv);
        self::assertStringContainsString("'+1+1", $csv);
        self::assertStringContainsString("'-2", $csv);
        self::assertStringContainsString("'@SUM(1)", $csv);
        self::assertStringNotContainsString(';=cmd', $csv);
        self::assertStringContainsString('"Обычный; текст"', $csv);
        self::assertSame("'=1", CsvWriter::cell('=1'));
        self::assertSame('1=1', CsvWriter::cell('1=1'));
    }

    public function testImportRowsAreCheckedLineByLine(): void
    {
        $channels = ['мой канал' => ['id' => 'CH1', 'names' => ['Мой канал']], 'ch1' => ['id' => 'CH1', 'names' => ['Мой канал']], 'второй' => ['id' => 'CH2', 'names' => ['Второй']]];
        $now = new DateTimeImmutable('2026-10-14 09:00:00', new \DateTimeZone('UTC'));
        $rows = [
            ['Дата', 'Время', 'Каналы', 'Текст', 'Медиа'],
            ['15.10.2026', '10.30', 'Мой канал; Второй', 'Привет', 'https://example.com/a.jpg https://example.com/b.jpg'],
            ['2026-10-16', '9:05', 'ch1', "=cmd|' /C calc'!A0", ''],
            ['2026-10-14', '10:00', 'Мой канал', 'В прошлом (по Москве уже 12:00)', ''],
            ['32.13.2026', '25:99', 'Нет такого', '', 'ftp://x/y'],
            ['', '', 'Мой канал', 'Черновик без даты', ''],
        ];
        $result = ImportParser::parse($rows, $channels, true, $now, 'Europe/Moscow');
        self::assertCount(5, $result);
        self::assertTrue($result[0]->ok());
        self::assertSame(['CH1', 'CH2'], $result[0]->channelIds);
        self::assertSame(['2026-10-15', '10:30'], [$result[0]->date, $result[0]->time]);
        self::assertCount(2, $result[0]->media);
        self::assertTrue($result[1]->ok(), 'a formula-looking text is just text');
        self::assertSame("=cmd|' /C calc'!A0", $result[1]->text);
        self::assertFalse($result[2]->ok());
        self::assertSame(4, $result[2]->line);
        self::assertGreaterThanOrEqual(4, count($result[3]->errors));
        self::assertFalse($result[4]->ok(), 'scheduling needs a date');
        // As drafts a missing date is fine and a past date is not an error.
        $drafts = ImportParser::parse($rows, $channels, false, $now, 'Europe/Moscow');
        self::assertTrue($drafts[2]->ok());
        self::assertTrue($drafts[4]->ok());
        self::assertEquals($result[0], ImportRow::fromArray($result[0]->toArray()));
    }

    public function testImportNeedsTheRightColumnsAndReadsCsvInAnyDialect(): void
    {
        $this->expectException(\App\Domain\Automation\Import\ImportException::class);
        ImportParser::parse([['a', 'b'], ['1', '2']], [], true, new DateTimeImmutable('now'), 'UTC');
    }

    public function testCsvFilesAreReadWithSemicolonCommaBomAndWindows1251(): void
    {
        $dir = sys_get_temp_dir();
        $path = tempnam($dir, 'imp');
        self::assertIsString($path);
        try {
            file_put_contents($path, "\xEF\xBB\xBFДата;Время;Каналы;Текст\r\n14.10.2026;10:00;A;\"Строка; с точкой\"\r\n");
            self::assertSame([['Дата', 'Время', 'Каналы', 'Текст'], ['14.10.2026', '10:00', 'A', 'Строка; с точкой']], SheetReader::read($path, 'x.csv'));
            file_put_contents($path, "date,time,channels,text\n2026-10-14,10:00,A,Hi\n\n,,,\n");
            self::assertCount(2, SheetReader::read($path, 'x.csv'));
            file_put_contents($path, mb_convert_encoding("Дата;Каналы;Текст\n14.10.2026;Канал;Привет\n", 'Windows-1251', 'UTF-8'));
            self::assertSame('Привет', SheetReader::read($path, 'x.csv')[1][2]);
            file_put_contents($path, "Дата;Каналы;Текст\n");
            $this->expectException(\App\Domain\Automation\Import\ImportException::class);
            SheetReader::read($path, 'x.csv');
        } finally {
            @unlink($path);
        }
    }
}
