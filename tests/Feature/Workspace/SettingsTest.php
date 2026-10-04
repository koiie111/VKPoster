<?php

declare(strict_types=1);

namespace App\Tests\Feature\Workspace;

use App\Domain\Workspace\Role;
use App\Domain\Workspace\WorkspaceService;
use App\Tests\Support\WorkspaceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Workspace settings (name, time zone, language) and deleting a workspace.
 */
#[CoversClass(WorkspaceService::class)]
final class SettingsTest extends WorkspaceTestCase
{
    public function testSettingsAreSavedAndShown(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->actAs($owner);
        $url = $this->base($workspace) . '/settings';

        $page = $this->get($url)->body;
        self::assertStringContainsString('Пространство: Ольга', $page);
        self::assertStringContainsString('Europe/Moscow (UTC+3)', $page);

        $this->post($url, ['name' => '  Кофейня «Зерно»  ', 'timezone' => 'Asia/Yekaterinburg', 'locale' => 'ru']);

        $row = $this->db->select('SELECT * FROM workspaces WHERE id = ?', [$workspace->id])[0];
        self::assertSame('Кофейня «Зерно»', $row['name']);
        self::assertSame('Asia/Yekaterinburg', $row['timezone']);
        self::assertStringContainsString('Настройки сохранены.', $this->get($url)->body);
        self::assertContains('workspace.updated', $this->auditActions($workspace));
    }

    public function testValidationKeepsWhatWasTyped(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->actAs($owner);
        $url = $this->base($workspace) . '/settings';

        $this->post($url, ['name' => 'Х', 'timezone' => 'Mars/Base', 'locale' => 'xx']);
        $page = $this->get($url)->body;

        self::assertSame('Пространство: Ольга', $this->db->select('SELECT name FROM workspaces WHERE id = ?', [$workspace->id])[0]['name']);
        self::assertStringContainsString('не меньше 2', $page);
        self::assertStringContainsString('Выберите существующий часовой пояс', $page);
        self::assertStringContainsString('value="Х"', $page, 'the typed name is kept');
    }

    public function testAdminMayEditSettingsButNotDelete(): void
    {
        [, $workspace] = $this->ownerWithWorkspace();
        $admin = $this->memberOf($workspace, 'admin@example.com', Role::Admin);
        $this->actAs($admin);

        self::assertSame(200, $this->get($this->base($workspace) . '/settings')->status);
        self::assertStringNotContainsString('delete-dialog', $this->get($this->base($workspace) . '/settings')->body);
        $this->post($this->base($workspace) . '/settings', ['name' => 'Новое имя', 'timezone' => 'UTC', 'locale' => 'ru']);
        self::assertSame('Новое имя', $this->db->select('SELECT name FROM workspaces WHERE id = ?', [$workspace->id])[0]['name']);
        self::assertSame(403, $this->post($this->base($workspace) . '/delete', ['confirm_name' => 'Новое имя'])->status);
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS c FROM workspaces')[0]['c']);
    }

    public function testEditorCannotOpenSettings(): void
    {
        [, $workspace] = $this->ownerWithWorkspace();
        $editor = $this->memberOf($workspace, 'editor@example.com', Role::Editor);
        $this->actAs($editor);

        self::assertSame(403, $this->get($this->base($workspace) . '/settings')->status);
        self::assertSame(403, $this->post($this->base($workspace) . '/settings', ['name' => 'Взлом', 'timezone' => 'UTC', 'locale' => 'ru'])->status);
    }

    public function testDeletingNeedsTheTypedNameAndRemovesEverything(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->memberOf($workspace, 'second@example.com', Role::Editor);
        $this->actAs($owner);

        $this->post($this->base($workspace) . '/delete', ['confirm_name' => 'не то']);
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS c FROM workspaces')[0]['c'], 'wrong name: nothing deleted');
        self::assertStringContainsString('Название введено неверно', $this->get($this->base($workspace) . '/settings')->body);

        $response = $this->post($this->base($workspace) . '/delete', ['confirm_name' => $workspace->name]);

        self::assertSame('/app', $response->header('Location'));
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM workspaces')[0]['c']);
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM workspace_members')[0]['c']);
        $trail = $this->db->select('SELECT action FROM audit_log WHERE workspace_id = ? AND action = ?', [$workspace->id, 'workspace.deleted']);
        self::assertCount(1, $trail, 'the audit trail outlives the workspace');
        // /app creates a fresh personal workspace for the owner who now has none.
        self::assertStringStartsWith('/w/', (string) $this->get('/app')->header('Location'));
    }

    public function testOwnerCanSeeTheLeaveHint(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->actAs($owner);

        self::assertStringContainsString('Вы владелец, поэтому выйти нельзя', $this->get($this->base($workspace) . '/settings')->body);
    }
}
