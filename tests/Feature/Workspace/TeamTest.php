<?php

declare(strict_types=1);

namespace App\Tests\Feature\Workspace;

use App\Domain\Workspace\InvitationLookup;
use App\Domain\Workspace\Role;
use App\Domain\Workspace\TeamService;
use App\Domain\Workspace\Workspace;
use App\Tests\Support\ArrayMailer;
use App\Tests\Support\WorkspaceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Inviting, accepting, changing roles, removing, leaving and transferring ownership.
 */
#[CoversClass(TeamService::class)]
#[CoversClass(InvitationLookup::class)]
final class TeamTest extends WorkspaceTestCase
{
    /**
     * Invite an address as the current user and return the raw token from the email.
     */
    private function invite(Workspace $workspace, string $email, string $role): string
    {
        $response = $this->post($this->base($workspace) . '/team/invitations', ['email' => $email, 'role' => $role]);
        self::assertSame($this->base($workspace) . '/team', $response->header('Location'));
        $this->drainQueue();
        $mail = $this->mailer->lastTo(strtolower($email));
        self::assertNotNull($mail, 'invitation mail to ' . $email);
        self::assertMatchesRegularExpression('#^/invitations/([A-Za-z0-9_-]{43})$#', ArrayMailer::path($mail));

        return substr(ArrayMailer::path($mail), strlen('/invitations/'));
    }

    private function memberRole(Workspace $workspace, string $email): ?string
    {
        $rows = $this->db->select('SELECT m.role FROM workspace_members m JOIN users u ON u.id = m.user_id WHERE m.workspace_id = ? AND u.email = ?', [$workspace->id, $email]);

        return $rows === [] ? null : (string) $rows[0]['role'];
    }

    private function memberId(Workspace $workspace, string $email): string
    {
        return (string) $this->db->select('SELECT m.public_id FROM workspace_members m JOIN users u ON u.id = m.user_id WHERE m.workspace_id = ? AND u.email = ?', [$workspace->id, $email])[0]['public_id'];
    }

    public function testInvitationEmailCarriesATokenThatIsStoredOnlyAsAHash(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->actAs($owner);

        $token = $this->invite($workspace, 'New@Example.com', 'editor');

        $row = $this->db->select('SELECT * FROM invitations')[0];
        self::assertSame(hash('sha256', $token), $row['token_hash']);
        self::assertStringNotContainsString($token, json_encode($row, JSON_THROW_ON_ERROR));
        self::assertSame('new@example.com', $row['email']);
        self::assertSame('editor', $row['role']);
        $expires = new \DateTimeImmutable((string) $row['expires_at'], new \DateTimeZone('UTC'));
        self::assertEqualsWithDelta(7 * 86400, $expires->getTimestamp() - $this->clock->now()->getTimestamp(), 5);
        $mail = $this->mailer->lastTo('new@example.com');
        self::assertNotNull($mail);
        self::assertStringContainsString('Пространство: Ольга', $mail->subject . $mail->text);
        self::assertStringContainsString('Редактор', $mail->text);
        self::assertContains('member.invited', $this->auditActions($workspace));
    }

    public function testGuestSeesTheInvitationThenReturnsAfterSigningIn(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->actAs($owner);
        $token = $this->invite($workspace, 'newbie@example.com', 'author');
        $this->createUser('newbie@example.com', true, null, 'Новичок');

        $this->useBrowser();
        $page = $this->get('/invitations/' . $token);
        self::assertSame(200, $page->status);
        self::assertStringContainsString('Пространство: Ольга', $page->body);
        self::assertStringContainsString('Автор', $page->body);
        self::assertStringContainsString('href="/login"', $page->body);
        self::assertStringContainsString('href="/register"', $page->body);

        $login = $this->signIn('newbie@example.com');
        self::assertSame('/invitations/' . $token, $login->header('Location'), 'sign-in leads back to the invitation');

        $page = $this->get('/invitations/' . $token);
        self::assertStringContainsString('Войти в команду', $page->body);
        $accepted = $this->post('/invitations/' . $token . '/accept');
        self::assertSame($this->base($workspace), $accepted->header('Location'));
        self::assertSame('author', $this->memberRole($workspace, 'newbie@example.com'));
        self::assertContains('member.joined', $this->auditActions($workspace));
        self::assertStringContainsString('Вы в команде', $this->get($this->base($workspace))->body);
    }

