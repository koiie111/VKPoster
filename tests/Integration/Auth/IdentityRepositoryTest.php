<?php

declare(strict_types=1);

namespace App\Tests\Integration\Auth;

use App\Domain\Auth\Social\IdentityRepository;
use App\Domain\User\UserRepository;
use App\Integrations\OAuth\SocialProfile;
use App\Tests\Support\FakeClock;
use App\Tests\Support\TestEnv;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IdentityRepository::class)]
final class IdentityRepositoryTest extends TestCase
{
    private IdentityRepository $identities;
    private UserRepository $users;

    protected function setUp(): void
    {
        $db = TestEnv::connection();
        $db->execute('DELETE FROM users');
        $clock = new FakeClock('2026-10-04 12:00:00');
        $this->identities = new IdentityRepository($db, $clock);
        $this->users = new UserRepository($db, $clock);
    }

    private function user(string $email): int
    {
        $user = $this->users->create(['email' => $email, 'name' => 'U', 'password_hash' => null]);
        self::assertNotNull($user);

        return $user->id;
    }

    private function profile(string $provider = 'google', string $id = 'g-1'): SocialProfile
    {
        return new SocialProfile($provider, $id, 'p@example.com', true, 'Имя');
    }

    public function testLinkAndFind(): void
    {
        $id = $this->user('a@example.com');

        self::assertTrue($this->identities->link($id, $this->profile()));

        $found = $this->identities->find('google', 'g-1');
        self::assertNotNull($found);
        self::assertSame($id, $found->userId);
        self::assertSame('Имя', $found->displayName);
        self::assertSame('p@example.com', $found->email);
        self::assertNull($this->identities->find('google', 'other'));
        self::assertNull($this->identities->find('vkid', 'g-1'));
        self::assertCount(1, $this->identities->forUser($id));
        self::assertNotNull($this->identities->findForUser($id, 'google'));
    }

    public function testAProviderAccountBelongsToOneUser(): void
    {
        $a = $this->user('a@example.com');
        $b = $this->user('b@example.com');
        $this->identities->link($a, $this->profile());

        self::assertFalse($this->identities->link($b, $this->profile()));
        self::assertSame([], $this->identities->forUser($b));
    }

    public function testAUserHasOneAccountPerProvider(): void
    {
        $a = $this->user('a@example.com');
        $this->identities->link($a, $this->profile('google', 'g-1'));

        self::assertFalse($this->identities->link($a, $this->profile('google', 'g-2')));
        self::assertTrue($this->identities->link($a, $this->profile('vkid', '55')));
        self::assertCount(2, $this->identities->forUser($a));
    }

    public function testUnlinkOnlyTouchesTheOwnersRow(): void
    {
        $a = $this->user('a@example.com');
        $b = $this->user('b@example.com');
        $this->identities->link($a, $this->profile('google', 'g-1'));
        $this->identities->link($b, $this->profile('vkid', '5'));

        self::assertTrue($this->identities->unlink($a, 'google'));
        self::assertFalse($this->identities->unlink($a, 'google'));
        self::assertFalse($this->identities->unlink($a, 'vkid'));
        self::assertCount(1, $this->identities->forUser($b));
    }

    public function testIdentitiesDieWithTheirUser(): void
    {
        $a = $this->user('a@example.com');
        $this->identities->link($a, $this->profile());

        $this->users->delete($a);

        self::assertNull($this->identities->find('google', 'g-1'));
    }

    public function testTouchRefreshesTheReportedProfile(): void
    {
        $a = $this->user('a@example.com');
        $this->identities->link($a, $this->profile());
        $identity = $this->identities->find('google', 'g-1');
        self::assertNotNull($identity);

        $this->identities->touch($identity, new SocialProfile('google', 'g-1', 'new@example.com', true, 'Новое имя'));

        $fresh = $this->identities->find('google', 'g-1');
        self::assertNotNull($fresh);
        self::assertSame('new@example.com', $fresh->email);
        self::assertSame('Новое имя', $fresh->displayName);
    }

    public function testTouchKeepsTheNameWhenTheProviderSendsNone(): void
    {
        $a = $this->user('a@example.com');
        $this->identities->link($a, $this->profile());
        $identity = $this->identities->find('google', 'g-1');
        self::assertNotNull($identity);

        $this->identities->touch($identity, new SocialProfile('google', 'g-1', null, false, ''));

        self::assertSame('Имя', $this->identities->find('google', 'g-1')?->displayName);
    }
}
