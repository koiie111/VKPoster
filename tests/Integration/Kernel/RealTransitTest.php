<?php

declare(strict_types=1);

namespace App\Tests\Integration\Kernel;

use App\Kernel\Security\Crypto;
use App\Kernel\Security\VaultTransit;
use App\Kernel\Security\PasswordHasher;
use App\Kernel\HttpClient\GuzzleHttpClient;
use App\Kernel\HttpClient\SsrfGuard;
use PHPUnit\Framework\TestCase;

/** Opt-in real TLS OpenBao fixture only, provisioned by scripts/security/crypto-stand.py. */
final class RealTransitTest extends TestCase
{
    public function testPurposeSeparationRotationPepperAndNoKeyExport(): void
    {
        $url = getenv('CRYPTO_FIXTURE_URL');
        if (!is_string($url) || preg_match('/^https:\/\/stage19-bao-[a-f0-9]{10}:8200$/D', $url) !== 1) {
            self::markTestSkipped('Run the disposable crypto stand for actual Transit integration.');
        }
        $http = new GuzzleHttpClient(new SsrfGuard());
        $backend = new VaultTransit($http, $url, 'vkposter', '/fixture/app.token', '/fixture/ca.pem');
        $crypto = new Crypto('isolated', [], $backend);
        $app = $crypto->encrypt('synthetic credential');
        $webhook = $crypto->encrypt('synthetic webhook', 'webhook');
        self::assertSame('synthetic credential', $crypto->decrypt($app));
        self::assertSame('synthetic webhook', $crypto->decrypt($webhook, 'webhook'));
        try {
            $crypto->decrypt($webhook);
            self::fail('Cross-purpose ciphertext accepted.');
        } catch (\App\Kernel\Exception\CryptoException) {
            self::assertStringStartsWith('v3:webhook:', $webhook);
        }
        $link = (new \App\Kernel\Security\Signer($crypto, new \App\Tests\Support\FakeClock()))->signUrl('/media/synthetic', 300);
        $signature = $crypto->signature('pdf', 'synthetic report');
        self::assertTrue($crypto->verifySignature('pdf', 'synthetic report', $signature));
        self::assertFalse($crypto->verifySignature('pdf', 'forged report', $signature));
        self::assertFalse($crypto->verifySignature('signer', 'synthetic report', $signature));
        $pepper = new PasswordHasher(1024, 1, 1, isolated: $backend);
        $hash = $pepper->hash('synthetic password');
        self::assertTrue($pepper->verify('synthetic password', $hash));
        self::assertFalse($pepper->verify('wrong password', $hash));
        self::assertFalse($pepper->needsRehash($hash));
        // A separate fixture-only operator capability. The app token cannot rotate/export keys.
        $operator = trim((string) file_get_contents('/fixture/rotate.token'));
        foreach (['app', 'webhook', 'pdf', 'password', 'links'] as $purpose) {
            $r = $http->request('POST', $url . '/v1/transit/keys/vkposter-' . $purpose . '/rotate', [
                'headers' => ['X-Vault-Token' => $operator], 'verify' => '/fixture/ca.pem', 'http_errors' => false,
            ]);
            self::assertContains($r->getStatusCode(), [200, 204]);
        }
        $after = new VaultTransit($http, $url, 'vkposter', '/fixture/app.token', '/fixture/ca.pem');
        $rotated = new Crypto('isolated', [], $after);
        self::assertTrue($rotated->needsRotation($app));
        $rewrapped = $rotated->rotate($app);
        self::assertFalse($rotated->needsRotation($rewrapped));
        self::assertSame('synthetic credential', $rotated->decrypt($rewrapped));
        self::assertSame('synthetic credential', $rotated->decrypt($app));
        self::assertTrue($rotated->verifySignature('pdf', 'synthetic report', $signature));
        $newSigner = new \App\Kernel\Security\Signer($rotated, new \App\Tests\Support\FakeClock());
        self::assertTrue($newSigner->verifyUrl($link));
        self::assertFalse($newSigner->verifyUrl(str_replace('synthetic', 'forged', $link)));
        $newPepper = new PasswordHasher(1024, 1, 1, isolated: $after);
        self::assertTrue($newPepper->verify('synthetic password', $hash));
        self::assertTrue($newPepper->needsRehash($hash));
        self::assertSame('synthetic webhook', $rotated->decrypt($rotated->rotate($webhook, 'webhook'), 'webhook'));
        $appToken = trim((string) file_get_contents('/fixture/app.token'));
        $pdfToken = trim((string) file_get_contents('/fixture/pdf.token'));
        foreach (['transit/export/encryption-key/vkposter-app', 'transit/export/hmac-key/vkposter-pdf'] as $path) {
            $r = $http->request('GET', $url . '/v1/' . $path, ['headers' => ['X-Vault-Token' => $appToken], 'verify' => '/fixture/ca.pem']);
            self::assertSame(403, $r->getStatusCode());
        }
        $r = $http->request('POST', $url . '/v1/transit/verify/vkposter-pdf/sha2-256', [
            'headers' => ['X-Vault-Token' => $pdfToken], 'verify' => '/fixture/ca.pem',
            'json' => ['input' => base64_encode('synthetic report'), 'hmac' => $signature, 'context' => base64_encode('vkposter:pdf')],
        ]);
        self::assertSame(200, $r->getStatusCode());
        self::assertTrue(json_decode((string) $r->getBody(), true, 8, JSON_THROW_ON_ERROR)['data']['valid']);
        $r = $http->request('POST', $url . '/v1/transit/hmac/vkposter-pdf/sha2-256', [
            'headers' => ['X-Vault-Token' => $pdfToken], 'verify' => '/fixture/ca.pem', 'json' => ['input' => base64_encode('synthetic report')],
        ]);
        self::assertSame(403, $r->getStatusCode(), 'Renderer cannot create signatures.');
        foreach ([2, 1] as $minimum) {
            $r = $http->request('POST', $url . '/v1/transit/keys/vkposter-app/config', [
                'headers' => ['X-Vault-Token' => $operator], 'verify' => '/fixture/ca.pem', 'json' => ['min_decryption_version' => $minimum],
            ]);
            self::assertContains($r->getStatusCode(), [200, 204]);
            self::assertSame('synthetic credential', $rotated->decrypt($rewrapped));
            if ($minimum === 2) {
                try {
                    $rotated->decrypt($app);
                    self::fail('Retired key version accepted.');
                } catch (\App\Kernel\Exception\CryptoException $error) {
                    self::assertNull($error->getPrevious());
                }
            } else {
                self::assertSame('synthetic credential', $rotated->decrypt($app), 'Reviewed recovery restores old-version access.');
            }
        }
        $legacyBackend = new VaultTransit($http, $url, 'vkposter', '/fixture/app.token', '/fixture/ca.pem', 'vkposter-legacy');
        $legacyCrypto = new Crypto('isolated', [], $legacyBackend);
        $v2 = 'v2:historical-alias:' . trim((string) file_get_contents('/fixture/legacy.cipher'));
        self::assertSame('synthetic legacy value', $legacyCrypto->decrypt($v2));
        self::assertSame('synthetic legacy value', $rotated->decrypt($legacyCrypto->rotate($v2)));
        self::assertSame('synthetic legacy value', $rotated->decrypt($legacyCrypto->rotate($v2, 'webhook'), 'webhook'));
        $this->expectException(\App\Kernel\Exception\CryptoException::class);
        $rotated->deriveKey('export');
    }
}