    public function testAnInvitationWorksOnlyOnce(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->actAs($owner);
        $token = $this->invite($workspace, 'once@example.com', 'viewer');
        $newbie = $this->createUser('once@example.com');
        $this->actAs($newbie);

        self::assertSame($this->base($workspace), $this->post('/invitations/' . $token . '/accept')->header('Location'));
        $this->workspaces->addMember($workspace->id, $newbie->id, Role::Viewer, null); // already a member: nothing changes

        $again = $this->post('/invitations/' . $token . '/accept');
        self::assertSame(410, $again->status);
        self::assertSame(410, $this->get('/invitations/' . $token)->status);
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS c FROM workspace_members WHERE user_id = ?', [$newbie->id])[0]['c']);
    }

    public function testExpiredRevokedAndUnknownLinksAreRefused(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->actAs($owner);
        $expired = $this->invite($workspace, 'late@example.com', 'viewer');
        $revoked = $this->invite($workspace, 'gone@example.com', 'viewer');
        $invitationId = (string) $this->db->select('SELECT public_id FROM invitations WHERE email = ?', ['gone@example.com'])[0]['public_id'];
        $this->post($this->base($workspace) . '/team/invitations/' . $invitationId . '/revoke');
        self::assertContains('member.invitation_revoked', $this->auditActions($workspace));

        $late = $this->createUser('late@example.com');
        $gone = $this->createUser('gone@example.com');
        $this->clock->advance(7 * 86400 + 60);
        $this->actAs($late);
        self::assertSame(410, $this->get('/invitations/' . $expired)->status, 'expired');
        self::assertSame(410, $this->post('/invitations/' . $expired . '/accept')->status);
        $this->actAs($gone);
        self::assertSame(410, $this->get('/invitations/' . $revoked)->status, 'revoked');
        self::assertSame(410, $this->get('/invitations/' . str_repeat('a', 43))->status, 'unknown');
        self::assertSame(404, $this->get('/invitations/short')->status, 'malformed');
        self::assertNull($this->memberRole($workspace, 'late@example.com'));
        self::assertNull($this->memberRole($workspace, 'gone@example.com'));
    }

    public function testSomeoneElsesAccountCannotUseTheLink(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->actAs($owner);
        $token = $this->invite($workspace, 'invited@example.com', 'admin');
        $other = $this->createUser('other@example.com');
        $this->actAs($other);

        $response = $this->post('/invitations/' . $token . '/accept');

        self::assertSame(403, $response->status);
        self::assertStringContainsString('для другого адреса', $response->body);
        self::assertNull($this->memberRole($workspace, 'other@example.com'));
        // The link still works for the real addressee.
        $invited = $this->createUser('invited@example.com');
        $this->actAs($invited);
        self::assertSame($this->base($workspace), $this->post('/invitations/' . $token . '/accept')->header('Location'));
    }

    public function testANewerInvitationReplacesTheOlderOneForTheSameAddress(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->actAs($owner);
        $first = $this->invite($workspace, 'twice@example.com', 'viewer');
        $second = $this->invite($workspace, 'twice@example.com', 'editor');

        self::assertNotSame($first, $second);
        self::assertSame(410, $this->get('/invitations/' . $first)->status);
        self::assertSame(200, $this->get('/invitations/' . $second)->status);
        $team = $this->get($this->base($workspace) . '/team')->body;
        self::assertSame(1, substr_count($team, 'Отозвать приглашение: twice@example.com'), 'one open invitation is listed');
    }

    public function testInvitingChecksRoleAddressAndExistingMembers(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $admin = $this->memberOf($workspace, 'admin@example.com', Role::Admin);
        $this->actAs($admin);
        $url = $this->base($workspace) . '/team/invitations';

        $this->post($url, ['email' => 'x@example.com', 'role' => 'admin']);
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM invitations')[0]['c'], 'an admin cannot create admins');
        $this->post($url, ['email' => 'x@example.com', 'role' => 'owner']);
        $this->post($url, ['email' => 'not-an-email', 'role' => 'editor']);
        $this->post($url, ['email' => 'x@example.com', 'role' => 'nonsense']);
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM invitations')[0]['c']);
        $this->post($url, ['email' => $owner->email, 'role' => 'editor']);
        self::assertStringContainsString('уже в команде', $this->get($this->base($workspace) . '/team')->body);
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM invitations')[0]['c']);
    }

