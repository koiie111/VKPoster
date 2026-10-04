<?php

declare(strict_types=1);

namespace App\Integrations\OAuth;

use App\Kernel\Config;
use App\Kernel\HttpClient\HttpClientInterface;
use App\Support\Clock;

/**
 * The sign-in providers that are switched on: those with keys in `.env`, plus `fake` outside production
 * when DEV_OAUTH_FAKE is set. Unknown or unconfigured providers do not exist as far as routes and
 * templates are concerned, which is also the whitelist for `/auth/{provider}/...`.
 */
final class ProviderRegistry
{
    /** @var array<string, OAuthProvider>|null */
    private ?array $providers = null;

    private ?TelegramLogin $telegram = null;

    public function __construct(
        private readonly Config $config,
        private readonly HttpClientInterface $http,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Redirect-based providers in display order.
     *
     * @return array<string, OAuthProvider>
     */
    public function redirectProviders(): array
    {
        if ($this->providers !== null) {
            return $this->providers;
        }
        $all = [];
        $jwt = new JwtVerifier($this->http, $this->clock);
        $vk = $this->keys('vkid');
        if ($vk['client_id'] !== '') {
            $all['vkid'] = new VkIdProvider($this->http, $vk['client_id'], $vk['client_secret']);
        }
        $yandex = $this->keys('yandex');
        if ($yandex['client_id'] !== '' && $yandex['client_secret'] !== '') {
            $all['yandex'] = new YandexProvider($this->http, $yandex['client_id'], $yandex['client_secret']);
        }
        $google = $this->keys('google');
        if ($google['client_id'] !== '' && $google['client_secret'] !== '') {
            $all['google'] = new GoogleProvider($this->http, $jwt, $google['client_id'], $google['client_secret']);
        }
        if ($this->config->bool('oauth.fake') && !$this->config->isProduction()) {
            $all['fake'] = new FakeProvider($this->config->string('app.url'));
        }

        $ordered = [];
        foreach ([...$this->config->array('oauth.order'), 'fake'] as $id) {
            if (is_string($id) && isset($all[$id])) {
                $ordered[$id] = $all[$id];
            }
        }
        foreach ($all as $id => $provider) {
            $ordered[$id] ??= $provider;
        }

        return $this->providers = $ordered;
    }

    public function get(string $id): ?OAuthProvider
    {
        return $this->redirectProviders()[$id] ?? null;
    }

    /**
     * The Telegram widget login, or null when the bot is not configured.
     */
    public function telegram(): ?TelegramLogin
    {
        if ($this->telegram === null) {
            $keys = $this->config->array('oauth.telegram');
            $this->telegram = new TelegramLogin(
                $this->clock,
                is_string($keys['bot_token'] ?? null) ? $keys['bot_token'] : '',
                is_string($keys['bot_name'] ?? null) ? $keys['bot_name'] : '',
            );
        }

        return $this->telegram->isConfigured() ? $this->telegram : null;
    }

    /**
     * Ids of every enabled provider, in the order buttons are shown (Telegram takes its place from `oauth.order`).
     *
     * @return list<string>
     */
    public function enabledIds(): array
    {
        $ids = array_keys($this->redirectProviders());
        if ($this->telegram() !== null) {
            $order = array_values(array_filter($this->config->array('oauth.order'), 'is_string'));
            $position = array_search('telegram', $order, true);
            $before = 0;
            if ($position !== false) {
                foreach (array_slice($order, 0, $position) as $earlier) {
                    $before += in_array($earlier, $ids, true) ? 1 : 0;
                }
            } else {
                $before = count($ids);
            }
            array_splice($ids, $before, 0, ['telegram']);
        }

        return $ids;
    }

    public function label(string $id): string
    {
        return match ($id) {
            'telegram' => 'Telegram',
            default => $this->get($id)?->label() ?? $id,
        };
    }

    /**
     * @return array{client_id: string, client_secret: string}
     */
    private function keys(string $provider): array
    {
        $keys = $this->config->array('oauth.' . $provider);

        return [
            'client_id' => is_string($keys['client_id'] ?? null) ? $keys['client_id'] : '',
            'client_secret' => is_string($keys['client_secret'] ?? null) ? $keys['client_secret'] : '',
        ];
    }
}
