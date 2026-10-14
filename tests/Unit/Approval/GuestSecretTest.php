<?php

declare(strict_types=1);

namespace App\Tests\Unit\Approval;

use App\Kernel\Log\SecretRedactor;
use PHPUnit\Framework\TestCase;

final class GuestSecretTest extends TestCase
{
    public function testGuestCapabilityPathAndPinNeverReachLogs(): void
    {
        $token = str_repeat('a', 64).'.'.str_repeat('A', 43);
        $result = (new SecretRedactor())->redact(['path' => '/g/'.$token.'/unlock','pin' => '123456','error_code' => 'failed']);
        self::assertSame('/g/[redacted]/unlock', $result['path']);
        self::assertSame('[redacted]', $result['pin']);
        self::assertSame('failed', $result['error_code']);
    }
}
