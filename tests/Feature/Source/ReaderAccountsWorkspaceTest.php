<?php

declare(strict_types=1);

namespace App\Tests\Feature\Source;

use App\Domain\Source\ReaderAccounts;
use App\Domain\Workspace\Role;
use App\Kernel\Http\Request;
use App\Tests\Support\WorkspaceTestCase;

/** Own account controls remain in the workspace boundary and never reveal a saved session. */
final class ReaderAccountsWorkspaceTest extends WorkspaceTestCase
{
    public function testOwnerCreatesImportsAndDisablesOwnAccount(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $base = $this->base($workspace) . '/sources';
        $this->actAs($owner);
        self::assertSame(200, $this->get($base)->status);
        self::assertSame(419, $this->app->handle(Request::create('POST', $base . '/reader-account'))->status);
        self::assertSame(302, $this->post($base . '/reader-account')->status);
        $row = $this->app->container()->get(ReaderAccounts::class)->list($workspace->id)[0];
        $id = (string) $row['public_id'];
        $session = '1' . str_repeat('P', 200);
        self::assertSame(302, $this->post($base . '/reader-account/' . $id . '/session', ['telegram_user_id' => '54321', 'session' => $session])->status);
        self::assertStringNotContainsString($session, $this->get($base)->body);
        self::assertSame('active', $this->app->container()->get(ReaderAccounts::class)->list($workspace->id)[0]['status']);
        self::assertSame(302, $this->post($base . '/reader-account/' . $id . '/status', ['status' => 'disabled'])->status);
        self::assertSame('disabled', $this->app->container()->get(ReaderAccounts::class)->list($workspace->id)[0]['status']);

        $viewer = $this->memberOf($workspace, 'reader-viewer@example.com', Role::Viewer);
        $this->actAs($viewer);
        self::assertSame(403, $this->post($base . '/reader-account')->status);
    }

    public function testForeignAccountCannotBeChanged(): void
    {
        [$firstOwner, $firstWorkspace] = $this->ownerWithWorkspace();
        [$secondOwner, $secondWorkspace] = $this->ownerWithWorkspace('reader-other@example.com');
        $id = $this->app->container()->get(ReaderAccounts::class)->create($firstWorkspace->id, 'First', $firstOwner->id);
        $this->actAs($secondOwner);
        self::assertSame(404, $this->post($this->base($secondWorkspace) . '/sources/reader-account/' . $id . '/status', ['status' => 'disabled'])->status);
    }
}
