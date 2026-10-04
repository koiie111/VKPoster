<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Integrations\OAuth\FakeProvider;
use App\Integrations\OAuth\Pkce;
use App\Kernel\Http\Response;

/**
 * Base class for social sign-in feature tests, driven through the built-in fake provider: starts a flow,
 * answers the callback with a profile of the test's choosing, and reads back what the server decided.
 */
abstract class SocialTestCase extends AuthTestCase
{
    /**
     * @return array{id: string, email: ?string, email_verified: bool, name: string, avatar: null}
     */
    protected function profile(string $id = 'fake-1', ?string $email = 'ivan@example.com', bool $verified = true, string $name = 'Иван Петров'): array
    {
        return ['id' => $id, 'email' => $email, 'email_verified' => $verified, 'name' => $name, 'avatar' => null];
    }

    /**
     * Start a sign-in at the fake provider and return what the authorization URL carried.
     *
     * @return array{state: string, challenge: string, nonce: string}
     */
    protected function startLogin(string $query = ''): array
    {
        return $this->flowFrom($this->get('/auth/fake/redirect' . $query));
    }

    /**
     * Start linking from a signed-in session.
     *
     * @return array{state: string, challenge: string, nonce: string}
     */
    protected function startLink(): array
    {
        return $this->flowFrom($this->post('/account/login-methods/fake/link'));
    }

    /**
     * @return array{state: string, challenge: string, nonce: string}
     */
    protected function flowFrom(Response $response): array
    {
        self::assertSame(302, $response->status);
        $location = (string) $response->header('Location');
        self::assertStringStartsWith('http://localhost/dev/oauth/fake?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        self::assertIsString($query['state'] ?? null);
        self::assertIsString($query['challenge'] ?? null);
        self::assertIsString($query['nonce'] ?? null);

        return ['state' => $query['state'], 'challenge' => $query['challenge'], 'nonce' => $query['nonce']];
    }

    /**
     * Answer the callback as the fake provider would.
     *
     * @param array{state: string, challenge: string, nonce: string} $flow
     * @param array<string, mixed> $profile
     */
    protected function answer(array $flow, array $profile, ?string $state = null, ?string $challenge = null): Response
    {
        $code = FakeProvider::makeCode($profile, $challenge ?? $flow['challenge'], $flow['nonce']);

        return $this->get('/auth/fake/callback?' . http_build_query(['code' => $code, 'state' => $state ?? $flow['state']]));
    }

    /**
     * Run the whole sign-in with a profile and follow nothing: returns the callback response.
     *
     * @param array<string, mixed> $profile
     */
    protected function socialLogin(array $profile): Response
    {
        return $this->answer($this->startLogin(), $profile);
    }

    protected function consent(string $name = 'Иван Петров', bool $agree = true): Response
    {
        $body = ['name' => $name];
        if ($agree) {
            $body['consent'] = '1';
        }

        return $this->post('/auth/social/consent', $body);
    }

    /**
     * Sign up a brand-new account through the fake provider and leave the browser signed in.
     *
     * @param array<string, mixed> $profile
     */
    protected function registerViaSocial(array $profile): void
    {
        $response = $this->socialLogin($profile);
        self::assertSame('/auth/social/consent', $response->header('Location'));
        $done = $this->consent();
        self::assertSame('/app', $done->header('Location'));
    }

    protected function challengeFor(string $verifier): string
    {
        return Pkce::challengeFor($verifier);
    }

    protected function userCount(): int
    {
        return (int) $this->db->select('SELECT COUNT(*) AS c FROM users')[0]['c'];
    }

    /**
     * @return list<string> providers linked to a user
     */
    protected function linkedProviders(int $userId): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['provider'],
            $this->db->select('SELECT provider FROM user_identities WHERE user_id = ? ORDER BY provider', [$userId]),
        );
    }
}