    public function testInvitationsAreRateLimitedPerWorkspace(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->actAs($owner);
        for ($i = 1; $i <= 20; ++$i) {
            $this->post($this->base($workspace) . '/team/invitations', ['email' => 'p' . $i . '@example.com', 'role' => 'viewer']);
        }

        $this->post($this->base($workspace) . '/team/invitations', ['email' => 'p21@example.com', 'role' => 'viewer']);

        self::assertSame(20, (int) $this->db->select('SELECT COUNT(*) AS c FROM invitations')[0]['c']);
        self::assertStringContainsString('слишком много', $this->get($this->base($workspace) . '/team')->body);
    }

    public function testChangingRolesFollowsTheHierarchy(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $admin = $this->memberOf($workspace, 'admin@example.com', Role::Admin);
        $admin2 = $this->memberOf($workspace, 'admin2@example.com', Role::Admin);
        $editor = $this->memberOf($workspace, 'editor@example.com', Role::Editor);
        $role = fn (string $email, string $role) => $this->post($this->base($workspace) . '/team/members/' . $this->memberId($workspace, $email) . '/role', ['role' => $role]);

        $this->actAs($admin);
        $role('editor@example.com', 'viewer');
        self::assertSame('viewer', $this->memberRole($workspace, 'editor@example.com'));
        $role('editor@example.com', 'admin');
        self::assertSame('viewer', $this->memberRole($workspace, 'editor@example.com'), 'an admin cannot make admins');
        $role('admin2@example.com', 'viewer');
        self::assertSame('admin', $this->memberRole($workspace, 'admin2@example.com'), 'an admin cannot demote an admin');
        $role('owner@example.com', 'viewer');
        self::assertSame('owner', $this->memberRole($workspace, 'owner@example.com'), 'nobody touches the owner');
        $role('admin@example.com', 'viewer');
        self::assertSame('admin', $this->memberRole($workspace, 'admin@example.com'), 'nobody changes their own role');

        $this->actAs($owner);
        $role('admin2@example.com', 'author');
        self::assertSame('author', $this->memberRole($workspace, 'admin2@example.com'));
        $role('owner@example.com', 'admin');
        self::assertSame('owner', $this->memberRole($workspace, 'owner@example.com'), 'the last owner cannot be demoted');
        $role('editor@example.com', 'owner');
        self::assertSame('viewer', $this->memberRole($workspace, 'editor@example.com'), 'ownership moves only by transfer');
        $role('editor@example.com', 'bogus');
        self::assertSame('viewer', $this->memberRole($workspace, 'editor@example.com'));
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS c FROM workspace_members WHERE workspace_id = ? AND role = ?', [$workspace->id, 'owner'])[0]['c']);
        $changes = array_keys(array_filter($this->auditActions($workspace), static fn (string $a): bool => $a === 'member.role_changed'));
        self::assertCount(2, $changes);
        self::assertNotSame($admin->id, $admin2->id);
    }

    public function testRemovingAMember(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->memberOf($workspace, 'admin@example.com', Role::Admin);
        $this->memberOf($workspace, 'author@example.com', Role::Author);
        $remove = fn (string $email) => $this->post($this->base($workspace) . '/team/members/' . $this->memberId($workspace, $email) . '/remove');

        $this->actAs($this->app->container()->get(\App\Domain\User\UserRepository::class)->findByEmail('admin@example.com') ?? $owner);
        $remove('owner@example.com');
        self::assertSame('owner', $this->memberRole($workspace, 'owner@example.com'));
        $remove('admin@example.com');
        self::assertSame('admin', $this->memberRole($workspace, 'admin@example.com'), 'nobody removes themselves this way');
        $remove('author@example.com');
        self::assertNull($this->memberRole($workspace, 'author@example.com'));
        self::assertContains('member.removed', $this->auditActions($workspace));
        // The removed person lost access at once.
        $this->actAs($this->app->container()->get(\App\Domain\User\UserRepository::class)->findByEmail('author@example.com') ?? $owner);
        self::assertSame(404, $this->get($this->base($workspace))->status);
    }

