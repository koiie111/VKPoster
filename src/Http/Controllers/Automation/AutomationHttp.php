<?php

declare(strict_types=1);

namespace App\Http\Controllers\Automation;

use App\Domain\Automation\AutomationException;
use App\Domain\Channel\Channel;
use App\Domain\Channel\ChannelRepository;
use App\Domain\Post\PostService;
use App\Domain\Workspace\WorkspaceContext;
use App\Http\FormFlash;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;

/**
 * What the pages of stage 13 have in common: answering a refusal of a domain service (403 for a missing permission, a banner for a
 * plan limit, a toast or the form's own error otherwise, with the typed values kept), and the list of channels a member may pick.
 */
final class AutomationHttp
{
    public function __construct(
        private readonly FormFlash $flash,
        private readonly ChannelRepository $channels,
        private readonly PostService $posts,
    ) {
    }

    public static function base(WorkspaceContext $context): string
    {
        return '/w/' . $context->workspacePublicId;
    }

    /**
     * Turn an exception from a service into a redirect back to `$back`. A missing permission is a 403 page, as for every other route.
     *
     * @param array<string, mixed> $input what the person typed, to fill the form again
     * @throws HttpException 403
     */
    public function refuse(WorkspaceContext $context, AutomationException $e, string $back, array $input = []): Response
    {
        if ($e->forbidden) {
            throw new HttpException(403, 'Forbidden');
        }
        if ($e->planLimit) {
            $this->flash->planLimit($e->getMessage(), $context->workspacePublicId);
        } elseif ($input !== []) {
            $this->flash->invalid($input, ['form' => $e->getMessage()]);
        } else {
            $this->flash->toast($e->getMessage(), 'error');
        }

        return Response::redirect($back);
    }

    /**
     * Channels the member may use, as plain rows for a form.
     *
     * @return list<array{id: string, name: string, platform: string, platform_label: string}>
     */
    public function channelChoices(WorkspaceContext $context): array
    {
        return array_map(static fn (Channel $c): array => ['id' => $c->publicId, 'name' => $c->displayName(), 'platform' => $c->platform->badgeKey(), 'platform_label' => $c->platform->label()], $this->channels->all($context, $this->posts->allowedChannels($context)));
    }

    /**
     * The 26-character id from the URL, or 404 for anything else.
     */
    public static function id(Request $request, string $name): string
    {
        $params = $request->attribute('route_params');
        $value = is_array($params) ? ($params[$name] ?? null) : null;
        if (!is_string($value) || preg_match('/^[0-9A-Za-z]{26}$/', $value) !== 1) {
            throw new HttpException(404, 'Not found');
        }

        return $value;
    }

    /**
     * @return list<string> ids of checked boxes of a field, valid ULIDs only
     */
    public static function ids(Request $request, string $field): array
    {
        $raw = $request->input($field);
        $ids = [];
        foreach (is_array($raw) ? $raw : [] as $value) {
            if (is_string($value) && preg_match('/^[0-9A-Za-z]{26}$/', $value) === 1) {
                $ids[strtoupper($value)] = strtoupper($value);
            }
        }

        return array_values($ids);
    }

    public static function text(Request $request, string $field, int $max = 500): string
    {
        $value = $request->input($field);

        return is_string($value) ? mb_substr(trim($value), 0, $max) : '';
    }

    public static function flag(Request $request, string $field): bool
    {
        $value = $request->input($field);

        return $value === '1' || $value === 'on' || $value === true;
    }
}
