<?php

declare(strict_types=1);

namespace App\Tests\Integration\Workspace;

use App\Domain\User\User;
use App\Domain\User\UserRepository;
use App\Domain\Channel\ChannelMode;
use App\Domain\Channel\ChannelRepository;
use App\Domain\Workspace\ChannelAccessRepository;
use App\Integrations\Social\Contracts\Platform;
use App\Domain\Workspace\InvitationLookup;
use App\Domain\Workspace\InvitationRepository;
use App\Domain\Workspace\MemberRepository;
use App\Domain\Workspace\Role;
use App\Domain\Workspace\Workspace;
use App\Domain\Workspace\WorkspaceContext;
use App\Domain\Workspace\WorkspaceRepository;
use App\Kernel\Database\Connection;
use App\Tests\Support\FakeClock;
use App\Tests\Support\TestEnv;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The workspace tables against a real MySQL: scoping by workspace, ownership transfer, channel access and invitation tokens.
 */
#[CoversClass(WorkspaceRepository::class)]
#[CoversClass(MemberRepository::class)]
#[CoversClass(ChannelAccessRepository::class)]
#[CoversClass(InvitationRepository::class)]
#[CoversClass(InvitationLookup::class)]
final class WorkspaceRepositoriesTest extends TestCase
{
    private Connection $db;
    private FakeClock $clock;
    private UserRepository $users;
    private WorkspaceRepository $workspaces;
    private MemberRepository $members;
    private ChannelAccessRepository $access;
    private InvitationRepository $invitations;
    private InvitationLookup $lookup;

    protected function setUp(): void
    {
        $this->db = TestEnv::connection();
        $this->db->execute('DELETE FROM workspaces');
        $this->db->execute('DELETE FROM users');
        $this->clock = new FakeClock('2026-10-04 12:00:00');
        $this->users = new UserRepository($this->db, $this->clock);
        $this->workspaces = new WorkspaceRepository($this->db, $this->clock);
        $this->members = new MemberRepository($this->db);
        $this->access = new ChannelAccessRepository($this->db, $this->clock);
        $this->invitations = new InvitationRepository($this->db, $this->clock);
        $this->lookup = new InvitationLookup($this->db, $this->clock);
    }

    private function user(string $email): User
    {
        $user = $this->users->create(['email' => $email, 'name' => $email, 'password_hash' => null]);
        self::assertNotNull($user);

        return $user;
    }

    /**
     * @return array{User, Workspace, WorkspaceContext}
     */
    private function owned(string $email): array
    {
        $user = $this->user($email);
        $workspace = $this->workspaces->create($user->id, 'WS ' . $email, 'Europe/Moscow');

        return [$user, $workspace, $this->context($workspace, $user)];
    }

    private function context(Workspace $workspace, User $user): WorkspaceContext
    {
        $membership = $this->workspaces->membership($workspace->id, $user->id);
        self::assertNotNull($membership);

        return WorkspaceContext::from($workspace, $membership);
    }

