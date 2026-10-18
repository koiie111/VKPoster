<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ai;

use App\Domain\Ai\AiAssistant;
use App\Domain\Ai\AiCredits;
use App\Domain\Ai\AiWorkspaceSettings;
use App\Domain\Ai\BrandVoice;
use App\Domain\Audit\AuditLog;
use App\Domain\Channel\ChannelRepository;
use App\Kernel\Exception\HttpException;
use App\Http\FormFlash;
use App\Http\WorkspaceRequest;
use App\Integrations\Ai\Llm\LlmCatalog;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;

/**
 * Workspace AI settings: the brand voice that is mixed into every request, an optional own OpenAI-compatible model (the key is
 * encrypted and never shown again) and the credit balance. For owners and administrators (`workspace.settings`).
 */
final class AiSettingsController
{
    public function __construct(
        private readonly View $view,
        private readonly AiWorkspaceSettings $settings,
        private readonly AiCredits $credits,
        private readonly AiAssistant $assistant,
        private readonly AuditLog $audit,
        private readonly FormFlash $flash,
        private readonly ChannelRepository $channels,
    ) {
    }

    public function show(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $styles = [];
        foreach ($this->channels->all($context) as $channel) {
            $style = $this->settings->channelStyle($context->workspaceId, $channel->id);
            $styles[] = ['id' => $channel->publicId, 'name' => $channel->displayName(), 'platform' => $channel->platform->value, 'style' => $style, 'has_style' => !$style->isEmpty()];
        }

        return $this->view->response('workspace/ai/settings.twig', [
            'channel_styles' => $styles,
            'workspace' => $context,
            'base' => '/w/' . $context->workspacePublicId,
            'voice' => $this->settings->brandVoice($context->workspaceId),
            'own' => $this->settings->ownForForm($context->workspaceId),
            'auth_schemes' => LlmCatalog::AUTH_SCHEMES,
            'credits' => $this->credits->summary($context->workspaceId, $context->workspacePublicId),
            'connected' => $this->assistant->available($context),
        ]);
    }

    public function saveVoice(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $examples = [];
        foreach (['example_1', 'example_2', 'example_3'] as $key) {
            $examples[] = WorkspaceRequest::text($request->input($key));
        }
        $voice = BrandVoice::fromArray([
            'tone' => WorkspaceRequest::text($request->input('tone')),
            'rules' => WorkspaceRequest::text($request->input('rules')),
            'forbidden' => WorkspaceRequest::text($request->input('forbidden')),
            'examples' => $examples,
        ]);
        $this->settings->saveBrandVoice($context->workspaceId, $voice);
        $this->audit->record('ai.voice_updated', $context->userId, 'workspace', $context->workspacePublicId, [], $context->workspaceId);
        $this->flash->toast('Голос бренда сохранён: ассистент будет писать в этом стиле.');

        return Response::redirect('/w/' . $context->workspacePublicId . '/settings/ai');
    }

    /** The writing style of one channel (a public, community or account): rules, tone, forbidden words and example posts. */
    public function saveChannelStyle(Request $request, string $channelId): Response
    {
        $context = WorkspaceRequest::context($request);
        $channel = $this->channels->find($context, $channelId) ?? throw new HttpException(404, 'Not found');
        $examples = [];
        foreach (['example_1', 'example_2', 'example_3'] as $key) {
            $examples[] = WorkspaceRequest::text($request->input($key));
        }
        $style = BrandVoice::fromArray([
            'tone' => WorkspaceRequest::text($request->input('tone')),
            'rules' => WorkspaceRequest::text($request->input('rules')),
            'forbidden' => WorkspaceRequest::text($request->input('forbidden')),
            'examples' => $examples,
        ]);
        $this->settings->saveChannelStyle($context->workspaceId, $channel->id, $style);
        $this->audit->record('ai.channel_style_updated', $context->userId, 'channel', $channel->publicId, ['empty' => $style->isEmpty() ? '1' : '0'], $context->workspaceId);
        $this->flash->toast($style->isEmpty() ? 'Стиль канала «' . $channel->displayName() . '» очищен.' : 'Стиль канала «' . $channel->displayName() . '» сохранён: ассистент будет держать его в постах для этого канала.');

        return Response::redirect('/w/' . $context->workspacePublicId . '/settings/ai#ch-' . $channel->publicId);
    }

    public function saveOwn(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $input = [];
        foreach (['enabled', 'base_url', 'model', 'auth', 'api_key'] as $key) {
            $input[$key] = WorkspaceRequest::text($request->input($key));
        }
        $errors = $this->settings->saveOwn($context->workspaceId, $input);
        if ($errors !== []) {
            $this->flash->toast(implode(' ', $errors), 'error');

            return Response::redirect('/w/' . $context->workspacePublicId . '/settings/ai#own');
        }
        // The trail says that the endpoint changed, never the key or the address.
        $this->audit->record('ai.own_model_updated', $context->userId, 'workspace', $context->workspacePublicId, ['enabled' => $input['enabled'] === '1' ? '1' : '0'], $context->workspaceId);
        $this->flash->toast($input['enabled'] === '1' ? 'Своя модель включена: запросы не тратят кредиты.' : 'Своя модель выключена.');

        return Response::redirect('/w/' . $context->workspacePublicId . '/settings/ai#own');
    }
}
