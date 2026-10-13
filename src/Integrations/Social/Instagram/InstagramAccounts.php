<?php

declare(strict_types=1);

namespace App\Integrations\Social\Instagram;

use App\Integrations\Social\Contracts\ErrorKind;
use App\Integrations\Social\Contracts\PlatformError;
use SensitiveParameter;

/**
 * The professional Instagram account behind an access token (`GET /me`). Used when connecting (the account becomes the channel)
 * and by the health check (is it still the same, still professional account).
 */
final class InstagramAccounts
{
    public function __construct(private readonly InstagramApi $api)
    {
    }

    /**
     * @return array{id: string, username: ?string, name: string, professional: bool}
     * @throws PlatformError
     */
    public function profile(#[SensitiveParameter] string $token): array
    {
        $me = $this->api->get('me', ['fields' => 'user_id,username,name,account_type'], $token);
        $id = $me['user_id'] ?? ($me['id'] ?? null);
        if (!is_scalar($id) || preg_match('/^\d{1,32}$/', (string) $id) !== 1) {
            throw new PlatformError(ErrorKind::Temporary, 'Instagram /me answered without an account id.', 'Instagram ответил неполно. Попробуем ещё раз.');
        }
        $username = is_string($me['username'] ?? null) && $me['username'] !== '' ? $me['username'] : null;
        $name = is_string($me['name'] ?? null) && $me['name'] !== '' ? $me['name'] : ($username ?? 'Instagram ' . $id);
        $type = is_string($me['account_type'] ?? null) ? strtoupper($me['account_type']) : '';

        return ['id' => (string) $id, 'username' => $username, 'name' => $name, 'professional' => $type !== 'PERSONAL'];
    }
}
