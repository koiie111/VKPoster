<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Api\{ApiException, ApiTokens};
use App\Domain\Webhook\{Webhooks, WebhookOutbox};
use App\Domain\Billing\{Entitlements, PlanLimitException};
use App\Http\{WorkspaceRequest, FormFlash};
use App\Kernel\Http\{Request, Response};
use App\Kernel\View\View;

/** Session/CSRF workspace management UI, separate from Bearer-only resource requests. Secrets never enter flash/session. */
final class SettingsController
{
    public function __construct(private readonly ApiTokens $tokens, private readonly Webhooks $hooks, private readonly Entitlements $plans, private readonly View $view, private readonly FormFlash $flash)
    {
    }

    public function show(Request $request): Response
    {
        return $this->page($request);
    }

    public function createToken(Request $request): Response
    {
        try {
            $ctx = WorkspaceRequest::context($request);
            $expires = null;
            $date = WorkspaceRequest::text($request->body['expires_at'] ?? '');
            if ($date !== '') {
                $expires = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone($ctx->timezone));
                if ($expires === false || $expires->format('Y-m-d') !== $date) {
                    throw new ApiException(422, 'invalid_expiry', 'Проверьте срок действия.');
                }
            }
            $result = $this->tokens->create($ctx, WorkspaceRequest::text($request->body['name'] ?? ''), $this->strings($request->body['scopes'] ?? []), $expires);
            return $this->page($request, $result['secret'], 'API token');
        } catch (ApiException|PlanLimitException $e) {
            return $this->page($request, error: $e->getMessage(), status: $e instanceof ApiException ? $e->status : 403);
        }
    }

    public function createWebhook(Request $request): Response
    {
        try {
            $result = $this->hooks->create(WorkspaceRequest::context($request), WorkspaceRequest::text($request->body['url'] ?? ''), $this->strings($request->body['events'] ?? []));
            return $this->page($request, $result['secret'], 'Секрет подписи вебхука');
        } catch (ApiException|PlanLimitException $e) {
            return $this->page($request, error: $e->getMessage(), status: $e instanceof ApiException ? $e->status : 403);
        }
    }

    public function revoke(Request $request, string $id): Response
    {
        return $this->action($request, fn () => $this->tokens->revoke(WorkspaceRequest::context($request), $id));
    }

    public function toggle(Request $request, string $id): Response
    {
        return $this->action($request, fn () => $this->hooks->toggle(WorkspaceRequest::context($request), $id));
    }

    public function retry(Request $request, string $id): Response
    {
        return $this->action($request, fn () => $this->hooks->retry(WorkspaceRequest::context($request), $id));
    }

    private function action(Request $request, \Closure $operation): Response
    {
        try {
            $operation();
            $this->flash->toast('Изменения сохранены.');
            return Response::redirect('/w/' . WorkspaceRequest::context($request)->workspacePublicId . '/settings/api');
        } catch (ApiException|PlanLimitException $e) {
            return $this->page($request, error: $e->getMessage(), status: $e instanceof ApiException ? $e->status : 403);
        }
    }

    private function page(Request $request, ?string $secret = null, string $secretLabel = '', ?string $error = null, int $status = 200): Response
    {
        $ctx = WorkspaceRequest::context($request);
        $available = $this->plans->hasFeature($ctx->workspaceId, 'api');
        $hooks = [];
        $deliveries = [];
        $hookAccess = false;
        if ($available) {
            try {
                $hooks = $this->hooks->all($ctx);
                $deliveries = $this->hooks->deliveries($ctx);
                $hookAccess = true;
            } catch (ApiException) {
                // Restricted administrators may still manage tokens, but not workspace-wide event subscriptions.
            }
        }
        return $this->view->response('workspace/api/settings.twig', ['workspace' => $ctx, 'base' => '/w/' . $ctx->workspacePublicId . '/settings/api', 'available' => $available, 'tokens' => $available ? $this->tokens->all($ctx) : [], 'hooks' => $hooks, 'deliveries' => $deliveries, 'scopes' => ApiTokens::SCOPES, 'events' => ['post.published', 'post.failed', 'channel.error', 'approval.*'], 'hook_access' => $hookAccess, 'secret' => $secret, 'secret_label' => $secretLabel, 'form_error' => $error, 'form' => $request->body], $status)->withHeader('Cache-Control', 'no-store')->withHeader('Referrer-Policy', 'no-referrer');
    }

    /** @return list<string> */
    private function strings(mixed $raw): array
    {
        return is_array($raw) ? array_values(array_filter($raw, 'is_string')) : [];
    }
}
