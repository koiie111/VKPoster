<?php

declare(strict_types=1);

namespace App\Integrations\Social\Fake;

use App\Integrations\Social\Contracts\Capabilities;
use App\Integrations\Social\Contracts\Credential;
use App\Integrations\Social\Contracts\ErrorKind;
use App\Integrations\Social\Contracts\HealthStatus;
use App\Integrations\Social\Contracts\Platform;
use App\Integrations\Social\Contracts\PlatformAdapter;
use App\Integrations\Social\Contracts\PlatformError;
use App\Integrations\Social\Contracts\PublishRequest;
use App\Integrations\Social\Contracts\PublishResult;
use Psr\Log\LoggerInterface;

/**
 * A network that does not exist: "publishes" into the log and into `$published`, so the whole pipeline can be exercised in dev
 * and tests without any real account. Failures can be scripted with `failNext()`; a channel id that starts with `broken-`
 * fails its health check.
 */
final class FakeAdapter implements PlatformAdapter
{
    /** @var list<array{channel: string, text: string, media: int, id: string}> */
    public array $published = [];

    /** @var list<string> */
    public array $deleted = [];

    private ?PlatformError $nextFailure = null;

    private int $counter = 0;

    public function __construct(private readonly ?LoggerInterface $logger = null)
    {
    }

    public function platform(): Platform
    {
        return Platform::Fake;
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(4096, 1024, 10, true, true, true, true, true, true, true, 50 * 1024 * 1024);
    }

    public function validate(PublishRequest $request): array
    {
        return mb_strlen($request->text) > 4096 ? ['Текст длиннее лимита тестовой сети: 4096 символов.'] : [];
    }

    /**
     * The next call of any method throws this error (once).
     */
    public function failNext(ErrorKind $kind, string $message = 'Scripted failure'): void
    {
        $this->nextFailure = new PlatformError($kind, $message, 'Тестовая ошибка: ' . $message);
    }

    public function publish(PublishRequest $request, string $externalChannelId, Credential $credential, string $idempotencyKey): PublishResult
    {
        $this->maybeFail();
        $id = (string) (++$this->counter);
        $this->published[] = ['channel' => $externalChannelId, 'text' => $request->text, 'media' => count($request->media), 'id' => $id];
        $this->logger?->info('Fake publication', ['channel' => $externalChannelId, 'id' => $id, 'chars' => mb_strlen($request->text), 'media' => count($request->media)]);

        return new PublishResult($id, 'https://fake.invalid/' . $externalChannelId . '/' . $id, [$id]);
    }

    public function delete(PublishResult $published, string $externalChannelId, Credential $credential): void
    {
        $this->maybeFail();
        $this->deleted[] = $published->externalId;
    }

    public function pin(PublishResult $published, string $externalChannelId, Credential $credential, bool $pin): void
    {
        $this->maybeFail();
    }

    public function healthCheck(string $externalChannelId, Credential $credential): HealthStatus
    {
        if (str_starts_with($externalChannelId, 'broken-')) {
            return HealthStatus::broken('Тестовый канал помечен как сломанный.');
        }

        return HealthStatus::ok(['post' => true, 'edit' => true, 'delete' => true, 'pin' => true]);
    }

    private function maybeFail(): void
    {
        if ($this->nextFailure !== null) {
            $failure = $this->nextFailure;
            $this->nextFailure = null;

            throw $failure;
        }
    }
}
