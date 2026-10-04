<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use App\Domain\Auth\PasswordPolicy;
use App\Tests\Support\MockHttpClient;
use App\Tests\Support\TestEnv;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Password rules: length, trivial patterns, the local common-password list, optional HIBP lookup.
 */
#[CoversClass(PasswordPolicy::class)]
final class PasswordPolicyTest extends TestCase
{
    private function policy(?MockHttpClient $http = null, bool $hibp = false): PasswordPolicy
    {
        return new PasswordPolicy($http ?? new MockHttpClient(), new NullLogger(), $hibp, TestEnv::basePath() . '/resources/data/common-passwords.txt');
    }

    public function testAcceptsLongUncommonPasswords(): void
    {
        self::assertNull($this->policy()->check('a-long-unusual-passphrase'));
        self::assertNull($this->policy()->check('Пароль с пробелами и кириллицей 2026'));
        self::assertNull($this->policy()->check(str_repeat('ab1', 40) . 'xyz'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function weak(): array
    {
        return [
            'too short' => ['abc123', 'слишком короткий'],
            'nine characters' => ['abcdefg12', 'слишком короткий'],
            'too long' => [str_repeat('x1', 65), 'длиннее 128'],
            'one repeated character' => ['aaaaaaaaaaaa', 'слишком простой'],
            'two characters only' => ['abababababab', 'слишком простой'],
            'common' => ['password123', 'распространён'],
            'common with other case' => ['PassWord123', 'распространён'],
            'keyboard walk' => ['1q2w3e4r5t', 'распространён'],
            'digits' => ['1234567890', 'распространён'],
        ];
    }

    #[DataProvider('weak')]
    public function testRejectsWeakPasswords(string $password, string $expected): void
    {
        $message = $this->policy()->check($password);

        self::assertNotNull($message);
        self::assertStringContainsString($expected, $message);
    }

    public function testRejectsThePasswordThatEqualsTheEmailOrItsLocalPart(): void
    {
        self::assertNotNull($this->policy()->check('maria.ivanova@example.com', 'maria.ivanova@example.com'));
        self::assertNotNull($this->policy()->check('maria.ivanova', 'maria.ivanova@example.com'));
        self::assertNull($this->policy()->check('maria.ivanova-2026!', 'maria.ivanova@example.com'));
    }

    public function testLengthIsCountedInCharactersNotBytes(): void
    {
        self::assertNotNull($this->policy()->check('пароль123'), 'nine characters, even though it is 15 bytes');
        self::assertNull($this->policy()->check('парольпарольx9'));
    }

    public function testHibpLookupSendsOnlyAFiveCharacterPrefixAndRejectsBreachedPasswords(): void
    {
        $password = 'a-long-unusual-passphrase';
        $hash = strtoupper(sha1($password));
        $http = (new MockHttpClient())->expect('GET', 'https://api.pwnedpasswords.com/range/' . substr($hash, 0, 5), 200, "0018A45C4D1DEF81644B54AB7F969B88D65:0\r\n" . substr($hash, 5) . ":42\r\n");

        $message = $this->policy($http, true)->check($password);

        self::assertNotNull($message);
        self::assertStringContainsString('утечки', $message);
        self::assertSame('https://api.pwnedpasswords.com/range/' . substr($hash, 0, 5), $http->requests[0]['url']);
        $http->assertAllConsumed();
    }

    public function testHibpPaddingEntriesWithZeroCountAreIgnored(): void
    {
        $password = 'a-long-unusual-passphrase';
        $hash = strtoupper(sha1($password));
        $http = (new MockHttpClient())->expect('GET', 'https://api.pwnedpasswords.com/range/' . substr($hash, 0, 5), 200, substr($hash, 5) . ":0\r\n");

        self::assertNull($this->policy($http, true)->check($password));
    }

    public function testHibpFailsOpen(): void
    {
        // No expectation registered: the mock throws, as a real network failure would.
        self::assertNull($this->policy(new MockHttpClient(), true)->check('a-long-unusual-passphrase'));
        $down = (new MockHttpClient())->expect('GET', 'https://api.pwnedpasswords.com/range/' . substr(strtoupper(sha1('a-long-unusual-passphrase')), 0, 5), 503, 'down');
        self::assertNull($this->policy($down, true)->check('a-long-unusual-passphrase'));
    }

    public function testHibpIsOffByDefault(): void
    {
        $http = new MockHttpClient();

        $this->policy($http)->check('a-long-unusual-passphrase');

        self::assertSame([], $http->requests);
    }

    public function testTheListIsLowerCaseAndHasThousandsOfEntries(): void
    {
        $lines = file(TestEnv::basePath() . '/resources/data/common-passwords.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($lines);
        $entries = array_filter($lines, static fn (string $l): bool => $l[0] !== '#');

        self::assertGreaterThan(5000, count($entries));
        foreach ($entries as $entry) {
            self::assertSame(mb_strtolower($entry), $entry);
            self::assertGreaterThanOrEqual(10, mb_strlen($entry));
        }
    }
}
