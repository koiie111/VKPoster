<?php

declare(strict_types=1);

namespace App\Integrations\OAuth;

/**
 * A redirect-based sign-in provider (authorization code flow with PKCE). Telegram uses a widget instead
 * and has its own class (`TelegramLogin`).
 *
 * Adding a provider: implement this interface, register it in `ProviderRegistry`, add its keys to
 * `config/oauth.php` and `.env.example` (see docs/architecture/howto-oauth-provider.md).
 */
interface OAuthProvider
{
    /** Short id used in URLs and the `user_identities.provider` column. */
    public function id(): string;

    /** Name shown on buttons. */
    public function label(): string;

    /** Host of the authorization page; the only host we redirect the browser to. */
    public function authorizationHost(): string;

    public function authorizationUrl(string $redirectUri, string $state, Pkce $pkce, string $nonce): string;

    /**
     * Swap the one-time `code` for tokens.
     *
     * @param array<string, string> $callback the query parameters of the callback request (VK adds `device_id`)
     * @throws OAuthException
     */
    public function exchangeCode(string $code, string $codeVerifier, string $redirectUri, array $callback): TokenSet;

    /**
     * Read the profile. `$nonce` is the value sent in the authorization URL (checked inside `id_token` where one exists).
     *
     * @throws OAuthException
     */
    public function fetchProfile(TokenSet $tokens, string $nonce): SocialProfile;
}
