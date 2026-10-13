<?php

declare(strict_types=1);

namespace App\Integrations\Social\Instagram;

use App\Domain\Media\MediaKind;
use App\Integrations\Social\Contracts\Capabilities;
use App\Integrations\Social\Contracts\CommentingAdapter;
use App\Integrations\Social\Contracts\Credential;
use App\Integrations\Social\Contracts\ErrorKind;
use App\Integrations\Social\Contracts\HealthStatus;
use App\Integrations\Social\Contracts\OutcomeVerifier;
use App\Integrations\Social\Contracts\Platform;
use App\Integrations\Social\Contracts\PlatformAdapter;
use App\Integrations\Social\Contracts\PlatformError;
use App\Integrations\Social\Contracts\PublishMedia;
use App\Integrations\Social\Contracts\PublishRequest;
use App\Integrations\Social\Contracts\PublishResult;
use Closure;
use DateTimeImmutable;

/**
 * Instagram (professional account, Instagram Login, ADR 0010). The account id is the channel's external id. Publishing is two steps: a
 * container is created from a public link to the file (Instagram downloads it itself, so media arrive as `PublishMedia::$url`), the adapter
 * waits until Instagram has processed it (`status_code=FINISHED`, polling with growing pauses), and `media_publish` makes it a post.
 * Kinds: a photo or a carousel of 2–10 files, Reels (one video), Stories (one file). The daily publishing limit is asked before anything is created.
 *
 * Instagram cannot delete, edit or pin a post through the API, so those abilities are off in `capabilities()`.
 */
final class InstagramAdapter implements PlatformAdapter, CommentingAdapter, OutcomeVerifier
{
    private const MAX_CAPTION = 2200;
    private const MAX_MEDIA = 10;
    private const MAX_HASHTAGS = 30;
    private const MAX_MENTIONS = 20;
    private const MAX_VIDEO_BYTES = 100 * 1024 * 1024;
    /** How many of the latest posts are looked through when an outcome is unknown. */
    private const VERIFY_POSTS = 20;
    /** Pauses (seconds) between looks at a container that is still being processed: about five minutes in all. */
    private const WAIT_STEPS = [2, 3, 5, 8, 13, 20, 30, 30, 30, 30, 30, 30, 30, 30];

    /** @var Closure(int): void */
    private readonly Closure $sleep;

