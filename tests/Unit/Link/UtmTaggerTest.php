<?php

declare(strict_types=1);

namespace App\Tests\Unit\Link;

use App\Domain\Link\UtmRule;
use App\Domain\Link\UtmTagger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** UTM tags must never break an address: anchors, existing parameters and the person's own tags stay as they were. */
#[CoversClass(UtmTagger::class)]
#[CoversClass(UtmRule::class)]
final class UtmTaggerTest extends TestCase
{
    private const VARS = ['platform' => 'vk', 'channel' => 'Кофемания Центр', 'date' => '2026-10-18', 'month' => '2026-10'];

    /**
     * @param array<string, mixed> $override
     */
    private function rule(array $override = []): UtmRule
    {
        return UtmRule::fromArray($override + ['enabled' => true, 'source' => '{platform}', 'medium' => 'social', 'campaign' => '{month}']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function addresses(): iterable
    {
        yield 'plain' => ['https://example.com/', 'https://example.com/?utm_source=vk&utm_medium=social&utm_campaign=2026-10'];
        yield 'with parameters' => ['https://example.com/a?x=1&y=two', 'https://example.com/a?x=1&y=two&utm_source=vk&utm_medium=social&utm_campaign=2026-10'];
        yield 'with an anchor' => ['https://example.com/a#part-2', 'https://example.com/a?utm_source=vk&utm_medium=social&utm_campaign=2026-10#part-2'];
        yield 'parameters and anchor' => ['https://example.com/a?x=1#top', 'https://example.com/a?x=1&utm_source=vk&utm_medium=social&utm_campaign=2026-10#top'];
        yield 'own campaign wins' => ['https://example.com/?utm_campaign=sale', 'https://example.com/?utm_campaign=sale&utm_source=vk&utm_medium=social'];
        yield 'own tag in other case wins' => ['https://example.com/?UTM_SOURCE=mail', 'https://example.com/?UTM_SOURCE=mail&utm_medium=social&utm_campaign=2026-10'];
        yield 'empty query mark' => ['https://example.com/a?', 'https://example.com/a?utm_source=vk&utm_medium=social&utm_campaign=2026-10'];
    }

    #[DataProvider('addresses')]
    public function testAddressesKeepEverythingTheyHad(string $in, string $expected): void
    {
        self::assertSame($expected, (new UtmTagger())->tagUrl($in, $this->rule(), self::VARS));
    }

    public function testNothingChangesWhenTheRuleIsOffOrTheAddressIsNotHttp(): void
    {
        $tagger = new UtmTagger();
        self::assertSame('https://example.com/', $tagger->tagUrl('https://example.com/', $this->rule(['enabled' => false]), self::VARS));
        foreach (['javascript:alert(1)', 'mailto:a@b.c', 'tg://resolve?domain=x', '/relative'] as $url) {
            self::assertSame($url, $tagger->tagUrl($url, $this->rule(), self::VARS));
        }
    }

    public function testValuesAreSafeInAQueryString(): void
    {
        $url = (new UtmTagger())->tagUrl('https://example.com/', $this->rule(['source' => '{channel}', 'medium' => '', 'campaign' => 'Акция & скидки!']), self::VARS);

        self::assertSame('https://example.com/?utm_source=kofemaniya_tsentr&utm_campaign=aktsiya_skidki', $url);
    }

    public function testTextLinksAndBareAddressesAreRewrittenWithoutTouchingTheRest(): void
    {
        $seen = [];
        $text = "Смотрите [тут](https://a.example/x?y=1) и https://b.example/page. А ещё (https://c.example/z), готово!\n**https://d.example/**";
        $out = UtmTagger::mapUrls($text, static function (string $url) use (&$seen): string {
            $seen[] = $url;

            return $url . '#t';
        });

        self::assertSame(['https://a.example/x?y=1', 'https://b.example/page', 'https://c.example/z', 'https://d.example/'], $seen);
        self::assertStringContainsString('[тут](https://a.example/x?y=1#t)', $out);
        self::assertStringContainsString('https://b.example/page#t. А ещё', $out);
        self::assertStringContainsString('(https://c.example/z#t), готово!', $out);
        self::assertStringContainsString('**https://d.example/#t**', $out);
    }

    public function testRulesAreChecked(): void
    {
        self::assertSame([], $this->rule()->problems());
        self::assertNotSame([], UtmRule::fromArray(['enabled' => true, 'source' => ''])->problems(), 'a source is required while the rule is on');
        self::assertSame([], UtmRule::fromArray(['enabled' => false, 'source' => ''])->problems());
        self::assertNotSame([], $this->rule(['campaign' => '{unknown}'])->problems());
        self::assertNotSame([], $this->rule(['campaign' => '{month'])->problems());
        self::assertSame(UtmRule::MAX_TEMPLATE, mb_strlen($this->rule(['campaign' => str_repeat('a', 500)])->campaign));
    }
}
