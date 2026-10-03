<?php

declare(strict_types=1);

namespace App\Tests\Unit\Kernel;

use App\Kernel\Http\Request;
use App\Kernel\Security\Csrf;
use App\Kernel\Session\Session;
use App\Tests\Support\ArraySessionStore;
use App\Tests\Support\FakeClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Session lifecycle, flash data, timeouts, id regeneration, and CSRF token checks.
 */
#[CoversClass(Session::class)]
#[CoversClass(Csrf::class)]
final class SessionTest extends TestCase
{
    private ArraySessionStore $store;
    private FakeClock $clock;

    protected function setUp(): void
    {
        $this->store = new ArraySessionStore();
        $this->clock = new FakeClock();
    }

    private function session(?string $id = null, int $idle = 100, int $absolute = 1000): Session
    {
        $session = new Session($this->store, $this->clock, $idle, $absolute);
        $session->start($id);

        return $session;
    }

    public function testNewAnonymousSessionIsNotPersisted(): void
    {
        $session = $this->session();
        $session->save();

        self::assertSame([], $this->store->items);
        self::assertFalse($session->needsCookie());
    }

    public function testDataPersistsAcrossRequests(): void
    {
        $first = $this->session();
        $first->set('user', 5);
        $first->save();
        self::assertTrue($first->needsCookie());

        $second = $this->session($first->id());

        self::assertSame(5, $second->get('user'));
        self::assertFalse($second->isNew());
        self::assertFalse($second->needsCookie());
    }

    public function testMalformedIdStartsFreshSession(): void
    {
        $session = $this->session('../../etc/passwd');

        self::assertMatchesRegularExpression(Session::ID_PATTERN, $session->id());
        self::assertTrue($session->isNew());
    }

    public function testUnknownIdIsNotAdopted(): void
    {
        $forged = str_repeat('a', 64);
        $session = $this->session($forged);

        self::assertNotSame($forged, $session->id());
    }

    public function testFlashLivesForExactlyOneNextRequest(): void
    {
        $first = $this->session();
        $first->set('x', 1);
        $first->flash('msg', 'saved');
        $first->save();

        $second = $this->session($first->id());
        self::assertSame('saved', $second->getFlash('msg'));
        $second->save();

        $third = $this->session($first->id());
        self::assertNull($third->getFlash('msg'));
    }

    public function testFlashValidationDropsPasswordFields(): void
    {
        $first = $this->session();
        $first->flashValidation(['email' => 'a@b.co', 'password' => 'hunter2', '_token' => 't'], ['email' => ['bad']]);
        $first->save();

        $second = $this->session($first->id());

        self::assertSame(['email' => 'a@b.co'], $second->getFlash('_old'));
        self::assertSame(['email' => ['bad']], $second->getFlash('_errors'));
    }

    public function testRegenerateIssuesNewIdAndKillsOldOne(): void
    {
        $first = $this->session();
        $first->set('user', 5);
        $first->save();
        $oldId = $first->id();

        $again = $this->session($oldId);
        $again->regenerate();
        $again->save();

        self::assertNotSame($oldId, $again->id());
        self::assertTrue($again->needsCookie());
        self::assertArrayNotHasKey($oldId, $this->store->items);
        self::assertSame(5, $this->session($again->id())->get('user'));
        self::assertTrue($this->session($oldId)->isNew(), 'old id must not resume the session');
    }

    public function testIdleTimeoutEndsSession(): void
    {
        $first = $this->session(null, 100);
        $first->set('user', 5);
        $first->save();

        $this->clock->advance(101);

        self::assertNull($this->session($first->id(), 100)->get('user'));
    }

    public function testActivityExtendsIdleButNotAbsoluteTimeout(): void
    {
        $first = $this->session(null, 100, 250);
        $first->set('user', 5);
        $first->save();

        $this->clock->advance(90);
        $a = $this->session($first->id(), 100, 250);
        self::assertSame(5, $a->get('user'));
        $a->save();

        $this->clock->advance(90);
        $b = $this->session($first->id(), 100, 250);
        self::assertSame(5, $b->get('user'));
        $b->save();

        $this->clock->advance(90); // 270 s since creation > absolute 250
        self::assertNull($this->session($first->id(), 100, 250)->get('user'));
    }

    public function testInvalidateDestroysSession(): void
    {
        $first = $this->session();
        $first->set('user', 5);
        $first->save();
        $second = $this->session($first->id());

        $second->invalidate();
        $second->save();

        self::assertTrue($second->wasDestroyed());
        self::assertSame([], $this->store->items);
        self::assertFalse($second->needsCookie());
    }

    public function testCsrfTokenIsStableAndChecked(): void
    {
        $csrf = new Csrf();
        $session = $this->session();
        $token = $csrf->token($session);

        self::assertSame($token, $csrf->token($session));
        $good = Request::create('POST', '/', body: ['_token' => $token], headers: ['Host' => 'app.test']);
        $header = Request::create('POST', '/', headers: ['X-CSRF-Token' => $token, 'Host' => 'app.test']);
        $bad = Request::create('POST', '/', body: ['_token' => 'nope'], headers: ['Host' => 'app.test']);
        $none = Request::create('POST', '/', headers: ['Host' => 'app.test']);

        self::assertTrue($csrf->isValid($good, $session));
        self::assertTrue($csrf->isValid($header, $session));
        self::assertFalse($csrf->isValid($bad, $session));
        self::assertFalse($csrf->isValid($none, $session));
    }

    public function testCsrfRejectsForeignOriginAndCrossSiteFetch(): void
    {
        $csrf = new Csrf();
        $session = $this->session();
        $token = $csrf->token($session);
        $headers = static fn (array $extra): array => ['Host' => 'app.test', ...$extra];

        $sameOrigin = Request::create('POST', '/', body: ['_token' => $token], headers: $headers(['Origin' => 'https://app.test']));
        $foreign = Request::create('POST', '/', body: ['_token' => $token], headers: $headers(['Origin' => 'https://evil.example']));
        $nullOrigin = Request::create('POST', '/', body: ['_token' => $token], headers: $headers(['Origin' => 'null']));
        $crossSite = Request::create('POST', '/', body: ['_token' => $token], headers: $headers(['Sec-Fetch-Site' => 'cross-site']));

        self::assertTrue($csrf->isValid($sameOrigin, $session));
        self::assertFalse($csrf->isValid($foreign, $session));
        self::assertFalse($csrf->isValid($nullOrigin, $session));
        self::assertFalse($csrf->isValid($crossSite, $session));
    }
}
