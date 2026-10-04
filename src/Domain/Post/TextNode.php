<?php

declare(strict_types=1);

namespace App\Domain\Post;

/**
 * A piece of parsed editor markup: plain text (`t`), bold (`b`), italic (`i`), strikethrough (`s`) or a link (`a`, `value` is the
 * address and the children are its text).
 */
final class TextNode
{
    /**
     * @param list<TextNode> $children
     */
    public function __construct(public readonly string $kind, public readonly string $value = '', public readonly array $children = [])
    {
    }
}
