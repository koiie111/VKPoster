<?php

declare(strict_types=1);

namespace App\Integrations\Social;

use App\Integrations\Social\Contracts\ChannelConnector;
use App\Integrations\Social\Contracts\Platform;
use App\Integrations\Social\Contracts\PlatformAdapter;
use LogicException;

/**
 * All platform adapters, narrowed by the feature flags. Adding a network = one adapter class + one line in `config/services.php`;
 * nothing else in the core changes.
 */
final class PlatformRegistry
{
    /** @var array<string, PlatformAdapter> */
    private array $adapters = [];

    /** @var list<Platform> */
    private array $configured = [];

    /** @var (\Closure(): list<string>)|null */
    private ?\Closure $switchedOff;

    /**
     * @param list<PlatformAdapter> $adapters every adapter that exists
     * @param list<string> $enabledNames the `PLATFORMS_ENABLED` flags
     * @param bool $allowFake whether the `fake` platform may be used (never in production)
     * @param (\Closure(): list<string>)|null $switchedOff platforms the owner turned off in the admin area (asked on every call, so a change
     *                                                     reaches long-running processes); a platform missing from the flags cannot be turned on this way
     */
    public function __construct(array $adapters, array $enabledNames, bool $allowFake, ?\Closure $switchedOff = null)
    {
        $this->switchedOff = $switchedOff;
        foreach ($adapters as $adapter) {
            $this->adapters[$adapter->platform()->value] = $adapter;
        }
        foreach ($enabledNames as $name) {
            $platform = Platform::tryFrom(trim($name));
            if ($platform === null || ($platform === Platform::Fake && !$allowFake) || !isset($this->adapters[$platform->value])) {
                continue;
            }
            $this->configured[] = $platform;
        }
    }

    /**
     * Platforms switched on, in the order of the flag list.
     *
     * @return list<Platform>
     */
    public function enabled(): array
    {
        $off = $this->switchedOff === null ? [] : ($this->switchedOff)();

        return array_values(array_filter($this->configured, static fn (Platform $p): bool => !in_array($p->value, $off, true)));
    }

    /**
     * Platforms the flags allow, whether or not the owner has switched them off right now (the admin screen lists these).
     *
     * @return list<Platform>
     */
    public function configured(): array
    {
        return $this->configured;
    }

    public function isEnabled(Platform $platform): bool
    {
        return in_array($platform, $this->enabled(), true);
    }

    /**
     * @throws LogicException for a platform without an adapter, or one that is switched off
     */
    public function adapter(Platform $platform): PlatformAdapter
    {
        if (!$this->isEnabled($platform)) {
            throw new LogicException(sprintf('Platform "%s" is not enabled.', $platform->value));
        }

        return $this->adapters[$platform->value];
    }

    public function connector(Platform $platform): ?ChannelConnector
    {
        $adapter = $this->isEnabled($platform) ? $this->adapters[$platform->value] : null;

        return $adapter instanceof ChannelConnector ? $adapter : null;
    }
}
