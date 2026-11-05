<?php

declare(strict_types=1);

namespace App\Domain\Post;

/** The publishing workflow selected for a post, independent of each network's format. */
enum PostKind: string
{
    case Regular = 'regular';
    case ShortVideo = 'short_video';
}
