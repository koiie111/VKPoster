<?php

declare(strict_types=1);

namespace App\Tests\Unit\Kernel;

use App\Kernel\Exception\CryptoException;
use App\Kernel\HttpClient\HttpClientInterface;
use App\Kernel\Security\Crypto;
use App\Kernel\Security\VaultTransit;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/** Transit policy/HTTP contracts exercise isolated operations without any real credential or server. */
final class VaultTransitTest extends TestCase
{
    public function testNonExportableKeyOperationsAndRotation(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'vault-test-');
        self::assertNotFalse($file);
        file_put_contents($file, 'synthetic-test-token-not-real');
        chmod($file, 0600);
        $calls = [];
        $http = $this->createMock(HttpClientInterface::class);
        $http->method('request')->willReturnCallback(static function (string $method, string $url, array $options) use (&$calls): Response {
            $calls[] = [$method, $url, $options];
            $data = match (true) {
                str_contains($url, '/keys/') => ['type' => 'aes256-gcm96', 'derived' => true, 'exportable' => false, 'allow_plaintext_backup' => false, 'convergent_encryption' => false, 'latest_version' => 2],
                str_contains($url, '/encrypt/') => ['ciphertext' => 'vault:v2:Zml4dHVyZQ=='],
                str_contains($url, '/decrypt/') => ['plaintext' => base64_encode('synthetic secret')],
                default => ['hmac' => 'vault:v2:' . str_repeat('ab', 32)],
            };
            return new Response(200, [], json_encode(['data' => $data], JSON_THROW_ON_ERROR));
        });
        try {
            $transit = new VaultTransit($http, 'https://vault.internal.test', 'vkposter', $file);
            $crypto = new Crypto('isolated', [], $transit);
            $cipher = $crypto->encrypt('synthetic secret');
            self::assertSame('v3:application:vault:v2:Zml4dHVyZQ==', $cipher);
            self::assertSame('synthetic secret', $crypto->decrypt($cipher));
            self::assertFalse($crypto->needsRotation($cipher));
            self::assertTrue($crypto->needsRotation('v3:application:vault:v1:Zml4dHVyZQ=='));
            self::assertSame(hex2bin(str_repeat('ab', 32)), $crypto->mac('signer', 'fixture'));
            self::assertCount(5, $calls);
            foreach ($calls as $call) {
                self::assertFalse($call[2]['allow_redirects']);
                self::assertTrue($call[2]['verify']);
                self::assertSame(5, $call[2]['timeout']);
            }
            $this->expectException(CryptoException::class);
            $crypto->deriveKey('export');
        } finally {
            unlink($file);
        }
    }

    public function testUnsafePolicyFailsClosedBeforeEncryption(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'vault-test-');
        self::assertNotFalse($file);
        file_put_contents($file, 'synthetic-test-token-not-real');
        chmod($file, 0600);
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::once())->method('request')->willReturn(new Response(200, [], '{"data":{"type":"aes256-gcm96","derived":true,"exportable":true}}'));
        try {
            $transit = new VaultTransit($http, 'https://vault.internal.test', 'vkposter', $file);
            $this->expectException(CryptoException::class);
            $transit->encrypt('fixture');
        } finally {
            unlink($file);
        }
    }

    public function testProviderFailureDoesNotExposePayloadOrRetryOrFallback(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'vault-test-');
        self::assertNotFalse($file);
        file_put_contents($file, 'synthetic-test-token-not-real');
        chmod($file, 0600);
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::once())->method('request')->willThrowException(new \RuntimeException('synthetic-test-token-not-real private payload'));
        try {
            (new VaultTransit($http, 'https://vault.internal.test', 'vkposter', $file))->encrypt('private payload');
            self::fail('Unavailable service must fail closed.');
        } catch (CryptoException $error) {
            self::assertSame('Isolated cryptographic service unavailable.', $error->getMessage());
            self::assertNull($error->getPrevious());
        } finally {
            unlink($file);
        }
    }
}
