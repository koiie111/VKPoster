<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ai;

use App\Domain\Ai\AiAction;
use App\Domain\Ai\AiAssistant;
use App\Domain\Ai\AiCredits;
use App\Domain\Ai\AiException;
use App\Domain\Channel\ChannelRepository;
use App\Http\WorkspaceRequest;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;

/**
 * The editor's assistant over JSON: `POST /w/{id}/ai/run` takes an action, the post text, an optional brief, the target platform and
 * length limit, and answers with plain text for the person to accept or discard. Nothing is saved or published here. Everyone who can
 * write drafts may use it (`ai.use`); credits and limits are enforced by `AiAssistant`.
 */
final class AiController
{
    public function __construct(private readonly AiAssistant $assistant, private readonly AiCredits $credits, private readonly ChannelRepository $channels)
    {
    }

    public function run(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $action = AiAction::tryFrom(WorkspaceRequest::text($request->input('action')));
        if ($action === null) {
            return Response::json(['ok' => false, 'message' => 'Неизвестное действие.'], 422);
        }
        $limit = WorkspaceRequest::text($request->input('limit'));
        // The channel decides the writing style; it is looked up inside this workspace, so another workspace's channel is simply ignored.
        $channelId = WorkspaceRequest::text($request->input('channel'));
        $channel = $channelId === '' ? null : $this->channels->find($context, strtoupper($channelId));
        try {
            $answer = $this->assistant->run(
                $context,
                $action,
                WorkspaceRequest::text($request->input('text')),
                WorkspaceRequest::text($request->input('brief')),
                WorkspaceRequest::text($request->input('platform')),
                ctype_digit($limit) ? (int) $limit : 0,
                $channel?->id,
            );
        } catch (AiException $e) {
            $status = match ($e->reason) {
                'no_credits' => 402,
                'rate_limited' => 429,
                'empty', 'too_long' => 422,
                'not_connected' => 503,
                default => 502,
            };

            return Response::json(['ok' => false, 'reason' => $e->reason, 'message' => $e->getMessage(), 'upgrade' => $e->upgrade ? '/w/' . $context->workspacePublicId . '/billing/plans' : null], $status);
        }
        $summary = $this->credits->summary($context->workspaceId, $context->workspacePublicId);

        return Response::json([
            'ok' => true,
            'text' => $answer->text,
            'credits' => $answer->credits,
            'over_limit' => $answer->overLimit,
            'credits_left' => $summary['left'],
            'style' => $answer->style,
            'style_channel' => $answer->style === 'channel' && $channel !== null ? $channel->displayName() : null,
        ]);
    }
}
