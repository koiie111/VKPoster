<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Domain\Content\Announcements;
use App\Http\WorkspaceRequest;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;

/**
 * A person closes one of the owner's notices; it stays closed for them. Always answers with a redirect back to a page of this site.
 */
final class AnnouncementController
{
    public function __construct(private readonly Announcements $announcements)
    {
    }

    public function dismiss(Request $request, string $id): Response
    {
        $this->announcements->dismiss((int) $id, WorkspaceRequest::user($request)->id);
        $back = $request->header('referer');
        $path = is_string($back) ? parse_url($back, PHP_URL_PATH) : null;

        return Response::redirect(is_string($path) && Response::isRelativeUrl($path) ? $path : '/app');
    }
}
