<?php

declare(strict_types=1);

namespace App\Kernel\View;

use App\Kernel\Config;
use App\Kernel\Http\RequestContext;
use App\Kernel\Http\Response;
use App\Kernel\Http\Router;
use App\Kernel\Security\Csrf;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Twig wrapper. Autoescape is always on; `strict_variables` is on outside production so template
 * typos fail loudly in dev and tests. Request-aware helpers (`csrf_field`, `csp_nonce`, `old`,
 * `errors`, `url`, `asset`, `t`) are registered as Twig functions here.
 */
final class View
{
    private readonly Environment $twig;

    /** @var array<string, string> */
    private array $assetVersions = [];

    public function __construct(
        Config $config,
        private readonly RequestContext $context,
        private readonly Csrf $csrf,
        private readonly Router $router,
        private readonly Translator $translator,
        string $templatesDir,
        private readonly string $publicDir,
        string $cacheDir,
    ) {
        $debug = !$config->isProduction();
        $this->twig = new Environment(new FilesystemLoader($templatesDir), [
            'cache' => $config->isProduction() ? $cacheDir : false,
            'strict_variables' => $debug,
            'autoescape' => 'html',
            'auto_reload' => $debug,
        ]);
        $this->twig->addGlobal('app_name', $config->string('app.name'));
        $this->twig->addExtension($this->extension());
    }

    /**
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = []): string
    {
        return $this->twig->render($template, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function response(string $template, array $data = [], int $status = 200): Response
    {
        return Response::html($this->render($template, $data), $status);
    }

    private function extension(): AbstractExtension
    {
        $view = $this;

        return new class ($view) extends AbstractExtension {
            public function __construct(private readonly View $view)
            {
            }

            public function getFunctions(): array
            {
                return [
                    new TwigFunction('csrf_field', $this->view->csrfField(...), ['is_safe' => ['html']]),
                    new TwigFunction('csrf_meta', $this->view->csrfMeta(...), ['is_safe' => ['html']]),
                    new TwigFunction('csrf_token', $this->view->csrfToken(...)),
                    new TwigFunction('csp_nonce', $this->view->nonce(...)),
                    new TwigFunction('asset', $this->view->asset(...)),
                    new TwigFunction('url', $this->view->url(...)),
                    new TwigFunction('t', $this->view->translate(...)),
                    new TwigFunction('old', $this->view->old(...)),
                    new TwigFunction('errors', $this->view->errors(...)),
                ];
            }
        };
    }

    /**
     * @internal Twig function.
     */
    public function csrfToken(): string
    {
        $session = $this->context->session();

        return $session === null ? '' : $this->csrf->token($session);
    }

    /**
     * @internal Twig function.
     */
    public function csrfField(): string
    {
        return '<input type="hidden" name="_token" value="' . htmlspecialchars($this->csrfToken(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
    }

    /**
     * Meta tag read by the htmx glue script, which adds the token to every request header.
     *
     * @internal Twig function.
     */
    public function csrfMeta(): string
    {
        return '<meta name="csrf-token" content="' . htmlspecialchars($this->csrfToken(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
    }

    /**
     * @internal Twig function.
     */
    public function nonce(): string
    {
        return $this->context->nonce();
    }

    /**
     * URL of a file under `public/assets/` with a content-hash version, so browsers refetch on change.
     *
     * @internal Twig function.
     */
    public function asset(string $path): string
    {
        $path = ltrim($path, '/');
        if (str_contains($path, '..')) {
            return '/assets/';
        }
        if (!isset($this->assetVersions[$path])) {
            $file = $this->publicDir . '/assets/' . $path;
            $this->assetVersions[$path] = is_file($file) ? substr((string) hash_file('sha256', $file), 0, 10) : '';
        }
        $version = $this->assetVersions[$path];

        return '/assets/' . $path . ($version !== '' ? '?v=' . $version : '');
    }

    /**
     * @param array<string, scalar> $params
     * @param array<string, scalar> $query
     * @internal Twig function.
     */
    public function url(string $name, array $params = [], array $query = []): string
    {
        return $this->router->url($name, $params, $query);
    }

    /**
     * @param array<string, scalar> $params
     * @internal Twig function.
     */
    public function translate(string $key, array $params = []): string
    {
        return $this->translator->t($key, $params);
    }

    /**
     * Value the user submitted on the previous request (re-fill the form after validation errors).
     *
     * @internal Twig function.
     */
    public function old(string $key, mixed $default = ''): mixed
    {
        $old = $this->context->session()?->getFlash('_old');

        return is_array($old) ? ($old[$key] ?? $default) : $default;
    }

    /**
     * Validation messages from the previous request: all of them, or those of one field.
     *
     * @return array<array-key, mixed>
     * @internal Twig function.
     */
    public function errors(?string $field = null): array
    {
        $errors = $this->context->session()?->getFlash('_errors');
        if (!is_array($errors)) {
            return [];
        }
        if ($field === null) {
            return $errors;
        }
        $messages = $errors[$field] ?? [];

        return is_array($messages) ? $messages : [];
    }
}
