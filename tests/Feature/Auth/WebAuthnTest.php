<?php

declare(strict_types=1);

namespace App\Tests\Feature\Auth;

use App\Domain\Auth\TwoFactorService;
use App\Domain\Auth\WebAuthnService;
use App\Domain\User\User;
use App\Domain\User\UserRepository;
use App\Kernel\Exception\HttpException;
use App\Tests\Support\AuthTestCase;

/** Synthetic P-256 authenticator exercises real library attestation/signatures, one-use challenge and account binding. */
final class WebAuthnTest extends AuthTestCase
{
    private function enabledUser(): User
    {
        $user = $this->createUser();
        $two = $this->app->container()->get(TwoFactorService::class);
        $two->beginSetup($user);
        $user = $this->app->container()->get(UserRepository::class)->find($user->id);
        self::assertNotNull($user);
        self::assertNotNull($two->confirmSetup($user, $this->totpCode($user->id)));
        return $this->app->container()->get(UserRepository::class)->find($user->id) ?? throw new \LogicException();
    }

    private function encoded(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** Minimal CBOR byte/text string fixture encoder; never used by production code. */
    private function cbor(string $bytes, int $major = 2): string
    {
        $length = strlen($bytes);
        return chr(($major << 5) | ($length < 24 ? $length : ($length < 256 ? 24 : 25)))
            . ($length < 24 ? '' : ($length < 256 ? chr($length) : pack('n', $length))) . $bytes;
    }

    /** @return array{\OpenSSLAsymmetricKey,string} */
    private function enroll(WebAuthnService $service, User $user): array
    {
        $options = $service->options($user, 'synthetic-session', true);
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($key);
        $details = openssl_pkey_get_details($key);
        self::assertNotFalse($details);
        $id = random_bytes(16);
        $cose = "\xa5\x01\x02\x03\x26\x20\x01\x21" . $this->cbor($details['ec']['x']) . "\x22" . $this->cbor($details['ec']['y']);
        $auth = hash('sha256', 'localhost', true) . "\x45" . pack('N', 1) . str_repeat("\0", 16) . pack('n', strlen($id)) . $id . $cose;
        $attestation = "\xa3" . $this->cbor('fmt', 3) . $this->cbor('none', 3) . $this->cbor('attStmt', 3) . "\xa0" . $this->cbor('authData', 3) . $this->cbor($auth);
        $client = json_encode(['type' => 'webauthn.create', 'origin' => 'http://localhost', 'challenge' => $options['publicKey']['challenge']], JSON_THROW_ON_ERROR);
        $service->register($user, 'synthetic-session', ['clientDataJSON' => $this->encoded($client), 'attestationObject' => $this->encoded($attestation)], 'Synthetic key');
        return [$key, $id];
    }

    public function testRealSignatureAndChallengeReplayCounterRevocationAndWrongAccount(): void
    {
        $user = $this->enabledUser();
        $service = $this->app->container()->get(WebAuthnService::class);
        [$key, $id] = $this->enroll($service, $user);
        self::assertCount(1, $service->credentials($user));
        $options = $service->options($user, 'synthetic-session', false);
        $client = json_encode(['type' => 'webauthn.get', 'origin' => 'http://localhost', 'challenge' => $options['publicKey']['challenge']], JSON_THROW_ON_ERROR);
        $auth = hash('sha256', 'localhost', true) . "\x05" . pack('N', 2);
        self::assertTrue(openssl_sign($auth . hash('sha256', $client, true), $signature, $key, OPENSSL_ALGO_SHA256));
        $payload = ['rawId' => $this->encoded($id), 'clientDataJSON' => $this->encoded($client), 'authenticatorData' => $this->encoded($auth), 'signature' => $this->encoded($signature)];
        self::assertFalse($service->verify($user, 'wrong-session', $payload));
        self::assertTrue($service->verify($user, 'synthetic-session', $payload));
        self::assertFalse($service->verify($user, 'synthetic-session', $payload));
        self::assertSame(2, (int) $this->db->select('SELECT sign_count FROM webauthn_credentials WHERE user_id = ?', [$user->id])[0]['sign_count']);
        $other = $this->createUser('other@example.com');
        self::assertSame([], $service->credentials($other));
        $service->revoke($other, $service->credentials($user)[0]['id']);
        self::assertCount(1, $service->credentials($user));
        $this->app->container()->get(TwoFactorService::class)->disable($user);
        self::assertSame([], $service->credentials($user));
    }

    public function testExpiredChallengeAndExactOriginAreRejected(): void
    {
        $user = $this->enabledUser();
        $service = $this->app->container()->get(WebAuthnService::class);
        $options = $service->options($user, 'synthetic-session', true);
        $this->clock->advance(121);
        $payload = ['clientDataJSON' => $this->encoded(json_encode(['type' => 'webauthn.create', 'origin' => 'http://localhost', 'challenge' => $options['publicKey']['challenge']], JSON_THROW_ON_ERROR)), 'attestationObject' => 'AA'];
        try {
            $service->register($user, 'synthetic-session', $payload, 'fixture');
            self::fail('Expired challenge must fail.');
        } catch (HttpException $e) {
            self::assertSame(422, $e->status);
        }
        $options = $service->options($user, 'synthetic-session', true);
        $payload['clientDataJSON'] = $this->encoded(json_encode(['type' => 'webauthn.create', 'origin' => 'https://evil-localhost', 'challenge' => $options['publicKey']['challenge']], JSON_THROW_ON_ERROR));
        $this->expectException(HttpException::class);
        $service->register($user, 'synthetic-session', $payload, 'fixture');
    }

    public function testRateLimiterOutageBlocksHardwareDispatch(): void
    {
        $this->enabledUser();
        $redis = $this->createMock(\Redis::class);
        $redis->method('isConnected')->willReturn(false);
        $this->app->container()->instance(\App\Kernel\Security\RateLimiter::class, new \App\Kernel\Security\RateLimiter($redis, $this->clock));
        $this->signIn();
        self::assertSame(503, $this->post('/login/2fa/webauthn/options')->status);
        self::assertSame([], $this->db->select('SELECT id FROM webauthn_challenges'));
    }

    public function testAnonymousCannotEnrollOrSkipPasswordStep(): void
    {
        self::assertSame('/login', $this->post('/account/webauthn/begin')->header('Location'));
        self::assertSame(403, $this->post('/login/2fa/webauthn/options')->status);
        self::assertSame(403, $this->post('/login/2fa/webauthn/verify')->status);
    }
}
