<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Api\{ApiException, ApiTokens, Idempotency, ApiResources};
use App\Domain\Billing\{Entitlements, PlanLimitException};
use App\Domain\Post\PostException;
use App\Domain\Media\MediaException;
use App\Kernel\Config;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\{Request, Response};
use App\Kernel\Middleware\MiddlewareInterface;
use App\Kernel\Security\RateLimiter;
use App\Support\Clock;
use Closure;
use Psr\Log\LoggerInterface;

/** Stateless public API boundary: Bearer only, fail-closed capacity gate, safe errors and durable POST results. */
final class PublicApi implements MiddlewareInterface
{
    public function __construct(private readonly \App\Domain\Settings\SiteSettings $site, private readonly ApiResources $resources, private readonly ApiTokens $tokens, private readonly Idempotency $idempotency, private readonly RateLimiter $limiter, private readonly Entitlements $plans, private readonly Config $config, private readonly Clock $clock, private readonly LoggerInterface $logger)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $headers = [];
        try {
            if ($this->site->maintenanceOn()) {
                $headers['Retry-After'] = '600';
                throw new ApiException(503, 'maintenance', 'API временно недоступен.');
            }
            $auth = $request->header('Authorization') ?? '';
            if (preg_match('/^Bearer (vkp_[a-f0-9]{64})$/D', $auth, $match) !== 1) {
                throw new ApiException(401, 'unauthorized', 'Требуется действующий Bearer token.');
            }
            $identity = $this->tokens->authenticate($match[1]);
            $ctx = $identity->context;
            $plan = $this->plans->plan($ctx->workspaceId);
            $configured = array_key_exists('api_requests_per_minute', $plan->limits)
                ? $this->plans->limit($ctx->workspaceId, 'api_requests_per_minute')
                : $this->config->int('api.rates.' . $plan->code, 60);
            $limit = max(1, min(3000, $configured ?? 3000));
            try {
                $gate = $this->limiter->attempt('public-api:' . $identity->tokenId, $limit, 60, failClosed: true);
            } catch (\Throwable) {
                throw new ApiException(503, 'temporarily_unavailable', 'API временно недоступен.');
            }
            $headers = ['X-RateLimit-Limit' => (string) $limit, 'X-RateLimit-Remaining' => (string) $gate->remaining, 'X-RateLimit-Reset' => (string) ($this->clock->now()->getTimestamp() + max(1, $gate->retryAfter > 0 ? $gate->retryAfter : 60))];
            if (!$gate->allowed) {
                $headers['Retry-After'] = (string) max(1, $gate->retryAfter);
                throw new ApiException(429, 'rate_limited', 'Слишком много запросов.');
            }
            if (strlen($request->rawBody) > 2 * 1024 * 1024) {
                throw new ApiException(422, 'request_too_large', 'Запрос слишком большой.');
            }
            if ($request->rawBody !== '' && str_contains($request->header('Content-Type') ?? '', 'application/json')) {
                try {
                    $value = json_decode($request->rawBody, false, 32, JSON_THROW_ON_ERROR);
                    if (!$value instanceof \stdClass) {
                        throw new \RuntimeException();
                    }
                } catch (\Throwable) {
                    throw new ApiException(400, 'invalid_json', 'Ожидается JSON object.');
                }
            }
            $request = $request->withAttribute('api', $identity)->withAttribute('workspace', $ctx);
            $response = in_array($request->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)
                ? $this->idempotency->run($identity, $request, fn (): Response => $next($request), fn (Response $saved) => $this->resources->authorizeReplay($identity, $request, $saved), $request->path === '/api/v1/media')
                : $next($request);
        } catch (ApiException $e) {
            $response = $this->error($e->status, $e->errorCode, $e->getMessage());
        } catch (PlanLimitException) {
            $response = $this->error(403, 'plan_limit', 'Операция недоступна на текущем тарифе или превышен лимит.');
        } catch (PostException $e) {
            $response = $this->error($e->forbidden ? 403 : ($e->retryable ? 503 : 422), $e->planLimit ? 'plan_limit' : 'post_refused', $e->getMessage());
        } catch (MediaException $e) {
            $response = $this->error(422, 'invalid_media', $e->getMessage());
        } catch (HttpException $e) {
            $response = $this->error($e->status, $e->status === 404 ? 'not_found' : 'request_refused', 'Запрос отклонён.');
        } catch (\Throwable $e) {
            $this->logger->error('api.request_failed', ['type' => $e::class]);
            $response = $this->error(500, 'internal_error', 'Не удалось выполнить запрос.');
        }
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        return $response;
    }

    private function error(int $status, string $code, string $message): Response
    {
        return Response::json(['error' => ['code' => $code, 'message' => $message, 'details' => null]], $status);
    }
}