    /**
     * @param (Closure(int): void)|null $sleep pause between looks, in seconds (tests pass a no-op)
     */
    public function __construct(
        private readonly InstagramApi $api,
        private readonly InstagramAccounts $accounts,
        private readonly int $postsPerDay = 100,
        ?Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    public function platform(): Platform
    {
        return Platform::Instagram;
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(self::MAX_CAPTION, self::MAX_CAPTION, self::MAX_MEDIA, true, false, false, false, false, false, true, self::MAX_VIDEO_BYTES, false, 'plain', $this->postsPerDay, true, true);
    }

    public function validate(PublishRequest $request): array
    {
        $problems = [];
        $count = count($request->media);
        $length = mb_strlen($request->text);

        if ($count === 0) {
            $problems[] = 'В Instagram нельзя опубликовать пост без фото или видео: добавьте файл.';
        }
        if ($length > self::MAX_CAPTION) {
            $problems[] = sprintf('Подпись длиннее лимита Instagram: %d из %d символов.', $length, self::MAX_CAPTION);
        }
        if (preg_match_all('/(?<![\p{L}\p{N}_])#[\p{L}\p{N}_]+/u', $request->text) > self::MAX_HASHTAGS) {
            $problems[] = sprintf('В подписи Instagram можно не больше %d хэштегов.', self::MAX_HASHTAGS);
        }
        if (preg_match_all('/(?<![\p{L}\p{N}_.])@[\p{L}\p{N}_.]+/u', $request->text) > self::MAX_MENTIONS) {
            $problems[] = sprintf('В подписи Instagram можно упомянуть не больше %d аккаунтов.', self::MAX_MENTIONS);
        }
        if ($count > self::MAX_MEDIA) {
            $problems[] = 'В Instagram можно прикрепить не больше 10 файлов к одному посту.';
        }
        if ($request->buttons !== []) {
            $problems[] = 'Instagram не умеет кнопки под постом: уберите кнопки для этого канала.';
        }
        if ($request->poll !== null) {
            $problems[] = 'Опросы в Instagram через API недоступны: уберите опрос для этого канала.';
        }
        foreach ($request->media as $media) {
            if ($media->kind === MediaKind::Document) {
                $problems[] = sprintf('Instagram не принимает документы: уберите файл «%s».', $media->filename);
            }
        }
        $videos = count(array_filter($request->media, static fn (PublishMedia $m): bool => $m->kind === MediaKind::Video));
        switch ($this->mode($request)) {
            case 'reel':
                if ($count !== 1 || $videos !== 1) {
                    $problems[] = 'Reels — это одно видео. Оставьте один видеофайл или выберите «Пост или карусель».';
                }
                break;
            case 'story':
                if ($count !== 1) {
                    $problems[] = 'История в Instagram — это один файл (фото или видео).';
                }
                break;
        }

        return $problems;
    }

    public function publish(PublishRequest $request, string $externalChannelId, Credential $credential, string $idempotencyKey): PublishResult
    {
        $token = $credential->secret;
        $account = $this->accountId($externalChannelId);
        $problems = $this->validate($request);
        if ($problems !== []) {
            throw new PlatformError(ErrorKind::Permanent, 'Instagram request failed validation.', $problems[0]);
        }
        foreach ($request->media as $media) {
            if ($media->url === null || !str_starts_with($media->url, 'https://')) {
                throw new PlatformError(ErrorKind::Permanent, 'Instagram needs a public https link to every file.', 'Instagram скачивает файлы по публичной ссылке: адрес сервиса (APP_URL) должен быть доступен из интернета по https.');
            }
        }
        $this->assertQuota($account, $token);

        $mode = $this->mode($request);
        $container = match ($mode) {
            'reel' => $this->createContainer($account, $token, ['media_type' => 'REELS', 'video_url' => (string) $request->media[0]->url, 'caption' => $request->text, 'share_to_feed' => true]),
            'story' => $this->createContainer($account, $token, ['media_type' => 'STORIES'] + $this->source($request->media[0])),
            default => count($request->media) === 1
                ? $this->createContainer($account, $token, ['caption' => $request->text] + $this->source($request->media[0]))
                : $this->createCarousel($account, $token, $request),
        };
        $this->waitFinished($container, $token);

        $published = $this->api->post($account . '/media_publish', ['creation_id' => $container], $token, true);
        $mediaId = $published['id'] ?? null;
        if (!is_string($mediaId) && !is_int($mediaId)) {
            throw new PlatformError(ErrorKind::UnknownOutcome, 'Instagram accepted media_publish but returned no id.', 'Instagram принял пост, но не вернул его номер. Проверьте профиль.');
        }
        $mediaId = (string) $mediaId;

        return new PublishResult($mediaId, $this->permalink($mediaId, $token), [$mediaId]);
    }

    public function delete(PublishResult $published, string $externalChannelId, Credential $credential): void
    {
        throw new PlatformError(ErrorKind::Permanent, 'Instagram API cannot delete media.', 'Instagram не позволяет удалять посты через API: удалите пост в приложении Instagram.');
    }

    public function pin(PublishResult $published, string $externalChannelId, Credential $credential, bool $pin): void
    {
        throw new PlatformError(ErrorKind::Permanent, 'Instagram API cannot pin media.', 'Instagram не позволяет закреплять посты через API.');
    }

    public function comment(PublishResult $published, string $externalChannelId, Credential $credential, string $text): void
    {
        $this->api->post($published->externalId . '/comments', ['message' => $text], $credential->secret, true);
    }

    public function healthCheck(string $externalChannelId, Credential $credential): HealthStatus
    {
        try {
            $profile = $this->accounts->profile($credential->secret);
        } catch (PlatformError $e) {
            return $e->concernsChannel() ? HealthStatus::broken($e->forUser(), true) : HealthStatus::unknown($e->forUser());
        }
        if ($profile['id'] !== $externalChannelId) {
            return HealthStatus::broken('Доступ выдан другому аккаунту Instagram. Подключите нужный аккаунт заново.', true);
        }
        if (!$profile['professional']) {
            return HealthStatus::broken('Аккаунт стал личным. Публиковать можно только в профессиональный аккаунт (бизнес или автор): переключите его в приложении Instagram.', true);
        }

        return HealthStatus::ok(['post' => true, 'edit' => false, 'delete' => false, 'pin' => false], $profile['name']);
    }

    public function findPublished(PublishRequest $request, string $externalChannelId, Credential $credential, DateTimeImmutable $since): ?PublishResult
    {
        $account = $this->accountId($externalChannelId);
        $response = $this->api->get($account . '/media', ['fields' => 'id,caption,timestamp,permalink', 'limit' => self::VERIFY_POSTS], $credential->secret);
        $items = is_array($response['data'] ?? null) ? $response['data'] : [];
        $wanted = self::normalize($request->text);

        foreach ($items as $item) {
            if (!is_array($item) || !is_string($item['id'] ?? null)) {
                continue;
            }
            $time = is_string($item['timestamp'] ?? null) ? strtotime($item['timestamp']) : false;
            // A one-minute margin covers clock differences between us and Instagram.
            if ($time === false || $time < $since->getTimestamp() - 60) {
                continue;
            }
            if (self::normalize(is_string($item['caption'] ?? null) ? $item['caption'] : '') === $wanted) {
                return new PublishResult($item['id'], is_string($item['permalink'] ?? null) ? $item['permalink'] : null, [$item['id']]);
            }
        }

        return null;
    }

    /**
     * `feed`, `reel` or `story`: an explicit choice wins; otherwise a single video becomes a Reel (Instagram no longer publishes plain
     * feed videos through the API) and everything else a post or carousel.
     */
    private function mode(PublishRequest $request): string
    {
        $single = count($request->media) === 1 && $request->media[0]->kind === MediaKind::Video;
        if ($request->mode === 'story') {
            return 'story';
        }
        if ($request->mode === 'reel' || (($request->mode === '' || $request->mode === 'feed') && $single)) {
            return 'reel';
        }

        return 'feed';
    }

    /**
     * @return array<string, string>
     */
    private function source(PublishMedia $media): array
    {
        return $media->kind === MediaKind::Video ? ['media_type' => 'VIDEO', 'video_url' => (string) $media->url] : ['image_url' => (string) $media->url];
    }

    /**
     * @param array<string, scalar> $params
     * @throws PlatformError
     */
    private function createContainer(string $account, string $token, array $params): string
    {
        if (($params['caption'] ?? null) === '') {
            unset($params['caption']);
        }
        $response = $this->api->post($account . '/media', $params, $token);
        $id = $response['id'] ?? null;
        if ((!is_string($id) && !is_int($id)) || (string) $id === '') {
            throw new PlatformError(ErrorKind::Temporary, 'Instagram created no container.', 'Instagram ответил неполно. Попробуем ещё раз.');
        }

        return (string) $id;
    }

    /**
     * @throws PlatformError
     */
    private function createCarousel(string $account, string $token, PublishRequest $request): string
    {
        $children = [];
        foreach ($request->media as $media) {
            $child = $this->createContainer($account, $token, ['is_carousel_item' => true] + $this->source($media));
            if ($media->kind === MediaKind::Video) {
                $this->waitFinished($child, $token);
            }
            $children[] = $child;
        }
        $params = ['media_type' => 'CAROUSEL', 'children' => implode(',', $children)];
        if ($request->text !== '') {
            $params['caption'] = $request->text;
        }

        return $this->createContainer($account, $token, $params);
    }

    /**
     * Wait until Instagram has processed a container. Nothing is published yet, so running out of patience is `temporary` (a retry makes a
     * new container) and an `ERROR` or `EXPIRED` container is `permanent`.
     *
     * @throws PlatformError
     */
    private function waitFinished(string $container, string $token): void
    {
        foreach (self::WAIT_STEPS as $pause) {
            $status = $this->api->get($container, ['fields' => 'status_code,status'], $token);
            $code = is_string($status['status_code'] ?? null) ? strtoupper($status['status_code']) : '';
            if ($code === 'FINISHED') {
                return;
            }
            if ($code === 'ERROR' || $code === 'EXPIRED') {
                throw new PlatformError(ErrorKind::Permanent, 'Instagram container ' . $code . (is_string($status['status'] ?? null) ? ': ' . mb_substr($status['status'], 0, 200) : ''), 'Instagram не смог обработать файл. Проверьте формат: фото JPEG, для Reels и историй — вертикальное видео (9:16), H.264.');
            }
            ($this->sleep)($pause);
        }

        throw new PlatformError(ErrorKind::Temporary, 'Instagram is still processing the container.', 'Instagram долго обрабатывает файл. Попробуем ещё раз чуть позже.');
    }

    /**
     * @throws PlatformError `rate_limited` when the account has used its publications for the last 24 hours
     */
    private function assertQuota(string $account, string $token): void
    {
        $response = $this->api->get($account . '/content_publishing_limit', ['fields' => 'quota_usage,config'], $token);
        $row = is_array($response['data'][0] ?? null) ? $response['data'][0] : [];
        $used = $row['quota_usage'] ?? null;
        $total = is_array($row['config'] ?? null) ? ($row['config']['quota_total'] ?? null) : null;
        if (is_int($used) && is_int($total) && $total > 0 && $used >= $total) {
            throw new PlatformError(ErrorKind::RateLimited, sprintf('Instagram publishing limit reached (%d of %d).', $used, $total), 'Достигнут суточный лимит публикаций Instagram для этого аккаунта. Пост уйдёт позже.', 3600);
        }
    }

    private function permalink(string $mediaId, string $token): ?string
    {
        try {
            $response = $this->api->get($mediaId, ['fields' => 'permalink'], $token);
        } catch (PlatformError) {
            return null; // the post is out; its link is a nicety
        }

        return is_string($response['permalink'] ?? null) ? $response['permalink'] : null;
    }

    /**
     * @throws PlatformError
     */
    private function accountId(string $externalChannelId): string
    {
        if (preg_match('/^\d{1,32}$/', $externalChannelId) !== 1) {
            throw new PlatformError(ErrorKind::Permanent, 'Bad Instagram account id.', 'Не получилось определить аккаунт Instagram. Подключите его заново.');
        }

        return $externalChannelId;
    }

    private static function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
