<?php

declare(strict_types=1);

namespace App\Tests\Feature\Admin;

use App\Domain\Admin\StaffRole;
use App\Domain\Design\ThemeColors;
use App\Domain\Settings\Settings;
use App\Http\Controllers\Admin\DesignController;
use App\Http\Controllers\Site\ThemeController;
use App\Tests\Support\AdminTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The owner edits the colours of the site by hand and can put the standard ones back.
 */
#[CoversClass(ThemeColors::class)]
#[CoversClass(DesignController::class)]
#[CoversClass(ThemeController::class)]
final class DesignTest extends AdminTestCase
{
    private function resetSetting(): void
    {
        $this->app->container()->get(Settings::class)->forget(ThemeColors::SETTING);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetSetting();
    }

    protected function tearDown(): void
    {
        $this->resetSetting();
        parent::tearDown();
    }

    public function testNothingIsChangedByDefault(): void
    {
        $this->useBrowser();
        $css = $this->get('/theme.css');
        self::assertSame(200, $css->status);
        self::assertSame('', $css->body);
        self::assertStringNotContainsString('/theme.css', $this->get('/')->body, 'no link while nothing is customised');
    }

    public function testOnlyContentAndOwnerRolesOpenThePage(): void
    {
        foreach ([StaffRole::Finance, StaffRole::Support, StaffRole::Analyst] as $role) {
            $this->staff($role);
            self::assertSame(403, $this->get('/admin/design')->status, $role->value);
            self::assertSame(403, $this->post('/admin/design', [])->status, $role->value);
            self::assertSame(403, $this->post('/admin/design/reset', [])->status, $role->value);
        }
        $this->staff(StaffRole::Content);
        $page = $this->get('/admin/design');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('name="light[bg]"', $page->body);
        self::assertStringContainsString('name="dark[p]"', $page->body);
        self::assertStringContainsString('#f7f8fc', $page->body, 'the standard page background');
    }

    public function testSavingChangesTheSiteAndResetBringsTheStandardBack(): void
    {
        $this->staff(StaffRole::Content);
        $save = $this->post('/admin/design', ['light' => ['p' => '#ff0000', 'bg' => '#f7f8fc'], 'dark' => ['p' => '#00ff00', 'fg' => 'nonsense']]);
        self::assertSame('/admin/design', $save->header('Location'));

        $css = $this->get('/theme.css')->body;
        self::assertStringContainsString(':root { --p: 255 0 0; }', $css);
        self::assertStringContainsString("--p: 0 255 0;", $css);
        self::assertStringContainsString("@media (prefers-color-scheme: dark) { :root:not([data-theme='light'])", $css);
        self::assertStringNotContainsString('--bg', $css, 'a colour equal to the standard is not stored');
        self::assertStringNotContainsString('--fg', $css, 'an invalid colour is ignored');
        self::assertMatchesRegularExpression('#<link rel="stylesheet" href="/theme\.css\?v=[0-9a-f]{10}">#', $this->get('/admin/design')->body);
        self::assertStringContainsString('value="#ff0000"', $this->get('/admin/design')->body);
        self::assertContains('admin.design_saved', $this->adminActions());

        $reset = $this->post('/admin/design/reset', ['scope' => 'light']);
        self::assertSame('/admin/design', $reset->header('Location'));
        $css = $this->get('/theme.css')->body;
        self::assertStringNotContainsString('255 0 0', $css);
        self::assertStringContainsString('0 255 0', $css, 'the dark theme is kept');

        $this->post('/admin/design/reset', ['scope' => 'all']);
        self::assertSame('', $this->get('/theme.css')->body);
        self::assertSame(['light' => [], 'dark' => []], $this->app->container()->get(ThemeColors::class)->overrides());
        self::assertContains('admin.design_reset', $this->adminActions());
    }

    public function testSavingTheStandardColoursEverywhereClearsTheSetting(): void
    {
        $theme = $this->app->container()->get(ThemeColors::class);
        $theme->save(['p' => '#ff0000'], [], null);
        self::assertTrue($theme->isCustomised());
        $light = [];
        foreach (ThemeColors::LIGHT as $token => $triplet) {
            $light[$token] = ThemeColors::tripletToHex($triplet);
        }
        $result = $theme->save($light, [], null);
        self::assertSame(['light.p'], $result['changed']);
        self::assertFalse($theme->isCustomised());
        self::assertSame('', $theme->version());
    }

    public function testContrastIsReportedForBadCombinations(): void
    {
        $theme = $this->app->container()->get(ThemeColors::class);
        $theme->save(['fg' => '#f7f8fc'], [], null);
        $bad = array_filter($theme->contrast('light'), static fn (array $r): bool => !$r['ok']);
        self::assertNotEmpty($bad);
        $this->staff(StaffRole::Content);
        self::assertStringContainsString('Плохо читается', $this->get('/admin/design')->body);
        self::assertSame([], array_filter($theme->contrast('dark'), static fn (array $r): bool => !$r['ok']));
    }

    public function testMaliciousStoredValuesNeverReachTheStylesheet(): void
    {
        $this->app->container()->get(Settings::class)->set(ThemeColors::SETTING, ['light' => ['p' => '1 2 3; } body { display: none', 'bg' => '1 2 3'], 'dark' => ['nope' => '1 2 3']], null);
        $css = $this->app->container()->get(ThemeColors::class)->css();
        self::assertSame(":root { --bg: 1 2 3; }\n", $css);
    }
}