    public function testCreateMakesTheOwnerAMemberWithAUrlSafeId(): void
    {
        [$user, $workspace] = $this->owned('a@example.com');

        self::assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $workspace->publicId);
        self::assertSame(Role::Owner, $this->workspaces->membership($workspace->id, $user->id)?->role);
        self::assertNotNull($this->workspaces->findByPublicId(strtolower($workspace->publicId)), 'ids are case-insensitive');
        self::assertNull($this->workspaces->findByPublicId('not-a-ulid'));
        self::assertNull($this->workspaces->findByPublicId((string) $workspace->id));
    }

    public function testContextCannotBeBuiltFromAForeignMembership(): void
    {
        [, $a] = $this->owned('a@example.com');
        [$userB, $b] = $this->owned('b@example.com');
        $membershipB = $this->workspaces->membership($b->id, $userB->id);
        self::assertNotNull($membershipB);

        $this->expectException(\InvalidArgumentException::class);
        WorkspaceContext::from($a, $membershipB);
    }

    public function testMemberQueriesAreLimitedToTheContextWorkspace(): void
    {
        [$userA, , $ctxA] = $this->owned('a@example.com');
        [$userB, , $ctxB] = $this->owned('b@example.com');
        $foreignMember = $this->members->all($ctxB)[0];

        self::assertNull($this->members->find($ctxA, $foreignMember->publicId));
        self::assertCount(1, $this->members->all($ctxA));
        self::assertSame($userA->id, $this->members->all($ctxA)[0]->userId);
        self::assertFalse($this->members->remove($ctxA, $userB->id), 'cannot remove a user of another workspace');
        self::assertCount(1, $this->members->all($ctxB));
        self::assertNull($this->members->findByEmail($ctxA, 'b@example.com'));
    }

    public function testInvitationsAreScopedAndTheLatestReplacesOlderOnes(): void
    {
        [$userA, , $ctxA] = $this->owned('a@example.com');
        [, , $ctxB] = $this->owned('b@example.com');
        $first = $this->invitations->create($ctxA, 'x@example.com', Role::Editor, InvitationLookup::hash('one'), $userA->id, 3600);
        $second = $this->invitations->create($ctxA, 'x@example.com', Role::Viewer, InvitationLookup::hash('two'), $userA->id, 3600);

        self::assertSame([$second->publicId], array_map(static fn ($i): string => $i->publicId, $this->invitations->open($ctxA)));
        self::assertSame([], $this->invitations->open($ctxB));
        self::assertNull($this->invitations->find($ctxB, $second->publicId));
        self::assertFalse($this->invitations->revoke($ctxB, $second->publicId), 'a foreign workspace cannot revoke it');
        self::assertFalse($this->invitations->revoke($ctxA, $first->publicId), 'already replaced');
        self::assertTrue($this->invitations->revoke($ctxA, $second->publicId));
        self::assertSame([], $this->invitations->open($ctxA));
    }

    public function testInvitationTokenLookupAndSingleUse(): void
    {
        [$user, , $ctx] = $this->owned('a@example.com');
        $token = InvitationLookup::newToken();
        $this->invitations->create($ctx, 'x@example.com', Role::Editor, InvitationLookup::hash($token), $user->id, 3600);

        self::assertMatchesRegularExpression(InvitationLookup::TOKEN_PATTERN, $token);
        self::assertNull($this->lookup->findOpen('short'));
        self::assertNull($this->lookup->findOpen(str_repeat('b', 43)));
        $found = $this->lookup->findOpen($token);
        self::assertNotNull($found);
        self::assertTrue($this->lookup->markAccepted($found));
        self::assertFalse($this->lookup->markAccepted($found), 'a second claim loses');
        self::assertNull($this->lookup->findOpen($token));
    }

    public function testExpiredInvitationIsNotFoundAndGetsPruned(): void
    {
        [$user, , $ctx] = $this->owned('a@example.com');
        $token = InvitationLookup::newToken();
        $this->invitations->create($ctx, 'x@example.com', Role::Editor, InvitationLookup::hash($token), $user->id, 60);

        $this->clock->advance(61);
        self::assertNull($this->lookup->findOpen($token));
        self::assertSame([], $this->invitations->open($ctx));
        self::assertSame(0, $this->lookup->prune());
        $this->clock->advance(31 * 86400);
        self::assertSame(1, $this->lookup->prune());
    }

    public function testTransferOwnershipKeepsExactlyOneOwner(): void
    {
        [$owner, $workspace, $ctx] = $this->owned('a@example.com');
        $other = $this->user('b@example.com');
        $outsider = $this->user('c@example.com');
        self::assertTrue($this->workspaces->addMember($workspace->id, $other->id, Role::Editor, $owner->id));
        self::assertFalse($this->workspaces->addMember($workspace->id, $other->id, Role::Admin, $owner->id), 'no duplicate membership');

        self::assertFalse($this->workspaces->transferOwnership($ctx, $outsider->id), 'the target must be a member');
        self::assertFalse($this->workspaces->transferOwnership($ctx, $owner->id), 'not to yourself');
        self::assertTrue($this->workspaces->transferOwnership($ctx, $other->id));
        self::assertFalse($this->workspaces->transferOwnership($ctx, $other->id), 'the previous owner no longer can');

        self::assertSame(Role::Owner, $this->workspaces->membership($workspace->id, $other->id)?->role);
        self::assertSame(Role::Admin, $this->workspaces->membership($workspace->id, $owner->id)?->role);
        self::assertSame($other->id, $this->workspaces->findByPublicId($workspace->publicId)?->ownerId);
    }

    /**
     * A real channel row: the access list refers to channels by a foreign key.
     */
    private function channel(WorkspaceContext $ctx, string $externalId): int
    {
        $repository = new ChannelRepository($this->db, $this->clock);

        return $repository->connect($ctx, Platform::Telegram, $externalId, ChannelMode::SharedBot, 'Канал ' . $externalId, null, 'channel', null, [], $ctx->userId)['channel']->id;
    }

    public function testChannelAccessDefaultsAndRestrictions(): void
    {
        [$owner, $workspace, $ctx] = $this->owned('a@example.com');
        $author = $this->user('author@example.com');
        $client = $this->user('client@example.com');
        $this->workspaces->addMember($workspace->id, $author->id, Role::Author, $owner->id);
        $this->workspaces->addMember($workspace->id, $client->id, Role::Client, $owner->id);

        self::assertNull($this->access->allowed($ctx, $author->id), 'authors see every channel by default');
        self::assertSame([], $this->access->allowed($ctx, $client->id), 'a client sees nothing until channels are assigned');

        $first = $this->channel($ctx, '-1001');
        $second = $this->channel($ctx, '-1002');
        self::assertTrue($this->access->set($ctx, $author->id, [$first, $second, $first]));
        self::assertSame([$first, $second], $this->access->allowed($ctx, $author->id));
        self::assertTrue($this->access->set($ctx, $author->id, []));
        self::assertSame([], $this->access->allowed($ctx, $author->id), 'restricted to nothing');
        self::assertTrue($this->access->set($ctx, $author->id, null));
        self::assertNull($this->access->allowed($ctx, $author->id), 'restriction lifted');

        self::assertTrue($this->access->set($ctx, $client->id, null));
        self::assertSame([], $this->access->allowed($ctx, $client->id), 'a client can never be unrestricted');
        $this->access->set($ctx, $client->id, [$first]);
        $this->members->setRole($ctx, $this->members->findByEmail($ctx, 'client@example.com') ?? self::fail('client missing'), Role::Viewer);
        self::assertNull($this->access->allowed($ctx, $client->id), 'leaving the client role lifts the limit');
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM member_channel_access')[0]['c']);
    }

    public function testChannelAccessIsPerWorkspaceAndRemovedWithTheMember(): void
    {
        [$ownerA, $a, $ctxA] = $this->owned('a@example.com');
        [, , $ctxB] = $this->owned('b@example.com');
        $member = $this->user('m@example.com');
        $this->workspaces->addMember($a->id, $member->id, Role::Author, $ownerA->id);
        $this->access->set($ctxA, $member->id, [$this->channel($ctxA, '-1001')]);

        self::assertFalse($this->access->set($ctxB, $member->id, [5]), 'not a member of B');
        self::assertNull($this->access->allowed($ctxB, $member->id));
        $this->members->remove($ctxA, $member->id);
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM member_channel_access')[0]['c'], 'cascade on removal');
    }

    public function testDeletingAnAccountRemovesItsWorkspacesAndMemberships(): void
    {
        [$owner, $workspace] = $this->owned('a@example.com');
        $other = $this->user('b@example.com');
        $this->workspaces->addMember($workspace->id, $other->id, Role::Viewer, $owner->id);

        $this->users->delete($other->id);
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS c FROM workspace_members')[0]['c']);
        $this->users->delete($owner->id);
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM workspaces')[0]['c']);
    }

    public function testForUserListsPersonalFirst(): void
    {
        $user = $this->user('a@example.com');
        $this->workspaces->create($user->id, 'Б проект', 'UTC');
        $this->workspaces->create($user->id, 'Личное', 'UTC', true);
        $this->workspaces->create($user->id, 'А проект', 'UTC');

        $names = array_map(static fn (array $row): string => $row['workspace']->name, $this->workspaces->forUser($user->id));

        self::assertSame(['Личное', 'А проект', 'Б проект'], $names);
        self::assertSame(3, $this->workspaces->countOwnedBy($user->id));
    }
}
