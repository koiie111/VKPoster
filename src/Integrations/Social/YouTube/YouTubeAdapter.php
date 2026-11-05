<?php

declare(strict_types=1);

namespace App\Integrations\Social\YouTube;

use App\Domain\Media\MediaKind;
use App\Domain\Post\PostKind;
use App\Domain\Post\VideoProviderSessions;
use App\Domain\Post\VideoUploadQuota;
use App\Integrations\Social\Contracts\Capabilities;
use App\Integrations\Social\Contracts\Credential;
use App\Integrations\Social\Contracts\ErrorKind;
use App\Integrations\Social\Contracts\HealthStatus;
use App\Integrations\Social\Contracts\OutcomeVerifier;
use App\Integrations\Social\Contracts\Platform;
use App\Integrations\Social\Contracts\PlatformAdapter;
use App\Integrations\Social\Contracts\PlatformError;
use App\Integrations\Social\Contracts\PublishRequest;
use App\Integrations\Social\Contracts\PublishResult;
use App\Integrations\Social\Contracts\VideoProcessingAdapter;
use App\Integrations\Social\Contracts\VideoThumbnailAdapter;
use App\Integrations\Social\Contracts\EditableAdapter;

/** Uploads Shorts to YouTube in resumable 8 MiB parts, then polls processing before marking the publication sent. */
final class YouTubeAdapter implements PlatformAdapter, VideoProcessingAdapter, VideoThumbnailAdapter, EditableAdapter, OutcomeVerifier
{
    private const CHUNK_BYTES = 8 * 1024 * 1024;

    public function __construct(private readonly YouTubeApi $api, private readonly VideoProviderSessions $sessions, private readonly VideoUploadQuota $quota, private readonly int $dailyUploadLimit)
    {
    }

