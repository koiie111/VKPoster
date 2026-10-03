<?php

declare(strict_types=1);

namespace App\Tests\Unit\Kernel;

use App\Kernel\Exception\CryptoException;
use App\Kernel\Security\Crypto;
use App\Kernel\Security\PasswordHasher;
use App\Kernel\Security\Signer;
use App\Tests\Support\FakeClock;
use App\Tests\Support\TestEnv;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Encryption round trip, key rotation, tamper detection, signing and password hashing.
 */
#[CoversClass(Crypto::class)]
#[CoversClass(Signer::class)]
#[CoversClass(PasswordHasher::class)]
final class CryptoTest extends TestCase
{
    private function key(int $seed): string
    {
        return base64_encode(str_repeat(chr($seed), 32));
    }

    public function testRoundTripAndFormat(): void
    {
        $crypto = new Crypto('k1', ['k1' => $this->key(1)]);

        $cipher = $crypto->encrypt('secret token');

        self::assertMatchesRegularExpression('#^v1:k1:[A-Za-z0-9+/]+=*$#', $cipher);
        self::assertStringNotContainsString('secret', $cipher);
        self::assertSame('secret token', $crypto->decrypt($cipher));
        self::assertNotSame($cipher, $crypto->encrypt('secret token'), 'nonce must be random');
    }

    public function testEmptyStringRoundTrips(): void
    {
        $crypto = new Crypto('k1', ['k1' => $this->key(1)]);

        self::assertSame('', $crypto->decrypt($crypto->encrypt('')));
    }

    public function testRotationKeepsOldDataReadable(): void
    {
        $old = new Crypto('k1', ['k1' => $this->key(1)]);
        $legacy = $old->encrypt('data');

        $rotated = new Crypto('k2', ['k2' => $this->key(2), 'k1' => $this->key(1)]);

        self::assertSame('data', $rotated->decrypt($legacy));
        self::assertTrue($rotated->needsRotation($legacy));
        $fresh = $rotated->rotate($legacy);
        self::assertStringStartsWith('v1:k2:', $fresh);
        self::assertFalse($rotated->needsRotation($fresh));
        self::assertSame('data', $rotated->decrypt($fresh));
    }

    public function testTamperedCiphertextIsRejected(): void
    {
        $crypto = new Crypto('k1', ['k1' => $this->key(1)]);
        $cipher = $crypto->encrypt('data');
        [$v, $id, $b64] = explode(':', $cipher, 3);
        $raw = (string) base64_decode($b64, true);
        $raw[strlen($raw) - 1] = $raw[strlen($raw) - 1] ^ "\x01";

        $this->expectException(CryptoException::class);
        $crypto->decrypt($v . ':' . $id . ':' . base64_encode($raw));
    }

    public function testWrongKeyIsRejected(): void
    {
        $cipher = (new Crypto('k1', ['k1' => $this->key(1)]))->encrypt('data');

        $this->expectException(CryptoException::class);
        (new Crypto('k1', ['k1' => $this->key(9)]))->decrypt($cipher);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function garbage(): array
    {
        return ['empty' => [''], 'wrong version' => ['v2:k1:AAAA'], 'unknown key' => ['v1:zz:AAAA'], 'short' => ['v1:k1:AAAA'], 'not base64' => ['v1:k1:***']];
    }

    #[DataProvider('garbage')]
    public function testMalformedPayloadsThrow(string $payload): void
    {
        $crypto = new Crypto('k1', ['k1' => $this->key(1)]);

        $this->expectException(CryptoException::class);
        $crypto->decrypt($payload);
    }

    public function testInvalidKeyConfigurationIsRejected(): void
    {
        $this->expectException(CryptoException::class);
        new Crypto('k1', ['k1' => base64_encode('too short')]);
    }

    public function testCurrentKeyMustBeConfigured(): void
    {
        $this->expectException(CryptoException::class);
        new Crypto('k2', ['k1' => $this->key(1)]);
    }

    public function testSignerSignsAndVerifies(): void
    {
        $signer = new Signer(new Crypto('k1', ['k1' => TestEnv::KEY]), new FakeClock());

        $sig = $signer->sign('payload');

        self::assertTrue($signer->verify('payload', $sig));
        self::assertFalse($signer->verify('payload2', $sig));
        self::assertFalse($signer->verify('payload', $sig . 'x'));
    }

    public function testSignedUrlsDetectTamperingAndExpiry(): void
    {
        $clock = new FakeClock();
        $signer = new Signer(new Crypto('k1', ['k1' => TestEnv::KEY]), $clock);

        $url = $signer->signUrl('/media/abc?size=small', 60);

        self::assertTrue($signer->verifyUrl($url));
        self::assertFalse($signer->verifyUrl(str_replace('abc', 'abd', $url)));
        self::assertFalse($signer->verifyUrl('/media/abc?size=small'));
        $clock->advance(61);
        self::assertFalse($signer->verifyUrl($url));
    }

    public function testSignedUrlWithoutExpiryNeverExpires(): void
    {
        $clock = new FakeClock();
        $signer = new Signer(new Crypto('k1', ['k1' => TestEnv::KEY]), $clock);
        $url = $signer->signUrl('/x');
        $clock->advance(10 ** 8);

        self::assertTrue($signer->verifyUrl($url));
    }

    public function testPasswordHasherUsesArgon2id(): void
    {
        $hasher = new PasswordHasher(1024, 1);

        $hash = $hasher->hash('correct horse');

        self::assertStringStartsWith('$argon2id$', $hash);
        self::assertTrue($hasher->verify('correct horse', $hash));
        self::assertFalse($hasher->verify('wrong', $hash));
        self::assertFalse($hasher->needsRehash($hash));
        self::assertTrue((new PasswordHasher(2048, 2))->needsRehash($hash));
    }
}
