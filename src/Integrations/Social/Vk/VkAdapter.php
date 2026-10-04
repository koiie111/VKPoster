<?php

declare(strict_types=1);

namespace App\Integrations\Social\Vk;

use App\Domain\Media\MediaKind;
use App\Integrations\Social\Contracts\Capabilities;
use App\Integrations\Social\Contracts\CommentingAdapter;
use App\Integrations\Social\Contracts\Credential;
use App\Integrations\Social\Contracts\EditableAdapter;
use App\Integrations\Social\Contracts\ErrorKind;
use App\Integrations\Social\Contracts\HealthStatus;
use App\Integrations\Social\Contracts\OutcomeVerifier;
use App\Integrations\Social\Contracts\Platform;
use App\Integrations\Social\Contracts\PlatformAdapter;
use App\Integrations\Social\Contracts\PlatformError;
use App\Integrations\Social\Contracts\PublishMedia;
use App\Integrations\Social\Contracts\PublishRequest;
use App\Integrations\Social\Contracts\PublishResult;
use DateTimeImmutable;

/**
 * VKontakte: posts on the wall of a community the connected account manages. The community id is the channel's external id; the wall
 * owner is `-id`. Text is plain (VK has no markup), files go through VK's upload servers (photo, video, document), a poll is created
 * first and attached. `guid` (derived from the idempotency key) makes VK drop a repeated `wall.post` within the hour.
 *
 * Attachments are limited to ten per post, the poll counts as one. Link buttons, silent sending and preview switching do not exist in VK.
 */
final class VkAdapter implements PlatformAdapter, EditableAdapter, CommentingAdapter, OutcomeVerifier
{
    private const MAX_TEXT = 16384;
    private const MAX_ATTACHMENTS = 10;
    private const MAX_IMAGE_BYTES = 50 * 1024 * 1024;
    private const MAX_VIDEO_BYTES = 256 * 1024 * 1024;
    private const MAX_DOCUMENT_BYTES = 200 * 1024 * 1024;
    /** How many of the latest wall posts are looked through when an outcome is unknown. */
    private const VERIFY_POSTS = 20;

    public function __construct(private readonly VkApi $api, private readonly VkCommunities $communities, private readonly int $postsPerDay = 50)
    {
    }

    public function platform(): Platform
    {
        return Platform::Vk;
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(self::MAX_TEXT, self::MAX_TEXT, self::MAX_ATTACHMENTS, true, true, false, false, true, true, true, self::MAX_VIDEO_BYTES, false, 'plain', $this->postsPerDay);
    }

    public function validate(PublishRequest $request): array
    {
        $problems = [];
        $length = mb_strlen($request->text);
        $count = count($request->media) + ($request->poll !== null ? 1 : 0);

        if ($length === 0 && $count === 0) {
            $problems[] = 'Добавьте текст или файл: пустой пост во ВКонтакте не отправить.';
        }
        if ($length > self::MAX_TEXT) {
            $problems[] = sprintf('Текст длиннее лимита ВКонтакте: %d из %d символов.', $length, self::MAX_TEXT);
        }
        if ($count > self::MAX_ATTACHMENTS) {
            $problems[] = 'Во ВКонтакте можно прикрепить не больше 10 вложений к одному посту (опрос считается за одно).';
        }
        if ($request->buttons !== []) {
            $problems[] = 'ВКонтакте не умеет кнопки под постом: уберите кнопки для этого канала.';
        }
        if ($request->poll !== null) {
            $poll = $request->poll;
            if (mb_strlen($poll['question']) < 1 || mb_strlen($poll['question']) > 255) {
                $problems[] = 'Вопрос опроса во ВКонтакте — до 255 символов.';
            }
            if (count($poll['options']) < 2 || count($poll['options']) > 10) {
                $problems[] = 'В опросе ВКонтакте от 2 до 10 вариантов ответа.';
            }
            foreach ($poll['options'] as $option) {
                if (mb_strlen($option) < 1 || mb_strlen($option) > 255) {
                    $problems[] = 'Вариант ответа в опросе ВКонтакте — до 255 символов.';
                    break;
                }
            }
        }
        foreach ($request->media as $media) {
            $size = is_file($media->path) ? (int) filesize($media->path) : 0;
            $limit = match ($media->kind) {
                MediaKind::Image => self::MAX_IMAGE_BYTES,
                MediaKind::Video => self::MAX_VIDEO_BYTES,
                MediaKind::Document => self::MAX_DOCUMENT_BYTES,
            };
            if ($size > $limit) {
                $problems[] = sprintf('Файл «%s» больше %d МБ: ВКонтакте его не примет.', $media->filename, intdiv($limit, 1024 * 1024));
            }
        }

        return $problems;
    }

