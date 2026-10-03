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

    public function testLoggerWritesJsonWithoutSecrets(): void
    {
        $dir = sys_get_temp_dir() . '/vkposter-log-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $logger = LoggerFactory::create($dir, 'info', false);

        $logger->info('login {user}', ['user' => 'ann', 'password' => 'hunter2', 'token' => 'abc']);

        $line = (string) file_get_contents($dir . '/app.log');
        $decoded = json_decode($line, true);
        self::assertIsArray($decoded);
        self::assertSame('login ann', $decoded['message']);
        self::assertStringNotContainsString('hunter2', $line);
        self::assertStringNotContainsString('abc', $line);
        self::assertStringContainsString(SecretRedactor::MASK, $line);
        unlink($dir . '/app.log');
        rmdir($dir);
    }
}