    public function testAnotherWorkspacesMemberIdIsNotFound(): void
    {
        [$ownerA, $a] = $this->ownerWithWorkspace('a@example.com');
        [, $b] = $this->ownerWithWorkspace('b@example.com', 'Борис');
        $victim = $this->memberOf($b, 'victim@example.com', Role::Editor);
        $this->actAs($ownerA);

        $this->post($this->base($a) . '/team/members/' . $this->memberId($b, 'victim@example.com') . '/remove');
        $this->post($this->base($a) . '/team/members/' . $this->memberId($b, 'victim@example.com') . '/role', ['role' => 'viewer']);

        self::assertSame('editor', $this->memberRole($b, 'victim@example.com'));
    }

    public function testMembersCanLeaveButTheOwnerCannot(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $editor = $this->memberOf($workspace, 'editor@example.com', Role::Editor);

        $this->actAs($owner);
        $this->post($this->base($workspace) . '/leave');
        self::assertSame('owner', $this->memberRole($workspace, 'owner@example.com'));
        self::assertStringContainsString('Владелец не может выйти', $this->get($this->base($workspace) . '/team')->body);

        $this->actAs($editor);
        $response = $this->post($this->base($workspace) . '/leave');
        self::assertSame('/app', $response->header('Location'));
        self::assertNull($this->memberRole($workspace, 'editor@example.com'));
        self::assertContains('member.left', $this->auditActions($workspace));
        self::assertSame(404, $this->get($this->base($workspace))->status);
    }

    public function testOwnershipTransfer(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $admin = $this->memberOf($workspace, 'admin@example.com', Role::Admin);
        $client = $this->memberOf($workspace, 'client@example.com', Role::Client);
        $transfer = fn (string $email, string $name) => $this->post($this->base($workspace) . '/team/transfer', ['member' => $this->memberId($workspace, $email), 'confirm_name' => $name]);

        $this->actAs($admin);
        self::assertSame(403, $transfer('admin@example.com', $workspace->name)->status, 'only the owner may transfer');

        $this->actAs($owner);
        $transfer('admin@example.com', 'не то название');
        self::assertSame('owner', $this->memberRole($workspace, 'owner@example.com'), 'the name must be typed');
        $transfer('client@example.com', $workspace->name);
        self::assertSame('owner', $this->memberRole($workspace, 'owner@example.com'), 'not to a client');
        $transfer('owner@example.com', $workspace->name);
        self::assertSame('owner', $this->memberRole($workspace, 'owner@example.com'), 'not to yourself');

        $transfer('admin@example.com', $workspace->name);
        self::assertSame('admin', $this->memberRole($workspace, 'owner@example.com'));
        self::assertSame('owner', $this->memberRole($workspace, 'admin@example.com'));
        self::assertSame($admin->id, (int) $this->db->select('SELECT owner_id FROM workspaces WHERE id = ?', [$workspace->id])[0]['owner_id']);
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS c FROM workspace_members WHERE workspace_id = ? AND role = ?', [$workspace->id, 'owner'])[0]['c']);
        self::assertContains('workspace.ownership_transferred', $this->auditActions($workspace));
        // The former owner is a plain admin now: no more transfer or deletion.
        self::assertSame(403, $this->post($this->base($workspace) . '/delete', ['confirm_name' => $workspace->name])->status);
    }

    public function testTeamPageListsMembersAndOpenInvitations(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->memberOf($workspace, 'editor@example.com', Role::Editor, 'Пётр Редактор');
        $this->actAs($owner);
        $this->invite($workspace, 'pending@example.com', 'viewer');

        $page = $this->get($this->base($workspace) . '/team')->body;

        self::assertStringContainsString('Пётр Редактор', $page);
        self::assertStringContainsString('pending@example.com', $page);
        self::assertStringContainsString('Участники (2)', $page);
        self::assertStringContainsString('Ждут ответа (1)', $page);
        self::assertStringContainsString('Передать владение', $page);
    }

    public function testEmptyInvitationListShowsAnExplanation(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->actAs($owner);

        self::assertStringContainsString('Открытых приглашений нет', $this->get($this->base($workspace) . '/team')->body);
    }
}
