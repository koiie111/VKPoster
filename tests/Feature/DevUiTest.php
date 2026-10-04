<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Http\Controllers\Dev\DevUiController;
use App\Tests\Support\HttpTestCase;
use App\Tests\Support\TestEnv;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The design-system showcase and the clickable prototypes render in every state, use the shared
 * layouts, and exist only outside production.
 */
#[CoversClass(DevUiController::class)]
final class DevUiTest extends HttpTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function pages(): iterable
    {
        foreach ([
            '/dev/ui',
            '/dev/proto/onboarding?step=1', '/dev/proto/onboarding?step=2', '/dev/proto/onboarding?step=3', '/dev/proto/onboarding?step=4',
            '/dev/proto/onboarding?step=99',
            '/dev/proto/channels', '/dev/proto/channels?state=empty', '/dev/proto/channels?state=loading', '/dev/proto/channels?connected=1',
            '/dev/proto/editor', '/dev/proto/editor?error=1',
            '/dev/proto/calendar', '/dev/proto/calendar?view=week', '/dev/proto/calendar?view=list', '/dev/proto/calendar?state=empty',
            '/dev/proto/dashboard',
            '/dev/layouts/auth', '/dev/layouts/landing', '/dev/layouts/admin',
        ] as $path) {
            yield $path => [$path];
        }
    }

    #[DataProvider('pages')]
    public function testPageRendersWithDesignSystemShell(string $path): void
    {
        $response = $this->get($path);

        self::assertSame(200, $response->status, $path);
        self::assertStringContainsString('<h1', $response->body);
        self::assertStringContainsString('id="main"', $response->body, 'skip link target is missing');
        self::assertMatchesRegularExpression('#<link rel="stylesheet" href="/assets/build/app\.[0-9a-f]{10}\.css">#', $response->body, 'built CSS is not linked: run `make css`');
        self::assertStringContainsString('/assets/js/theme.js', $response->body);
        self::assertStringNotContainsString('style="', $response->body, 'inline styles are blocked by CSP');
        self::assertStringNotContainsString('onclick=', $response->body);
    }

    public function testShowcaseCoversEveryStatusAndPlatform(): void
    {
        $body = $this->get('/dev/ui')->body;

        foreach (['Черновик', 'Запланирован', 'Опубликован', 'Не удалось', 'Нужно переподключить', 'ВКонтакте', 'Telegram', 'MAX', 'Instagram'] as $label) {
            self::assertStringContainsString($label, $body);
        }
    }

    public function testEditorErrorStateShowsFieldErrorsNextToFields(): void
    {
        $body = $this->get('/dev/proto/editor?error=1')->body;

        self::assertStringContainsString('aria-invalid="true"', $body);
        self::assertStringContainsString('Это время уже прошло', $body);
        self::assertStringContainsString('role="alert"', $body);
    }

    public function testDevPagesAreNotFoundInProduction(): void
    {
        $app = TestEnv::app(['APP_ENV' => 'production', 'APP_DEBUG' => '0']);

        $response = $app->handle(\App\Kernel\Http\Request::create('GET', '/dev/ui', headers: ['Host' => 'localhost'], server: ['REMOTE_ADDR' => '203.0.113.10']));

        self::assertSame(404, $response->status);
    }

    public function testEveryIconNamedInTemplatesExistsInTheSprite(): void
    {
        $base = TestEnv::basePath();
        $sprite = (string) file_get_contents($base . '/public/assets/icons/sprite.svg');
        preg_match_all('/<symbol id="i-([a-z0-9-]+)"/', $sprite, $m);
        $known = array_flip($m[1]);

        $missing = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base . '/templates', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'twig') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            preg_match_all("/(?:icon\\(|icon\\s*[:=]\\s*)'([a-z0-9-]+)'/", $source, $used);
            foreach ($used[1] as $name) {
                if (!isset($known[$name])) {
                    $missing[$name] = $file->getFilename();
                }
            }
        }

        self::assertSame([], $missing, 'icons missing from public/assets/icons/sprite.svg');
    }
}
