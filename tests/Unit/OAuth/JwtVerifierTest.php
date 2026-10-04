<?php

declare(strict_types=1);

namespace App\Tests\Unit\OAuth;

use App\Integrations\OAuth\JwtVerifier;
use App\Integrations\OAuth\OAuthException;
use App\Integrations\OAuth\Pkce;
use App\Tests\Support\FakeClock;
use App\Tests\Support\JwtFactory;
use App\Tests\Support\MockHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JwtVerifier::class)]
final class JwtVerifierTest extends TestCase
{
    private const JWKS = 'https://example.test/jwks';
    private const AUD = 'client-id';

    private JwtFactory $factory;
    private FakeClock $clock;
    private MockHttpClient $http;
    private JwtVerifier $verifier;

    protected function setUp(): void
    {
        $this->factory = new JwtFactory();
        $this->clock = new FakeClock('2026-10-04 12:00:00');
        $this->http = new MockHttpClient();
        $this->verifier = new JwtVerifier($this->http, $this->clock);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function claims(array $overrides = []): array
    {
        $now = $this->clock->now()->getTimestamp();

        return $overrides + ['iss' => 'https://accounts.google.com', 'aud' => self::AUD, 'sub' => '1', 'nonce' => 'n-1', 'iat' => $now, 'exp' => $now + 3600];
    }

    /**
     * @return array<string, mixed>
     */
    private function verify(string $jwt, string $nonce = 'n-1', ?string $jwks = null): array
    {
        $this->http->expect('GET', self::JWKS, 200, $jwks ?? $this->factory->jwks());

        return $this->verifier->verify($jwt, self::JWKS, ['https://accounts.google.com', 'accounts.google.com'], self::AUD, $nonce);
    }

    private function assertRejected(string $jwt, string $nonce = 'n-1', ?string $jwks = null): void
    {
        try {
            $this->verify($jwt, $nonce, $jwks);
            self::fail('token was accepted');
        } catch (OAuthException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testValidTokenReturnsItsClaims(): void
    {
        $claims = $this->verify($this->factory->token($this->claims()));

        self::assertSame('1', $claims['sub']);
        $this->http->assertAllConsumed();
    }

    public function testAcceptsTheOtherGoogleIssuerSpellingAndAudienceLists(): void
    {
        $token = $this->factory->token($this->claims(['iss' => 'accounts.google.com', 'aud' => ['other', self::AUD]]));

        self::assertSame('1', $this->verify($token)['sub']);
    }

    public function testBadSignatureIsRejected(): void
    {
        $token = $this->factory->token($this->claims());
        [$h, $p, $s] = explode('.', $token);
        $forged = $h . '.' . Pkce::base64Url((string) json_encode($this->claims(['sub' => 'attacker']))) . '.' . $s;

        $this->assertRejected($forged);
    }

    public function testTokenSignedWithAnotherKeyIsRejected(): void
    {
        $other = new JwtFactory($this->factory->kid);

        $this->assertRejected($other->token($this->claims()));
    }

    public function testUnknownKeyIdIsRejected(): void
    {
        $this->assertRejected($this->factory->token($this->claims(), ['kid' => 'nope']));
    }

    public function testOnlyRs256IsAccepted(): void
    {
        foreach (['none', 'HS256', 'RS512', 'ES256'] as $alg) {
            $this->assertRejected($this->factory->token($this->claims(), ['alg' => $alg]));
        }
    }

    public function testUnsignedTokenIsRejected(): void
    {
        $unsigned = Pkce::base64Url('{"alg":"none"}') . '.' . Pkce::base64Url((string) json_encode($this->claims())) . '.';

        $this->assertRejected($unsigned);
    }

    public function testWrongIssuerIsRejected(): void
    {
        $this->assertRejected($this->factory->token($this->claims(['iss' => 'https://evil.example'])));
    }

    public function testWrongAudienceIsRejected(): void
    {
        $this->assertRejected($this->factory->token($this->claims(['aud' => 'someone-else'])));
        $this->assertRejected($this->factory->token($this->claims(['aud' => ['a', 'b']])));
    }

    public function testExpiredTokenIsRejectedButSmallClockSkewIsTolerated(): void
    {
        $now = $this->clock->now()->getTimestamp();

        $this->assertRejected($this->factory->token($this->claims(['exp' => $now - 120])));
        self::assertSame('1', $this->verify($this->factory->token($this->claims(['exp' => $now - 30])))['sub']);
        $this->assertRejected($this->factory->token($this->claims(['exp' => 'tomorrow'])));
    }

    public function testTokenFromTheFutureIsRejected(): void
    {
        $this->assertRejected($this->factory->token($this->claims(['iat' => $this->clock->now()->getTimestamp() + 3600])));
    }

    public function testNonceMustMatch(): void
    {
        $this->assertRejected($this->factory->token($this->claims()), 'another-nonce');
        $this->assertRejected($this->factory->token($this->claims(['nonce' => null])));
        $this->assertRejected($this->factory->token($this->claims()), '');
    }

    public function testMalformedTokensAreRejected(): void
    {
        foreach (['', 'a.b', 'a.b.c.d', '!!!.???.***'] as $junk) {
            try {
                $this->verifier->verify($junk, self::JWKS, ['x'], self::AUD, 'n');
                self::fail('accepted ' . $junk);
            } catch (OAuthException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testMissingKeyDocumentIsRejected(): void
    {
        $this->assertRejected($this->factory->token($this->claims()), 'n-1', '{"keys":[]}');
        $this->assertRejected($this->factory->token($this->claims()), 'n-1', 'not json');
    }

    public function testPemIsAValidPublicKey(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        self::assertNotFalse($key);
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);

        $pem = JwtVerifier::pem($details['rsa']['n'], $details['rsa']['e']);

        $loaded = openssl_pkey_get_public($pem);
        self::assertNotFalse($loaded);
        $parsed = openssl_pkey_get_details($loaded);
        self::assertIsArray($parsed);
        self::assertSame($details['rsa']['n'], $parsed['rsa']['n']);
        self::assertSame($details['rsa']['e'], $parsed['rsa']['e']);
    }
}
