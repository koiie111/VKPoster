<?php

declare(strict_types=1);

namespace App\Tests\Unit\Kernel;

use App\Kernel\Log\LoggerFactory;
use App\Kernel\Log\SecretRedactor;
use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Secrets must never reach log output.
 */
#[CoversClass(SecretRedactor::class)]
#[CoversClass(LoggerFactory::class)]
final class SecretRedactorTest extends TestCase
{
    public function testSensitiveKeysAreMaskedRecursively(): void
    {
        $redactor = new SecretRedactor();
        $record = new LogRecord(new DateTimeImmutable(), 'app', Level::Info, 'msg', [
            'password' => 'p',
            'new_password' => 'p2',
            'access_token' => 't',
            'Authorization' => 'Bearer x',
            'client_secret' => 's',
            'code' => '123456',
            'oauth_code' => 'abc',
            'nested' => ['api_key' => 'k', 'user' => 'ann', 'deeper' => ['Cookie' => 'sid=1']],
            'user_id' => 7,
            'error_code' => 'E42',
            'status_code' => 500,
        ]);

        $context = $redactor($record)->context;

        foreach (['password', 'new_password', 'access_token', 'Authorization', 'client_secret', 'code', 'oauth_code'] as $key) {
            self::assertSame(SecretRedactor::MASK, $context[$key], $key);
        }
        self::assertIsArray($context['nested']);
        self::assertSame(SecretRedactor::MASK, $context['nested']['api_key']);
        self::assertSame('ann', $context['nested']['user']);
        self::assertIsArray($context['nested']['deeper']);
        self::assertSame(SecretRedactor::MASK, $context['nested']['deeper']['Cookie']);
        self::assertSame(7, $context['user_id']);
        self::assertSame('E42', $context['error_code']);
        self::assertSame(500, $context['status_code']);
    }

    public function testProductionKeyringAndDeepSecretsAreMasked(): void
    {
        $redactor = new SecretRedactor();
        $data = ['APP_KEYS' => 'private', 'APP_KEY' => 'private', 'dsn' => 'private', 'Authorization' => 'private', 'nested' => ['payment_secret' => 'private']];
        self::assertStringNotContainsString('private', json_encode($redactor->redact($data), JSON_THROW_ON_ERROR));
        $record = new LogRecord(new DateTimeImmutable(), 'app', Level::Error, 'Authorization: Bearer synthetic-credential');
        self::assertStringNotContainsString('synthetic-credential', $redactor($record)->message);
    }

    public function testExceptionAndPayloadCannotLeakSecrets(): void
    {
        $redactor = new SecretRedactor();
        $data = $redactor->redact(['exception' => new \RuntimeException('private-password'), 'payload' => 'private-body', 'SENTRY_DSN' => 'private-dsn']);
        self::assertStringNotContainsString('private', json_encode($data, JSON_THROW_ON_ERROR));
        self::assertIsArray($data['exception']);
        self::assertSame(\RuntimeException::class, $data['exception']['class']);
        self::assertCount(50, $redactor->redact(array_fill(0, 200, 'bounded')));
    }

    public function testLoggerWritesJsonWithoutSecrets(): void
    {
        $dir = sys_get_temp_dir() . '/vkposter-log-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $logger = LoggerFactory::create($dir, 'info', false);

        $logger->info('login {user}', ['user' => 'ann', 'password' => 'hunter2', 'token' => 'abc']);

        $line = (string) file_get_contents($dir . '/app-' . date('Y-m-d') . '.log');
        $decoded = json_decode($line, true);
        self::assertIsArray($decoded);
        self::assertSame('login ann', $decoded['message']);
        self::assertStringNotContainsString('hunter2', $line);
        self::assertStringNotContainsString('abc', $line);
        self::assertStringContainsString(SecretRedactor::MASK, $line);
        unlink($dir . '/app-' . date('Y-m-d') . '.log');
        rmdir($dir);
    }

    public function testCapabilityPathsAndOauthQueriesAreMasked(): void
    {
        $redactor = new SecretRedactor();
        $token = str_repeat('x', 43);
        foreach (['password/reset', 'email/verify', 'email/change', 'invitations'] as $path) {
            $data = $redactor->redact(['uri' => '/' . $path . '/' . $token . '?code=private-code&state=visible']);
            self::assertStringNotContainsString($token, (string) $data['uri']);
            self::assertStringNotContainsString('private-code', (string) $data['uri']);
            self::assertStringContainsString('state=visible', (string) $data['uri']);
        }
    }
    public function testLegacyAndVersionedGuestCapabilitiesAreMaskedInPathsAndMessages(): void
    {
        $redactor = new SecretRedactor();
        foreach ([str_repeat('x', 43), 't1_' . str_repeat('A', 90), 'vault:v2:' . str_repeat('a', 64)] as $signature) {
            $url = '/g/' . str_repeat('a', 64) . '.' . $signature . '/analytics';
            $data = $redactor->redact(['uri' => $url]);
            self::assertStringStartsWith('/g/[redacted]', $data['uri']);
            self::assertStringNotContainsString($signature, $data['uri']);
        }
    }

}
