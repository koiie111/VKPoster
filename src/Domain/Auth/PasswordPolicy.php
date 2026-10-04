<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Kernel\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Rules for new passwords: 10 to 128 characters, not a single repeated character, not the email
 * address, not in the local list of common passwords, and (optionally) not found in the Have I Been
 * Pwned range API. The HIBP lookup sends only the first 5 characters of the SHA-1 hash and fails
 * open: when the service is down, sign-ups keep working.
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 10;
    public const MAX_LENGTH = 128;

    /** @var array<string, true>|null */
    private ?array $common = null;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
        private readonly bool $hibpEnabled,
        private readonly string $listPath,
    ) {
    }

    /**
     * @return string|null a Russian error message to show next to the field, or null when the password is acceptable
     */
    public function check(string $password, ?string $email = null): ?string
    {
        $length = mb_strlen($password);
        if ($length < self::MIN_LENGTH) {
            return sprintf('Пароль слишком короткий. Нужно не меньше %d символов.', self::MIN_LENGTH);
        }
        if ($length > self::MAX_LENGTH) {
            return sprintf('Пароль длиннее %d символов. Сократите его.', self::MAX_LENGTH);
        }
        $lower = mb_strtolower($password);
        if (count(array_unique(mb_str_split($lower))) < 3) {
            return 'Пароль слишком простой. Добавьте другие символы.';
        }
        if ($email !== null && ($lower === mb_strtolower($email) || $lower === mb_strtolower(explode('@', $email)[0]))) {
            return 'Пароль не должен совпадать с адресом почты. Придумайте другой.';
        }
        if (isset($this->commonPasswords()[$lower])) {
            return 'Этот пароль слишком распространён, его легко подобрать. Придумайте другой.';
        }
        if ($this->hibpEnabled && $this->isPwned($password)) {
            return 'Этот пароль уже попадал в утечки данных. Придумайте другой.';
        }

        return null;
    }

    /**
     * @return array<string, true>
     */
    private function commonPasswords(): array
    {
        if ($this->common === null) {
            $this->common = [];
            $lines = is_file($this->listPath) ? file($this->listPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false;
            foreach ($lines === false ? [] : $lines as $line) {
                if ($line !== '' && $line[0] !== '#') {
                    $this->common[mb_strtolower(trim($line))] = true;
                }
            }
        }

        return $this->common;
    }

    private function isPwned(string $password): bool
    {
        $hash = strtoupper(sha1($password));
        try {
            $response = $this->http->request('GET', 'https://api.pwnedpasswords.com/range/' . substr($hash, 0, 5), [
                'headers' => ['Add-Padding' => 'true'],
                'timeout' => 3,
                'connect_timeout' => 2,
            ]);
        } catch (Throwable $e) {
            $this->logger->warning('password.hibp.unavailable', ['error' => $e::class]);

            return false;
        }
        if ($response->getStatusCode() !== 200) {
            return false;
        }
        $suffix = substr($hash, 5);
        foreach (explode("\n", (string) $response->getBody()) as $line) {
            [$candidate, $count] = array_pad(explode(':', trim($line), 2), 2, '0');
            if (hash_equals($suffix, strtoupper($candidate)) && (int) $count > 0) {
                return true;
            }
        }

        return false;
    }
}