    public function platform(): Platform
    {
        return Platform::YouTube;
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            maxText: 5000,
            maxCaption: 5000,
            maxMedia: 1,
            albums: false,
            polls: false,
            buttons: false,
            silent: false,
            pin: false,
            delete: true,
            firstComment: false,
            maxFileBytes: 1024 * 1024 * 1024,
            shortVideo: true,
            shortVideoFields: ['title', 'privacy', 'made_for_kids', 'category_id', 'tags', 'cover_media_id']
        );
    }

    public function validate(PublishRequest $request): array
    {
        $options = $request->shortVideo;
        $issues = [];
        if ($request->kind !== PostKind::ShortVideo || count($request->media) !== 1 || $request->media[0]->kind !== MediaKind::Video) {
            $issues[] = 'Для YouTube Shorts выберите одно короткое видео.';
        }
        if ($options === null || $options->title === '') {
            $issues[] = 'Укажите заголовок YouTube Shorts.';
        } elseif (mb_strlen($options->title) > 100 || strpbrk($options->title, '<>') !== false) {
            $issues[] = 'Заголовок YouTube Shorts должен быть до 100 символов и без символов < >.';
        }
        if (strlen($request->text) > 5000 || strpbrk($request->text, '<>') !== false) {
            $issues[] = 'Описание YouTube должно быть до 5000 байт и без символов < >.';
        }
        if ($options === null || !in_array($options->privacy, ['public', 'unlisted', 'private'], true)) {
            $issues[] = 'Выберите видимость YouTube Shorts.';
        }
        if ($options === null || $options->madeForKids === null) {
            $issues[] = 'Укажите, предназначено ли видео для детей.';
        }
        if ($options !== null && strlen(implode(',', $options->tags)) > 460) {
            $issues[] = 'Список тегов YouTube слишком длинный.';
        }
        return $issues;
    }

    public function publish(PublishRequest $request, string $externalChannelId, Credential $credential, string $idempotencyKey): PublishResult
    {
        $issues = $this->validate($request);
        if ($issues !== []) {
            throw new PlatformError(ErrorKind::Permanent, 'YouTube request invalid.', implode(' ', $issues));
        }
        $media = $request->media[0];
        $path = $media->path;
        $size = is_file($path) ? filesize($path) : false;
        if ($size === false || $size < 1) {
            throw new PlatformError(ErrorKind::Temporary, 'YouTube source file missing.', 'Не удалось подготовить файл видео. Попробуем позже.');
        }
        $session = $this->sessions->find($idempotencyKey);
        if ($session !== null && $session['platform'] !== 'youtube') {
            throw new PlatformError(ErrorKind::UnknownOutcome, 'YouTube session platform changed.', 'Проверьте YouTube перед повторной отправкой.');
        }
        if ($session !== null && $session['phase'] === 'processing' && $session['remote_id'] !== null) {
            return new PublishResult($session['remote_id'], 'https://www.youtube.com/shorts/' . rawurlencode($session['remote_id']), [], true);
        }
        $url = $session['upload_url'] ?? null;
        if ($url === null) {
            if (!$this->quota->reserveYouTube($idempotencyKey, $this->dailyUploadLimit)) {
                throw new PlatformError(ErrorKind::RateLimited, 'YouTube project upload quota exhausted.', 'Квота загрузок YouTube на сегодня исчерпана. Публикация будет отложена.', $this->quota->secondsUntilReset());
            }
            $options = $request->shortVideo;
            if ($options === null) {
                throw new \LogicException('YouTube options disappeared.');
            }
            $tags = $options->tags;
            $tags[] = self::marker($idempotencyKey);
            $url = $this->api->startUpload([
                'snippet' => ['title' => $options->title, 'description' => $request->text, 'tags' => $tags, 'categoryId' => (string) ($options->categoryId ?? 22)],
                'status' => ['privacyStatus' => $options->privacy, 'selfDeclaredMadeForKids' => $options->madeForKids],
            ], $size, $credential->secret);
            $this->sessions->begin($idempotencyKey, 'youtube', null, $url);
        }
        $state = $this->api->uploadStatus($url, $size, $credential->secret);
        $offset = $state['offset'];
        $videoId = $state['id'];
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new PlatformError(ErrorKind::Temporary, 'YouTube source file unreadable.', 'Не удалось прочитать видео. Попробуем позже.');
        }
        try {
            while ($videoId === null && $offset < $size) {
                if (fseek($handle, $offset) !== 0) {
                    throw new PlatformError(ErrorKind::Temporary, 'YouTube source seek failed.', 'Не удалось продолжить загрузку видео.');
                }
                $bytes = fread($handle, max(1, min(self::CHUNK_BYTES, $size - $offset)));
                if (!is_string($bytes) || $bytes === '') {
                    throw new PlatformError(ErrorKind::Temporary, 'YouTube source read failed.', 'Не удалось продолжить загрузку видео.');
                }
                $answer = $this->api->uploadChunk($url, $bytes, $offset, $size, $credential->secret);
                $videoId = $answer['id'];
                $confirmed = $answer['offset'];
                if ($confirmed <= $offset && $videoId === null) {
                    throw new PlatformError(ErrorKind::Temporary, 'YouTube made no upload progress.', 'YouTube не подтвердил часть видео. Повторим позже.');
                }
                $offset = $confirmed;
                $this->sessions->confirm($idempotencyKey, $offset);
            }
        } finally {
            fclose($handle);
        }
        if ($videoId === null) {
            throw new PlatformError(ErrorKind::UnknownOutcome, 'YouTube upload ended without a video id.', 'Видео могло появиться на YouTube. Проверьте канал.');
        }
        $this->sessions->processing($idempotencyKey, $videoId);
        return new PublishResult($videoId, 'https://www.youtube.com/shorts/' . rawurlencode($videoId), [], true);
    }

    public function pollVideo(string $remoteId, string $externalChannelId, Credential $credential): ?PublishResult
    {
        $response = $this->api->video($remoteId, $credential->secret);
        $items = is_array($response['items'] ?? null) ? $response['items'] : [];
        $video = is_array($items[0] ?? null) ? $items[0] : null;
        if ($video === null) {
            return null;
        }
        $status = is_array($video['status'] ?? null) ? $video['status'] : [];
        $details = is_array($video['processingDetails'] ?? null) ? $video['processingDetails'] : [];
        if (in_array($status['uploadStatus'] ?? null, ['rejected', 'failed', 'deleted'], true) || ($details['processingStatus'] ?? null) === 'failed') {
            throw new PlatformError(ErrorKind::Permanent, 'YouTube video processing failed.', 'YouTube отклонил видео. Проверьте формат и правила канала.');
        }
        if (($status['uploadStatus'] ?? null) === 'processed' || ($details['processingStatus'] ?? null) === 'succeeded') {
            return new PublishResult($remoteId, 'https://www.youtube.com/shorts/' . rawurlencode($remoteId));
        }
        return null;
    }

    public function findPublished(PublishRequest $request, string $externalChannelId, Credential $credential, \DateTimeImmutable $since): ?PublishResult
    {
        if ($request->idempotencyKey === null) {
            return null;
        }
        $marker = self::marker($request->idempotencyKey);
        $channels = $this->api->channels($credential->secret);
        $items = is_array($channels['items'] ?? null) ? $channels['items'] : [];
        foreach ($items as $channel) {
            if (!is_array($channel) || ($channel['id'] ?? null) !== $externalChannelId) {
                continue;
            }
            $details = is_array($channel['contentDetails'] ?? null) ? $channel['contentDetails'] : [];
            $playlists = is_array($details['relatedPlaylists'] ?? null) ? $details['relatedPlaylists'] : [];
            $uploads = $playlists['uploads'] ?? null;
            if (!is_string($uploads) || $uploads === '') {
                return null;
            }
            $recent = $this->api->playlistItems($uploads, $credential->secret);
            $ids = [];
            foreach (is_array($recent['items'] ?? null) ? $recent['items'] : [] as $item) {
                $content = is_array($item) && is_array($item['contentDetails'] ?? null) ? $item['contentDetails'] : [];
                $id = $content['videoId'] ?? null;
                if (is_string($id) && $id !== '') {
                    $ids[] = $id;
                }
            }
            if ($ids === []) {
                return null;
            }
            $videos = $this->api->videos($ids, $credential->secret);
            foreach (is_array($videos['items'] ?? null) ? $videos['items'] : [] as $candidate) {
                $candidate = is_array($candidate) ? $candidate : [];
                $id = $candidate['id'] ?? null;
                if (!is_string($id) || !in_array($id, $ids, true)) {
                    continue;
                }
                $snippet = is_array($candidate['snippet'] ?? null) ? $candidate['snippet'] : [];
                $tags = is_array($snippet['tags'] ?? null) ? $snippet['tags'] : [];
                if (($snippet['channelId'] ?? null) === $externalChannelId && in_array($marker, $tags, true)) {
                    if ($this->sessions->find($request->idempotencyKey) === null) {
                        $this->sessions->begin($request->idempotencyKey, 'youtube', $id, null);
                    } else {
                        $this->sessions->processing($request->idempotencyKey, $id);
                    }
                    return new PublishResult($id, 'https://www.youtube.com/shorts/' . rawurlencode($id), [], true);
                }
            }
        }
        return null;
    }

    private static function marker(string $key): string
    {
        return 'ezposter-' . substr(hash('sha256', $key), 0, 24);
    }

    public function delete(PublishResult $published, string $externalChannelId, Credential $credential): void
    {
        $this->api->deleteVideo($published->externalId, $credential->secret);
    }

    public function setThumbnail(string $remoteId, string $path, Credential $credential): void
    {
        $this->api->setThumbnail($remoteId, $path, $credential->secret);
    }

    public function edit(PublishResult $published, string $externalChannelId, Credential $credential, PublishRequest $request, bool $hasMedia): void
    {
        $options = $request->shortVideo;
        if ($options === null || $options->title === '') {
            throw new PlatformError(ErrorKind::Permanent, 'YouTube edit missing title.', 'Укажите заголовок YouTube Shorts.');
        }
        $this->api->updateVideo($published->externalId, [
            'title' => $options->title, 'description' => $request->text,
            'categoryId' => (string) ($options->categoryId ?? 22), 'tags' => $options->tags,
        ], $credential->secret);
    }

    public function pin(PublishResult $published, string $externalChannelId, Credential $credential, bool $pin): void
    {
        throw new PlatformError(ErrorKind::Permanent, 'YouTube pin unavailable.', 'Закрепление YouTube Shorts недоступно.');
    }

    public function healthCheck(string $externalChannelId, Credential $credential): HealthStatus
    {
        try {
            $data = $this->api->channels($credential->secret);
            $items = is_array($data['items'] ?? null) ? $data['items'] : [];
            foreach ($items as $item) {
                if (is_array($item) && ($item['id'] ?? null) === $externalChannelId) {
                    $snippet = is_array($item['snippet'] ?? null) ? $item['snippet'] : [];
                    return HealthStatus::ok(['post' => true, 'delete' => true, 'edit' => true, 'pin' => false], is_string($snippet['title'] ?? null) ? $snippet['title'] : null);
                }
            }
            return HealthStatus::broken('Канал YouTube больше не доступен этому аккаунту.', true);
        } catch (PlatformError $error) {
            return in_array($error->kind, [ErrorKind::Temporary, ErrorKind::RateLimited], true) ? HealthStatus::unknown($error->forUser()) : HealthStatus::broken($error->forUser());
        }
    }
}
