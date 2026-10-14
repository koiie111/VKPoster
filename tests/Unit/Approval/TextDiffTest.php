<?php

declare(strict_types=1);

namespace App\Tests\Unit\Approval;

use App\Domain\Approval\TextDiff;
use PHPUnit\Framework\TestCase;

final class TextDiffTest extends TestCase
{
    public function testDiffRetainsUnchangedLinesAndEscapingIsDeferredToView(): void
    {
        $diff = TextDiff::lines("a\nb\nc", "a\n<script>\nc");
        self::assertSame(['equal','added','removed','equal'], array_column($diff, 'kind'));
        self::assertSame('<script>', $diff[1]['text']);
    }
    public function testLargeDiffIsBoundedAndDoesNotLoseText(): void
    {
        $a = str_repeat("a\n", 600);
        $b = str_repeat("b\n", 600);
        self::assertSame([['kind' => 'removed','text' => $a],['kind' => 'added','text' => $b]], TextDiff::lines($a, $b));
    }
}
