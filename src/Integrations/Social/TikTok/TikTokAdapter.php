<?php

declare(strict_types=1);

namespace App\Integrations\Social\TikTok;

use App\Domain\Media\MediaKind;
use App\Domain\Post\PostKind;
use App\Domain\Post\VideoProviderSessions;
use App\Integrations\Social\Contracts\Capabilities;
use App\Integrations\Social\Contracts\Credential;
use App\Integrations\Social\Contracts\ErrorKind;
use App\Integrations\Social\Contracts\HealthStatus;
use App\Integrations\Social\Contracts\Platform;
use App\Integrations\Social\Contracts\PlatformAdapter;
use App\Integrations\Social\Contracts\PlatformError;
use App\Integrations\Social\Contracts\PublishRequest;
use App\Integrations\Social\Contracts\PublishResult;
use App\Integrations\Social\Contracts\VideoProcessingAdapter;

/** Direct Post via a verified signed media URL. TikTok's publish id is persisted before polling. */
final class TikTokAdapter implements PlatformAdapter, VideoProcessingAdapter
{
    public function __construct(
        private readonly TikTokApi $api,
        private readonly VideoProviderSessions $sessions,
        private readonly string $verifiedUrlPrefix,
        private readonly string $mediaDisk,
    ) {
    }

    public function platform(): Platform
    {
        return Platform::TikTok;
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            maxText: 2200,
            maxCaption: 2200,
            maxMedia: 1,
            albums: false,
            polls: false,
            buttons: false,
            silent: false,
            pin: false,
            delete: false,
            firstComment: false,
            maxFileBytes: 1024 * 1024 * 1024,
            publicMediaUrls: true,
            shortVideo: true,
            shortVideoFields: ['privacy', 'allow_comments', 'allow_duet', 'allow_stitch', 'commercial_content', 'music_rights_accepted']
        );
    }

    public function validate(PublishRequest $request): array
    {
        $options = $request->shortVideo;
        $issues = [];
        if ($request->kind !== PostKind::ShortVideo || count($request->media) !== 1 || $request->media[0]->kind !== MediaKind::Video) {
            $issues[] = 'Для TikTok выберите одно короткое видео.';
        }
        if ($options === null || $options->privacy === '') {
            $issues[] = 'Выберите видимость TikTok без значения по умолчанию.';
        }
        if ($options === null || !$options->musicRightsAccepted) {
            $issues[] = 'Подтвердите право использовать музыку в видео TikTok.';
        }
        return $issues;
    }

    public function publish(PublishRequest $request, string $externalChannelId, Credential $credential, string $idempotencyKey): PublishResult
    {
        $issues = $this->validate($request);
        if ($issues !== []) {
            throw new PlatformError(ErrorKind::Permanent, 'TikTok request invalid.', implode(' ', $issues));
        }
        $existing = $this->sessions->find($idempotencyKey);
        if ($existing !== null) {
            if ($existing['platform'] !== 'tiktok' || $existing['remote_id'] === null) {
                throw new PlatformError(ErrorKind::UnknownOutcome, 'TikTok session incomplete.', 'Проверьте результат видео в TikTok.');
            }
            return new PublishResult($existing['remote_id'], null, [], true);
        }
        $url = $request->media[0]->url;
        if ($this->mediaDisk !== 's3' || $url === null || $this->verifiedUrlPrefix === '' || !str_starts_with($url, $this->verifiedUrlPrefix)) {
            throw new PlatformError(ErrorKind::Permanent, 'TikTok media URL is not backed by S3 or verified.', 'Для TikTok настройте S3 и подтвердите домен файла в TikTok for Developers.');
        }
        $options = $request->shortVideo;
        if ($options === null) {
            throw new \LogicException('TikTok short video options disappeared.');
        }
        $creator = $this->api->creator($credential->secret);
        $levels = is_array($creator['privacy_level_options'] ?? null) ? $creator['privacy_level_options'] : [];
        if (!in_array($options->privacy, $levels, true)) {
            throw new PlatformError(ErrorKind::Permanent, 'TikTok privacy level is unavailable.', 'Выбранная видимость больше не доступна для аккаунта TikTok. Откройте пост и выберите доступный вариант.');
        }
        foreach (['comment' => $options->allowComments, 'duet' => $options->allowDuet, 'stitch' => $options->allowStitch] as $name => $allowed) {
            if ($allowed === true && ($creator[$name . '_disabled'] ?? false) === true) {
                throw new PlatformError(ErrorKind::Permanent, 'TikTok ' . $name . ' disabled by creator.', 'Настройки TikTok изменились: разрешённое действие с видео недоступно.');
            }
        }
        $data = $this->api->startPull([
            'title' => mb_substr($request->text, 0, 2200),
            'privacy_level' => $options->privacy,
            'disable_comment' => $options->allowComments === false,
            'disable_duet' => $options->allowDuet === false,
            'disable_stitch' => $options->allowStitch === false,
            'brand_content_toggle' => $options->commercialContent,
        ], $url, $credential->secret);
        $id = $data['publish_id'] ?? null;
        if (!is_string($id) || $id === '' || strlen($id) > 255) {
            throw new PlatformError(ErrorKind::UnknownOutcome, 'TikTok direct post returned no publish id.', 'TikTok мог принять видео. Проверьте аккаунт перед повторной отправкой.');
        }
        $this->sessions->begin($idempotencyKey, 'tiktok', $id, null);
        return new PublishResult($id, null, [], true);
    }

    public function pollVideo(string $remoteId, string $externalChannelId, Credential $credential): ?PublishResult
    {
        $data = $this->api->status($remoteId, $credential->secret);
        $status = $data['status'] ?? null;
        if ($status === 'FAILED') {
            $reason = is_string($data['fail_reason'] ?? null) ? $data['fail_reason'] : 'unknown';
            throw new PlatformError(ErrorKind::Permanent, 'TikTok video failed: ' . $reason, 'TikTok не опубликовал видео (' . $reason . '). Проверьте формат и настройки аккаунта.');
        }
        if ($status === 'PUBLISH_COMPLETE') {
            $ids = is_array($data['publicaly_available_post_id'] ?? null) ? $data['publicaly_available_post_id'] : [];
            $publicId = isset($ids[0]) && is_scalar($ids[0]) ? (string) $ids[0] : $remoteId;
            return new PublishResult($publicId, null);
        }
        if (in_array($status, ['PROCESSING_DOWNLOAD', 'PROCESSING_UPLOAD', 'PROCESSING', 'PUBLISHING'], true)) {
            return null;
        }
        throw new PlatformError(ErrorKind::UnknownOutcome, 'TikTok unknown processing status.', 'Статус видео в TikTok неизвестен. Проверьте аккаунт.');
    }

    public function delete(PublishResult $published, string $externalChannelId, Credential $credential): void
    {
        throw new PlatformError(ErrorKind::Permanent, 'TikTok delete is unavailable.', 'Удалите видео вручную в TikTok.');
    }

    public function pin(PublishResult $published, string $externalChannelId, Credential $credential, bool $pin): void
    {
        throw new PlatformError(ErrorKind::Permanent, 'TikTok pin is unavailable.', 'Закрепление в TikTok недоступно.');
    }

    public function healthCheck(string $externalChannelId, Credential $credential): HealthStatus
    {
        try {
            $this->api->creator($credential->secret);
            return HealthStatus::ok(['post' => true, 'delete' => false, 'edit' => false, 'pin' => false]);
        } catch (PlatformError $e) {
            return in_array($e->kind, [ErrorKind::Temporary, ErrorKind::RateLimited], true) ? HealthStatus::unknown($e->forUser()) : HealthStatus::broken($e->forUser());
        }
    }
}
