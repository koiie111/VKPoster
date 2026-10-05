<?php

declare(strict_types=1);

namespace App\Kernel;

use App\Http\Middleware\RememberLogin;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\Maintenance;
use App\Http\Middleware\StartSession;
use App\Http\Middleware\VerifyCsrf;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\RequestContext;
use App\Kernel\Http\Response;
use App\Kernel\Http\Route;
use App\Kernel\Http\Router;
use App\Kernel\Middleware\ErrorHandler;
use App\Kernel\Middleware\Pipeline;
use JsonException;
use RuntimeException;

/**
 * Wires the container, routes and the global middleware pipeline, and turns a `Request` into a `Response`.
 *
 * Global middleware order (outermost first): SecurityHeaders → ErrorHandler → StartSession → RememberLogin → Maintenance → VerifyCsrf,
 * then the matched route's own middleware, then the controller. SecurityHeaders wraps ErrorHandler on
 * purpose: error pages (404, 419, 500) must carry the same CSP and headers as normal pages.
 */
final class Application
{
    private const GLOBAL_MIDDLEWARE = [SecurityHeaders::class, ErrorHandler::class, StartSession::class, RememberLogin::class, Maintenance::class, VerifyCsrf::class];

    private function __construct(
        private readonly Container $container,
        private readonly Config $config,
        private readonly Router $router,
        private readonly RequestContext $context,
    ) {
    }

    /**
     * Build the application from `$basePath/config`. Fails fast on missing or unsafe configuration.
     *
     * @throws Exception\ConfigException
     */
    public static function create(string $basePath, ?Env $env = null): self
    {
        $basePath = rtrim($basePath, '/');
        $config = Config::load($basePath . '/config', $env ?? Env::fromProcess());
        $container = new Container();
        $container->instance(Config::class, $config);
        $router = new Router();
        $container->instance(Router::class, $router);
        $context = new RequestContext();
        $container->instance(RequestContext::class, $context);

        $services = require $basePath . '/config/services.php';
        $services($container, $basePath);
        $routes = require $basePath . '/config/routes.php';
        $routes($router);
        $router->indexNames();

        return new self($container, $config, $router, $context);
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function config(): Config
    {
        return $this->config;
    }

    /**
     * Handle one request. Exceptions are converted into error responses by `ErrorHandler`.
     */
    public function handle(Request $request): Response
    {
        $this->context->begin($request);
        $allowed = [];
        $match = $this->router->match($request->method, $request->path, $allowed);
        $request = $request->withAttribute('route', $match['route'] ?? null);

        $pipeline = new Pipeline($this->container);
        $core = function (Request $request) use ($match, $allowed): Response {
            if ($match === null) {
                throw $allowed !== []
                    ? new HttpException(405, 'Method not allowed', ['Allow' => implode(', ', $allowed)])
                    : new HttpException(404, 'Not found');
            }
            $request = $request->withAttribute('route_params', $match['params']);

            return (new Pipeline($this->container))->run(
                $match['route']->middleware,
                $request,
                fn (Request $r): Response => $this->invoke($match['route'], $match['params'], $r),
            );
        };

        return $pipeline->run(self::GLOBAL_MIDDLEWARE, $request, $core);
    }

    /**
     * Front-controller entry point for php-fpm.
     *
     * @codeCoverageIgnore
     */
    public function run(): void
    {
        $request = Request::fromGlobals(array_values(array_filter($this->config->array('app.trusted_proxies'), 'is_string')));
        $this->handle($request)->send($request->method === 'HEAD');
    }

    /**
     * Call the controller. Parameters are resolved by name (`$request`, route params as strings) or
     * autowired by type; a controller may return a Response, a string (HTML) or an array (JSON).
     *
     * @param array<string, string> $params
     * @throws JsonException
     */
    private function invoke(Route $route, array $params, Request $request): Response
    {
        $result = $this->container->call($route->handler, ['request' => $request] + $params);
        if ($result instanceof Response) {
            return $result;
        }
        if (is_string($result)) {
            return Response::html($result);
        }
        if (is_array($result)) {
            return Response::json($result);
        }

        throw new RuntimeException(sprintf('Controller %s::%s returned an unsupported value.', $route->handler[0], $route->handler[1]));
    }
}
