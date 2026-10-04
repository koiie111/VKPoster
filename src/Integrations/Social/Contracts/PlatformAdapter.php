<?php

declare(strict_types=1);

namespace App\Integrations\Social\Contracts;

/**
 * One social network. Implementations hold no state about channels: everything arrives as arguments, so the same
 * adapter serves every workspace. Every failure is a `PlatformError`.
 *
 * `$idempotencyKey` is passed for platforms that can use it; adapters of networks without such a feature ignore it
 * (the pipeline guards against duplicates itself by never auto-retrying an `unknown_outcome`).
 */
interface PlatformAdapter
{
    public function platform(): Platform;

    public function capabilities(): Capabilities;

    /**
     * Problems that would make `publish()` fail, in Russian, for showing next to the editor. Empty list = fine.
     *
     * @return list<string>
     */
    public function validate(PublishRequest $request): array;

    /**
     * @throws PlatformError
     */
    public function publish(PublishRequest $request, string $externalChannelId, Credential $credential, string $idempotencyKey): PublishResult;

    /**
     * @throws PlatformError
     */
    public function delete(PublishResult $published, string $externalChannelId, Credential $credential): void;

    /**
     * @throws PlatformError
     */
    public function pin(PublishResult $published, string $externalChannelId, Credential $credential, bool $pin): void;

    /**
     * Never throws: problems are reported in the result.
     */
    public function healthCheck(string $externalChannelId, Credential $credential): HealthStatus;
}
