<?php

declare(strict_types=1);

namespace App\Kernel\Middleware;

use App\Kernel\Config;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;
use Closure;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Ulid;
use Throwable;

/**
 * Turns exceptions into responses (runs just inside `SecurityHeaders`, so error pages get the security headers).
 *
 * `HttpException` → its status page (JSON for `/api/*` and `Accept: application/json`).
 * Anything else → 500 with an error id that is also written to the log together with the exception;
 * stack traces are shown only when `APP_DEBUG` is on (never possible in production).
 */
final class ErrorHandler implements MiddlewareInterface
{
    private const PAGES = [403 => 'errors/403.twig', 404 => 'errors/404.twig', 419 => 'errors/419.twig', 429 => 'errors/429.twig', 500 => 'errors/500.twig'];

    private const TITLES = [
        400 => 'Некорректный запрос',
        401 => 'Нужно войти',
        403 => 'Нет доступа',
        404 => 'Страница не найдена',
        405 => 'Так сюда обращаться нельзя',
        419 => 'Страница устарела',
        429 => 'Слишком много запросов',
        500 => 'Что-то пошло не так',
    ];

    public function __construct(
        private readonly View $view,
        private readonly LoggerInterface $logger,
        private readonly Config $config,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        try {
            return $next($request);
        } catch (HttpException $e) {
            return $this->render($request, $e->status, null, $e->headers);
        } catch (Throwable $e) {
            $errorId = (string) new Ulid();
            $this->logger->error('http.unhandled_exception', [
                'error_id' => $errorId,
                'exception' => $e,
                'method' => $request->method,
                'path' => $request->path,
            ]);

            return $this->render($request, 500, $errorId, [], $this->config->bool('app.debug') ? $e : null);
        }
    }

    /**
     * @param array<string, string> $headers
     */
    private function render(Request $request, int $status, ?string $errorId, array $headers, ?Throwable $debug = null): Response
    {
        $title = self::TITLES[$status] ?? 'Ошибка';
        if ($request->wantsJson()) {
            $payload = ['error' => ['status' => $status, 'message' => $title]];
            if ($errorId !== null) {
                $payload['error']['id'] = $errorId;
            }
            $response = Response::json($payload, $status);
        } else {
            try {
                $response = $this->view->response(
                    self::PAGES[$status] ?? 'errors/error.twig',
                    [
                        'status' => $status,
                        'title' => $title,
                        'error_id' => $errorId,
                        'debug' => $debug === null ? null : $debug::class . ': ' . $debug->getMessage() . "\n" . $debug->getTraceAsString(),
                    ],
                    $status,
                );
            } catch (Throwable $e) {
                $this->logger->error('http.error_page_failed', ['exception' => $e]);
                $response = Response::text($title . ($errorId !== null ? ' (' . $errorId . ')' : ''), $status);
            }
        }
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
