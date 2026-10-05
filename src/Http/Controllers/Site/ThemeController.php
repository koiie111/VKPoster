<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Domain\Design\ThemeColors;
use App\Kernel\Http\Response;

/**
 * `/theme.css`: the colours the owner changed in the admin area, as CSS variables that replace the standard ones. The link carries the
 * version (`?v=hash`), so the answer can be cached for a year: a new colour means a new URL. Empty when nothing was changed.
 */
final class ThemeController
{
    public function __construct(private readonly ThemeColors $theme)
    {
    }

    public function css(): Response
    {
        return (new Response(200, $this->theme->css()))
            ->withHeader('Content-Type', 'text/css; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=31536000, immutable');
    }
}
