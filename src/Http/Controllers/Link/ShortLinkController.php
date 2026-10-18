<?php

declare(strict_types=1);

namespace App\Http\Controllers\Link;

use App\Domain\Link\ShortLinks;
use App\Domain\Link\UrlGuard;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The public redirect of short links: `/s/<code>` on any host, and `/<code>` on the short domain (`SHORT_LINK_DOMAIN`) only. A code that
 * does not exist is a plain 404; a link that was switched off, or whose site is on the blocklist now, gets a 410 page and never a
 * redirect. The click is counted before the redirect, but a counting failure never stops it. The redirect is a 302 so browsers do not
 * cache it and a later switch-off takes effect at once.
 */
final class ShortLinkController
{
    public function __construct(
        private readonly ShortLinks $links,
        private readonly UrlGuard $guard,
        private readonly View $view,
        private readonly LoggerInterface $logger,
        private readonly string $shortDomain,
    ) {
    }

    public function go(Request $request, string $code): Response
    {
        return $this->follow($request, $code);
    }

    /** `/<code>` is answered only on the short domain, so that no other root page of the site can be shadowed or probed. */
    public function root(Request $request, string $code): Response
    {
        if ($this->shortDomain === '' || $request->host() !== $this->shortDomain) {
            throw new HttpException(404, 'Not found');
        }

        return $this->follow($request, $code);
    }

    private function follow(Request $request, string $code): Response
    {
        $link = $this->links->find($code) ?? throw new HttpException(404, 'Not found');
        $target = (string) $link['target_url'];
        if ($link['disabled_at'] !== null || $this->guard->isBlocked($target) || $this->guard->problem($target) !== null) {
            return $this->view->response('errors/link_disabled.twig', ['title' => 'Ссылка отключена', 'error_id' => null, 'debug' => null], 410)
                ->withHeader('Cache-Control', 'no-store')->withHeader('X-Robots-Tag', 'noindex');
        }
        try {
            $this->links->recordClick($link, $request->ip(), $request->header('user-agent') ?? '');
        } catch (Throwable $e) {
            $this->logger->warning('Short link click was not counted.', ['exception' => $e::class]);
        }

        return new Response(302, '', ['Location' => $target, 'Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex']);
    }
}
