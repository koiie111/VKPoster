<?php

declare(strict_types=1);

namespace App\Tests\Feature\Ai;

use App\Domain\Workspace\Role;
use App\Http\Controllers\Ai\AiSettingsController;
use App\Tests\Support\BillingTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/** The workspace page of the assistant: who may open and change it, secrets never come back, the editor shows the assistant. */
#[CoversClass(AiSettingsController::class)]
final class AiSettingsPageTest extends BillingTestCase
{
    public function testOnlyOwnersAndAdminsOpenAndChangeIt(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $editor = $this->memberOf($workspace, 'editor@example.com', Role::Editor);
        $admin = $this->memberOf($workspace, 'admin@example.com', Role::Admin);
        $page = $this->base($workspace) . '/settings/ai';

        $this->useBrowser();
        self::assertSame(302, $this->get($page)->status, 'guest');
        $this->actAs($editor);
        self::assertSame(403, $this->get($page)->status);
        self::assertSame(403, $this->post($page . '/own', ['enabled' => '1'])->status);
        self::assertSame(403, $this->post($page . '/voice', ['tone' => 'x'])->status);
        $this->actAs($admin);
        self::assertSame(200, $this->get($page)->status);
        $this->actAs($owner);
        $response = $this->get($page);
        self::assertSame(200, $response->status);
        self::assertStringContainsString('Голос бренда', $response->body);
    }

    public function testTheKeyIsNeverShownAgainAndSavingIsAudited(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->actAs($owner);
        $page = $this->base($workspace) . '/settings/ai';

        $this->post($page . '/own', ['enabled' => '1', 'base_url' => 'https://llm.example.org/v1', 'model' => 'm1', 'api_key' => 'sk-HIDDEN-KEY', 'auth' => 'bearer']);

        $html = $this->get($page)->body;
        self::assertStringNotContainsString('sk-HIDDEN-KEY', $html);
        self::assertStringContainsString('Ключ сохранён', $html);
        self::assertContains('ai.own_model_updated', $this->auditActions($workspace));
        $meta = json_encode($this->db->select('SELECT meta_json FROM audit_log WHERE workspace_id = ?', [$workspace->id]), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('HIDDEN', $meta);
        self::assertStringNotContainsString('llm.example.org', $meta);

        $this->post($page . '/own', ['enabled' => '1', 'base_url' => 'https://llm.example.org/v1', 'model' => 'm2', 'api_key' => '', 'auth' => 'bearer']);
        $own = $this->app->container()->get(\App\Domain\Ai\AiWorkspaceSettings::class)->own($workspace->id);
        self::assertNotNull($own);
        self::assertSame('sk-HIDDEN-KEY', $own['api_key']);
    }

    public function testTheEditorShowsTheAssistantToWritersOnly(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->givePlan($workspace, 'pro');
        $viewer = $this->memberOf($workspace, 'viewer@example.com', Role::Viewer);

        $this->actAs($owner);
        $html = $this->get($this->base($workspace) . '/posts/new')->body;
        // Without a channel the editor shows the empty state; the assistant script must still be gated by the permission only.
        self::assertStringContainsString('js/ai.js', $html);

        $this->actAs($viewer);
        self::assertNotSame(200, $this->get($this->base($workspace) . '/posts/new')->status, 'viewers cannot open the editor at all');
    }
}
