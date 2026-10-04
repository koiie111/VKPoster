<?php

declare(strict_types=1);

namespace App\Http\Auth;

use App\Integrations\OAuth\Pkce;
use App\Integrations\OAuth\ProviderRegistry;
use App\Integrations\OAuth\SocialProfile;
use App\Kernel\Config;
use App\Kernel\Http\RequestContext;
use App\Kernel\Http\Response;
use App\Kernel\Session\Session;
use App\Support\Clock;
use RuntimeException;

/**
 * Browser-side state of social sign-in. Everything lives in the server-side session:
 *
 * - `oauth.flow.<provider>`: one pending authorization per provider (`state`, PKCE verifier, `nonce`, what the
 *   visitor wanted: sign in or link, and where to go next). It is taken out of the session when the callback
 *   arrives, so a `state` works exactly once, and it expires after 10 minutes.
 * - `social.pending_link`: a provider profile held back because its verified email belongs to an existing
 *   account; it is attached after the visitor signs in with that account's password.
 * - `social.pending_account`: a profile waiting for the consent step of sign-up.
 */
final class SocialFlow
{
    public const TTL = 600;

    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly Config $config,
        private readonly RequestContext $context,
        private readonly Clock $clock,
    ) {
    }

    public function redirectUri(string $providerId): string
    {
        return rtrim($this->config->string('app.url'), '/') . '/auth/' . $providerId . '/callback';
    }

    /**
     * Start an authorization at a redirect-based provider and answer with the redirect to it.
     *
     * @param 'login'|'link' $intent
     * @param int|null $userId the signed-in user for `link`
     * @param string|null $next relative URL to open after signing in (anything else is dropped)
     */
    public function begin(string $providerId, string $intent, ?int $userId, ?string $next): ?Response
    {
        $provider = $this->registry->get($providerId);
        if ($provider === null) {
            return null;
        }
        $state = Pkce::base64Url(random_bytes(32));
        $nonce = Pkce::base64Url(random_bytes(24));
        $pkce = Pkce::generate();
        $this->session()->set('oauth.flow.' . $providerId, [
            'state' => $state,
            'verifier' => $pkce->verifier,
            'nonce' => $nonce,
            'intent' => $intent,
            'user_id' => $userId,
            'next' => self::safeNext($next),
            'until' => $this->clock->now()->getTimestamp() + self::TTL,
        ]);

        $url = $provider->authorizationUrl($this->redirectUri($providerId), $state, $pkce, $nonce);

        // Real providers: an absolute URL on the provider's own host only. The fake one lives on this site.
        return Response::isRelativeUrl($url) ? Response::redirect($url) : Response::redirectToTrusted($url, [$provider->authorizationHost()]);
    }

    /**
     * Take the pending authorization out of the session and check the `state` that came back.
     *
     * @return array{verifier: string, nonce: string, intent: string, user_id: ?int, next: ?string}|null null for a missing,
     *         expired, already used or forged state
     */
    public function take(string $providerId, mixed $state): ?array
    {
        $flow = $this->session()->pull('oauth.flow.' . $providerId);
        if (!is_array($flow) || !is_string($flow['state'] ?? null) || !is_string($state) || $state === '') {
            return null;
        }
        if (!is_int($flow['until'] ?? null) || $flow['until'] < $this->clock->now()->getTimestamp() || !hash_equals($flow['state'], $state)) {
            return null;
        }

        return [
            'verifier' => is_string($flow['verifier'] ?? null) ? $flow['verifier'] : '',
            'nonce' => is_string($flow['nonce'] ?? null) ? $flow['nonce'] : '',
            'intent' => ($flow['intent'] ?? 'login') === 'link' ? 'link' : 'login',
            'user_id' => is_int($flow['user_id'] ?? null) ? $flow['user_id'] : null,
            'next' => is_string($flow['next'] ?? null) ? $flow['next'] : null,
        ];
    }

    /**
     * Data for the Telegram widget, or null when Telegram login is off. Also allows the widget's script and
     * frame in the Content-Security-Policy of the current response (this page only). The `state` travels
     * inside `data-auth-url`, so a callback that was not started from our page is refused.
     *
     * @param 'login'|'link' $intent
     * @return array{bot: string, auth_url: string}|null
     */
    public function telegramWidget(string $intent, ?int $userId, ?string $next = null): ?array
    {
        $telegram = $this->registry->telegram();
        if ($telegram === null) {
            return null;
        }
        $state = Pkce::base64Url(random_bytes(32));
        $this->session()->set('oauth.flow.telegram', [
            'state' => $state,
            'intent' => $intent,
            'user_id' => $userId,
            'next' => self::safeNext($next),
            'until' => $this->clock->now()->getTimestamp() + self::TTL,
        ]);
        $this->context->allowCsp('script-src', 'https://telegram.org');
        $this->context->allowCsp('frame-src', 'https://oauth.telegram.org');

        return ['bot' => $telegram->botName(), 'auth_url' => $this->redirectUri('telegram') . '?state=' . $state];
    }

    /**
     * Buttons for the sign-in and sign-up pages, in display order.
     *
     * @return list<array{id: string, label: string, href: ?string}> `href` is null for Telegram (it uses the widget)
     */
    public function buttons(?string $next = null): array
    {
        $buttons = [];
        $safe = self::safeNext($next);
        $query = $safe === null ? '' : '?next=' . rawurlencode($safe);
        foreach ($this->registry->enabledIds() as $id) {
            $buttons[] = ['id' => $id, 'label' => $this->registry->label($id), 'href' => $id === 'telegram' ? null : '/auth/' . $id . '/redirect' . $query];
        }

        return $buttons;
    }

    public function holdPendingLink(SocialProfile $profile): void
    {
        $this->session()->set('social.pending_link', ['profile' => $profile->toArray(), 'until' => $this->clock->now()->getTimestamp() + self::TTL]);
    }

    /**
     * The profile held back for linking after a password sign-in, removed from the session.
     */
    public function takePendingLink(): ?SocialProfile
    {
        return $this->takeProfile('social.pending_link', true);
    }

    /**
     * Wait for the consent step of sign-up.
     */
    public function holdPendingAccount(SocialProfile $profile, ?string $next): void
    {
        $this->session()->set('social.pending_account', [
            'profile' => $profile->toArray(),
            'next' => self::safeNext($next),
            'until' => $this->clock->now()->getTimestamp() + self::TTL,
        ]);
    }

    /**
     * @param bool $remove false to look without consuming (rendering the consent page)
     * @return array{profile: SocialProfile, next: ?string}|null
     */
    public function pendingAccount(bool $remove): ?array
    {
        $key = 'social.pending_account';
        $stored = $this->session()->get($key);
        $profile = $this->takeProfile($key, $remove);

        return $profile === null ? null : ['profile' => $profile, 'next' => is_array($stored) && is_string($stored['next'] ?? null) ? $stored['next'] : null];
    }

    public static function safeNext(?string $next): ?string
    {
        return $next !== null && strlen($next) <= 512 && Response::isRelativeUrl($next) ? $next : null;
    }

    private function takeProfile(string $key, bool $remove): ?SocialProfile
    {
        $session = $this->session();
        $stored = $session->get($key);
        if ($remove) {
            $session->forget($key);
        }
        if (!is_array($stored) || !is_array($stored['profile'] ?? null) || !is_int($stored['until'] ?? null) || $stored['until'] < $this->clock->now()->getTimestamp()) {
            return null;
        }

        return SocialProfile::fromArray($stored['profile']);
    }

    private function session(): Session
    {
        return $this->context->session() ?? throw new RuntimeException('No session in this request.');
    }
}
