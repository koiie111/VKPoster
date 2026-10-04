<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Kernel\Config;
use App\Kernel\Http\RequestContext;
use App\Kernel\Http\Router;
use App\Kernel\Security\Csrf;
use App\Kernel\View\Translator;
use App\Kernel\View\View;
use App\Tests\Support\TestEnv;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Twig components escape everything a user can type (names, titles, texts, labels), in text and in attributes.
 */
#[CoversClass(View::class)]
final class ComponentsTest extends TestCase
{
    private const PAYLOAD = '<script>alert(1)</script>"\'><img src=x onerror=alert(2)>';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/components-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $base = TestEnv::basePath() . '/templates';
        symlink($base . '/components', $this->dir . '/components');
        symlink($base . '/layouts', $this->dir . '/layouts');
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/components');
        @unlink($this->dir . '/layouts');
        @unlink($this->dir . '/probe.twig');
        @rmdir($this->dir);
    }

    public function testUserTextIsEscapedInEveryComponent(): void
    {
        file_put_contents($this->dir . '/probe.twig', <<<'TWIG'
{% import 'components/forms.twig' as f %}
{% import 'components/display.twig' as d %}
{% import 'components/overlay.twig' as o %}
{% import 'components/post.twig' as p %}
{% import 'components/calendar.twig' as c %}
{% import 'components/nav.twig' as n %}
{{ f.button(x, attrs = {'data-x': x}) }}
{{ f.icon_button('plus', x) }}
{{ f.input('a', x, value = x, hint = x, error = x, placeholder = x) }}
{{ f.textarea('b', x, value = x, hint = x, error = x, max = 10) }}
{{ f.select('c', x, {(x): x}, x, hint = x, error = x) }}
{{ f.checkbox('d', x, hint = x) }}{{ f.radio('e', x, x, hint = x) }}{{ f.switch('f', x, hint = x) }}
{{ f.datetime('g', x, x, x, tz = x, tz_label = x, error = x) }}
{{ f.dropzone('h', x, hint = x) }}
{{ d.badge(x) }}{{ d.status_badge(x) }}{{ d.platform_badge(x) }}{{ d.avatar(x) }}{{ d.avatar(x, x) }}
{{ d.empty_state(x, x, action_label = x, action_href = x) }}
{{ d.progress(1, 2, x) }}{{ d.stepper([x, x], 1) }}{{ d.breadcrumbs([{label: x, href: x}, {label: x}]) }}
{{ d.alert('error', x, x) }}{{ d.tooltip('t', x) }}
{{ d.table([{label: x, sort_href: x}], empty = true, empty_title = x, empty_text = x, caption = x) }}
{{ d.pagination(2, 3, x) }}
{{ d.page_header(x, x) }}
{{ o.modal('m', x, x) }}{{ o.drawer('dr', x) }}{{ o.confirm_dialog('cf', x, x, x, x, x, true) }}
{{ o.menu_item(x, href = x, attrs = {'data-x': x}) }}
{{ o.tabs('tb', [{id: 'a', label: x}], 'a') }}
{{ p.post_card({title: x, text: x, platforms: ['vk'], status: 'failed', time: x, error: x}) }}
{% for pl in ['vk', 'tg', 'max', 'ig'] %}{{ p.preview(pl, x, x) }}{% endfor %}
{{ c.item({platform: 'vk', time: x, title: x, status: 'draft'}) }}
{{ c.week([{label: x, num: 1, items: [{platform: 'vk', time: x, title: x, status: 'draft'}]}]) }}
{{ c.list([{label: x, items: [{platform: 'tg', time: x, title: x, status: 'draft'}]}]) }}
{{ n.sidebar_nav([{id: 'a', label: x, icon: 'plus', href: x}], 'a') }}
{{ n.user_menu(x, x) }}
{{ n.workspace_switcher(x, [{name: x, href: x}]) }}
{{ o.toast_region([{text: x, kind: x}]) }}
TWIG);

        $html = $this->view()->render('probe.twig', ['x' => self::PAYLOAD]);

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringNotContainsString('onerror=alert(2)>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function testIconFunctionRejectsUnknownNamesOutsideProduction(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->view()->icon('no-such-icon');
    }

    public function testIconIsDecorativeByDefaultAndLabelledOnRequest(): void
    {
        $view = $this->view();

        self::assertStringContainsString('aria-hidden="true"', $view->icon('plus'));
        self::assertStringContainsString('role="img" aria-label="Добавить"', $view->icon('plus', 'h-4 w-4', 'Добавить'));
    }

    public function testBuiltAssetIsResolvedThroughManifestOnly(): void
    {
        $public = sys_get_temp_dir() . '/public-test-' . bin2hex(random_bytes(4));
        mkdir($public . '/assets/build', 0777, true);
        file_put_contents($public . '/assets/build/manifest.json', '{"app.css":"app.abcdef0123.css"}');
        $view = $this->view($public);
        self::assertSame('/assets/build/app.abcdef0123.css', $view->asset('app.css'));

        file_put_contents($public . '/assets/build/manifest.json', '{"app.css":"../../etc/passwd"}');
        self::assertSame('/assets/app.css', $this->view($public)->asset('app.css'), 'a manifest entry with path characters must be ignored');

        @unlink($public . '/assets/build/manifest.json');
        @rmdir($public . '/assets/build');
        @rmdir($public . '/assets');
        @rmdir($public);
    }

    private function view(?string $publicDir = null): View
    {
        $app = TestEnv::app();
        $c = $app->container();

        return new View(
            $c->get(Config::class),
            $c->get(RequestContext::class),
            $c->get(Csrf::class),
            $c->get(Router::class),
            $c->get(Translator::class),
            $this->dir,
            $publicDir ?? TestEnv::basePath() . '/public',
            sys_get_temp_dir(),
        );
    }
}