    public function publish(PublishRequest $request, string $externalChannelId, Credential $credential, string $idempotencyKey): PublishResult
    {
        $token = $credential->secret;
        $group = $this->groupId($externalChannelId);
        $owner = -$group;

        $attachments = [];
        foreach ($request->media as $media) {
            $attachments[] = $this->uploadOne($media, $group, $token);
        }
        if ($request->poll !== null) {
            $poll = $request->poll;
            $created = $this->api->call('polls.create', [
                'owner_id' => $owner,
                'question' => $poll['question'],
                'is_anonymous' => $poll['anonymous'],
                'is_multiple' => $poll['multiple'],
                'add_answers' => $poll['options'],
            ], $token);
            $attachments[] = 'poll' . $this->intOf($created, 'owner_id', $owner) . '_' . $this->requiredId($created, 'id');
        }

        $params = ['owner_id' => $owner, 'from_group' => 1, 'guid' => md5($idempotencyKey)];
        if ($request->text !== '') {
            $params['message'] = $request->text;
        }
        if ($attachments !== []) {
            $params['attachments'] = implode(',', $attachments);
        }
        $response = $this->api->call('wall.post', $params, $token, true);
        $postId = $response['post_id'] ?? null;
        if (!is_int($postId) || $postId <= 0) {
            throw new PlatformError(ErrorKind::UnknownOutcome, 'VK accepted wall.post but returned no post id.', 'ВКонтакте принял пост, но не вернул его номер. Проверьте стену сообщества.');
        }

        return $this->result($group, $postId);
    }

    public function delete(PublishResult $published, string $externalChannelId, Credential $credential): void
    {
        $this->api->call('wall.delete', ['owner_id' => -$this->groupId($externalChannelId), 'post_id' => (int) $published->externalId], $credential->secret);
    }

    public function pin(PublishResult $published, string $externalChannelId, Credential $credential, bool $pin): void
    {
        $this->api->call($pin ? 'wall.pin' : 'wall.unpin', ['owner_id' => -$this->groupId($externalChannelId), 'post_id' => (int) $published->externalId], $credential->secret);
    }

    public function comment(PublishResult $published, string $externalChannelId, Credential $credential, string $text): void
    {
        $group = $this->groupId($externalChannelId);
        $this->api->call('wall.createComment', ['owner_id' => -$group, 'post_id' => (int) $published->externalId, 'from_group' => $group, 'message' => $text], $credential->secret);
    }

    /**
     * Change the text of a published post. `wall.edit` drops attachments that are not passed again, so the current ones are read first and
     * handed back (links are skipped: VK builds them from the text).
     */
    public function edit(PublishResult $published, string $externalChannelId, Credential $credential, PublishRequest $request, bool $hasMedia): void
    {
        $token = $credential->secret;
        $owner = -$this->groupId($externalChannelId);
        $postId = (int) $published->externalId;
        $params = ['owner_id' => $owner, 'post_id' => $postId, 'message' => $request->text];

        $current = $this->api->call('wall.getById', ['posts' => $owner . '_' . $postId], $token);
        // Newer API versions wrap the posts in `items`, older ones return the bare list.
        $items = is_array($current['items'] ?? null) ? $current['items'] : $current;
        $post = is_array($items[0] ?? null) ? $items[0] : [];
        $kept = [];
        foreach (is_array($post['attachments'] ?? null) ? $post['attachments'] : [] as $attachment) {
            $type = is_array($attachment) && is_string($attachment['type'] ?? null) ? $attachment['type'] : '';
            $body = $type !== '' && is_array($attachment[$type] ?? null) ? $attachment[$type] : [];
            if ($type === 'link' || !is_int($body['id'] ?? null) || !is_int($body['owner_id'] ?? null)) {
                continue;
            }
            $kept[] = $type . $body['owner_id'] . '_' . $body['id'] . (is_string($body['access_key'] ?? null) && $body['access_key'] !== '' ? '_' . $body['access_key'] : '');
        }
        if ($kept !== []) {
            $params['attachments'] = implode(',', $kept);
        }
        $this->api->call('wall.edit', $params, $token);
    }

    public function healthCheck(string $externalChannelId, Credential $credential): HealthStatus
    {
        try {
            $groups = $this->communities->managedBy($credential->secret);
        } catch (PlatformError $e) {
            return $e->concernsChannel() ? HealthStatus::broken($e->forUser(), false) : HealthStatus::unknown($e->forUser());
        }
        foreach ($groups as $group) {
            if ($group['id'] !== $externalChannelId) {
                continue;
            }
            if ($group['closed']) {
                return HealthStatus::broken('Сообщество удалено или заблокировано во ВКонтакте.', true);
            }

            return HealthStatus::ok(['post' => true, 'edit' => true, 'delete' => true, 'pin' => true], $group['name']);
        }

        return HealthStatus::broken('Вы больше не администратор и не редактор этого сообщества, поэтому публиковать в него нельзя.', true);
    }

