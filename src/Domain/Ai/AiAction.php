<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/**
 * What the assistant can do in the editor. Each action has a price in credits, the instruction the model gets (trusted, written
 * here) and whether the person must say what they want (`needsBrief`) or the current post text is enough (`needsText`).
 */
enum AiAction: string
{
    case Generate = 'generate';
    case Rewrite = 'rewrite';
    case Shorten = 'shorten';
    case Titles = 'titles';
    case Hashtags = 'hashtags';
    case Spellcheck = 'spellcheck';

    public function label(): string
    {
        return match ($this) {
            self::Generate => 'Написать пост по теме',
            self::Rewrite => 'Переписать под соцсеть',
            self::Shorten => 'Сократить до лимита',
            self::Titles => 'Придумать заголовки',
            self::Hashtags => 'Подобрать хэштеги',
            self::Spellcheck => 'Проверить орфографию',
        };
    }

    /** Credits taken for one successful answer (a longer text costs more). */
    public function cost(): int
    {
        return match ($this) {
            self::Generate => 3,
            self::Rewrite => 2,
            default => 1,
        };
    }

    /** The topic or theses are required: there may be no post text yet. */
    public function needsBrief(): bool
    {
        return $this === self::Generate;
    }

    /** The current post text is required. */
    public function needsText(): bool
    {
        return $this !== self::Generate;
    }

    public function instruction(): string
    {
        return match ($this) {
            self::Generate => 'Write one social media post on the topic or theses in the material. Make it ready to publish: a hook, the substance, a call to action when it fits.',
            self::Rewrite => 'Rewrite the post in the material for the target platform: keep the meaning, facts and links, adapt length, tone, line breaks and emoji to the platform.',
            self::Shorten => 'Shorten the post in the material so it fits the length limit. Keep the main idea, facts, links and the call to action; cut filler.',
            self::Titles => 'Suggest 5 different short headlines for the post in the material, one per line, without numbering or quotes.',
            self::Hashtags => 'Suggest 5 to 10 relevant hashtags for the post in the material, in one line separated by spaces. Do not repeat the post.',
            self::Spellcheck => 'Fix spelling, punctuation and typos in the post in the material. Do not change the meaning, style, formatting or links. Return the corrected post only.',
        };
    }
}
