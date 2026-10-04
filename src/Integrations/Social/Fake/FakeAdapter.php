<?php

declare(strict_types=1);

namespace App\Integrations\Social\Fake;

use App\Integrations\Social\Contracts\Capabilities;
use App\Integrations\Social\Contracts\CommentingAdapter;
use App\Integrations\Social\Contracts\Credential;
use App\Integrations\Social\Contracts\EditableAdapter;
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
final class FakeAdapter implements PlatformAdapter, EditableAdapter, CommentingAdapter
{
    /** @var list<array{channel: string, text: string, media: int, id: string}> */
    public array $published = [];

    /** @var list<string> */
    public array $deleted = [];

    /** @var list<array{id: string, text: string}> */
    public array $edited = [];

    /** @var list<array{id: string, text: string}> */
    public array $comments = [];

    /** @var list<string> */
    public array $pinned = [];

    /** @var list<PublishRequest> every request that reached `publish()`, for tests that look at what would have gone out */
    public array $requests = [];

    /** @var \Closure|null called inside `publish()` after the post is "sent" (tests simulate a crash right after sending) */
    public ?\Closure $afterSend = null;

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
        return new Capabilities(4096, 1024, 10, true, true, true, true, true, true, true, 50 * 1024 * 1024, true, 'html');
    }

    public function validate(PublishRequest $request): array
    {
        return mb_strlen($request->text) > 4096 ? ['Текст длиннее лимита тестовой сети: 4096 символов.'] : [];
    }

    /**
     * The next call of any method throws this error (once).
     */
    public function failNext(ErrorKind $kind, string $message = 'Scripted failure', ?int $retryAfter = null): void
    {
        $this->nextFailure = new PlatformError($kind, $message, 'Тестовая ошибка сети.', $retryAfter);
    }

    public function publish(PublishRequest $request, string $externalChannelId, Credential $credential, string $idempotencyKey): PublishResult
    {
        $this->maybeFail();
        $this->requests[] = $request;
        $id = (string) (++$this->counter);
        $this->published[] = ['channel' => $externalChannelId, 'text' => $request->text, 'media' => count($request->media), 'id' => $id];
        $this->logger?->info('Fake publication', ['channel' => $externalChannelId, 'id' => $id, 'chars' => mb_strlen($request->text), 'media' => count($request->media)]);
        if ($this->afterSend !== null) {
            ($this->afterSend)($id);
        }

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
        if ($pin) {
            $this->pinned[] = $published->externalId;
        }
    }

    public function edit(PublishResult $published, string $externalChannelId, Credential $credential, PublishRequest $request, bool $hasMedia): void
    {
        $this->maybeFail();
        $this->edited[] = ['id' => $published->externalId, 'text' => $request->text];
    }

    public function comment(PublishResult $published, string $externalChannelId, Credential $credential, string $text): void
    {
        $this->maybeFail();
        $this->comments[] = ['id' => $published->externalId, 'text' => $text];
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