    public function findPublished(PublishRequest $request, string $externalChannelId, Credential $credential, DateTimeImmutable $since): ?PublishResult
    {
        $group = $this->groupId($externalChannelId);
        $response = $this->api->call('wall.get', ['owner_id' => -$group, 'count' => self::VERIFY_POSTS, 'filter' => 'owner'], $credential->secret);
        $items = is_array($response['items'] ?? null) ? $response['items'] : [];
        $wanted = self::normalize($request->text);
        $attachmentCount = count($request->media) + ($request->poll !== null ? 1 : 0);

        foreach ($items as $item) {
            if (!is_array($item) || !is_int($item['id'] ?? null) || !is_int($item['date'] ?? null)) {
                continue;
            }
            // A one-minute margin covers clock differences between us and VK.
            if ($item['date'] < $since->getTimestamp() - 60 || (is_int($item['from_id'] ?? null) && $item['from_id'] !== -$group)) {
                continue;
            }
            $text = self::normalize(is_string($item['text'] ?? null) ? $item['text'] : '');
            $count = is_array($item['attachments'] ?? null) ? count($item['attachments']) : 0;
            if ($wanted !== '' ? $text === $wanted : ($text === '' && $count === $attachmentCount)) {
                return $this->result($group, $item['id']);
            }
        }

        return null;
    }

    /**
     * Upload one file and return its attachment string (`photo-1_2`, `video-1_2_key`, `doc-1_2`).
     *
     * @throws PlatformError
     */
    private function uploadOne(PublishMedia $media, int $group, string $token): string
    {
        return match ($media->kind) {
            MediaKind::Image => $this->uploadPhoto($media, $group, $token),
            MediaKind::Video => $this->uploadVideo($media, $group, $token),
            MediaKind::Document => $this->uploadDocument($media, $group, $token),
        };
    }

    private function uploadPhoto(PublishMedia $media, int $group, string $token): string
    {
        $server = $this->api->call('photos.getWallUploadServer', ['group_id' => $group], $token);
        $uploaded = $this->api->upload($this->stringOf($server, 'upload_url'), 'photo', $media->path, $media->filename);
        $saved = $this->api->call('photos.saveWallPhoto', [
            'group_id' => $group,
            'server' => $uploaded['server'] ?? '',
            'photo' => $uploaded['photo'] ?? '',
            'hash' => $uploaded['hash'] ?? '',
        ], $token);
        $photo = is_array($saved[0] ?? null) ? $saved[0] : [];

        return 'photo' . $this->intOf($photo, 'owner_id', 0) . '_' . $this->requiredId($photo, 'id') . $this->accessKey($photo);
    }

    private function uploadVideo(PublishMedia $media, int $group, string $token): string
    {
        $slot = $this->api->call('video.save', ['group_id' => $group, 'name' => self::title($media->filename), 'wallpost' => 0], $token);
        $this->api->upload($this->stringOf($slot, 'upload_url'), 'video_file', $media->path, $media->filename);

        return 'video' . $this->intOf($slot, 'owner_id', -$group) . '_' . $this->requiredId($slot, 'video_id') . $this->accessKey($slot);
    }

    private function uploadDocument(PublishMedia $media, int $group, string $token): string
    {
        $server = $this->api->call('docs.getWallUploadServer', ['group_id' => $group], $token);
        $uploaded = $this->api->upload($this->stringOf($server, 'upload_url'), 'file', $media->path, $media->filename);
        $saved = $this->api->call('docs.save', ['file' => $uploaded['file'] ?? '', 'title' => $media->filename], $token);
        $doc = is_array($saved['doc'] ?? null) ? $saved['doc'] : [];

        return 'doc' . $this->intOf($doc, 'owner_id', 0) . '_' . $this->requiredId($doc, 'id') . $this->accessKey($doc);
    }

    private function result(int $group, int $postId): PublishResult
    {
        return new PublishResult((string) $postId, 'https://vk.com/wall-' . $group . '_' . $postId, [(string) $postId]);
    }

    /**
     * @throws PlatformError
     */
    private function groupId(string $externalChannelId): int
    {
        if (preg_match('/^\d+$/', $externalChannelId) !== 1 || (int) $externalChannelId <= 0) {
            throw new PlatformError(ErrorKind::Permanent, 'Bad VK community id.', 'Не получилось определить сообщество. Подключите его заново.');
        }

        return (int) $externalChannelId;
    }

    /**
     * @param array<int|string, mixed> $data
     * @throws PlatformError
     */
    private function stringOf(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new PlatformError(ErrorKind::Temporary, 'VK answer lacks ' . $key . '.', 'ВКонтакте ответил неполно. Попробуем ещё раз.');
        }

        return $value;
    }

    /**
     * @param array<int|string, mixed> $data
     */
    private function intOf(array $data, string $key, int $default): int
    {
        return is_int($data[$key] ?? null) ? $data[$key] : $default;
    }

    /**
     * @param array<int|string, mixed> $data
     * @throws PlatformError
     */
    private function requiredId(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!is_int($value) || $value <= 0) {
            throw new PlatformError(ErrorKind::Temporary, 'VK answer lacks ' . $key . '.', 'ВКонтакте ответил неполно. Попробуем ещё раз.');
        }

        return $value;
    }

    /**
     * @param array<int|string, mixed> $data
     */
    private function accessKey(array $data): string
    {
        return is_string($data['access_key'] ?? null) && $data['access_key'] !== '' ? '_' . $data['access_key'] : '';
    }

    private static function title(string $filename): string
    {
        $name = pathinfo($filename, PATHINFO_FILENAME);

        return mb_substr($name !== '' ? $name : 'Видео', 0, 128);
    }

    private static function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
