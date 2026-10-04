<?php

declare(strict_types=1);

namespace App\Integrations\Social\Telegram;

use App\Integrations\Social\Contracts\ChannelInfo;
use App\Integrations\Social\Contracts\ErrorKind;
use App\Integrations\Social\Contracts\PlatformError;

/**
 * Answers "what is this chat and what may our bot do there": used when connecting a channel and by the health check.
 */
final class TelegramInspector
{
    /**
     * Turns what a person typed (`@name`, `name`, `t.me/name`, `https://t.me/name`, `-1001234567890`) into a chat reference,
     * or null when it cannot be one (private invite links hide the channel).
     */
    public static function parseReference(string $input): int|string|null
    {
        $input = trim($input);
        if (preg_match('/^-?\d{6,20}$/', $input) === 1) {
            return (int) $input;
        }
        if (preg_match('~^(?:https?://)?(?:t\.me|telegram\.me)/([A-Za-z][A-Za-z0-9_]{3,31})/?$~i', $input, $m) === 1) {
            return '@' . $m[1];
        }
        if (preg_match('/^@?([A-Za-z][A-Za-z0-9_]{3,31})$/', $input, $m) === 1) {
            return '@' . $m[1];
        }

        return null;
    }

    /**
     * @param int|string $chat numeric id or `@username`
     * @param int|null $botId id of the bot behind `$client`, when already known (saves a `getMe` call)
     * @throws PlatformError when the chat is unknown, is not a channel or group, or the bot may not post there
     */
    public function inspect(TelegramClient $client, int|string $chat, ?int $botId = null): ChannelInfo
    {
        $info = $client->getChat($chat);
        $type = is_string($info['type'] ?? null) ? $info['type'] : '';
        if (!in_array($type, ['channel', 'supergroup', 'group'], true)) {
            throw new PlatformError(ErrorKind::Permanent, 'Telegram chat is not a channel or group.', 'Это не канал и не группа. Укажите канал или группу, в которой бот администратор.');
        }
        $id = $info['id'] ?? null;
        if (!is_int($id)) {
            throw new PlatformError(ErrorKind::Permanent, 'Telegram returned a chat without an id.');
        }
        $botId ??= $this->botId($client);
        $member = $client->getChatMember($id, $botId);
        $rights = self::rights($member, $type);
        if (!$rights['post']) {
            throw new PlatformError(ErrorKind::Auth, 'The bot may not post in this chat.', $type === 'channel'
                ? 'Бот добавлен в канал, но не может публиковать. Откройте «Администраторы» канала и включите боту право «Публикация сообщений».'
                : 'Бот не администратор этой группы. Назначьте бота администратором, чтобы он мог писать.');
        }

        $title = is_string($info['title'] ?? null) && $info['title'] !== '' ? $info['title'] : (is_string($info['username'] ?? null) ? '@' . $info['username'] : 'Канал ' . $id);

        return new ChannelInfo(
            (string) $id,
            mb_substr($title, 0, 255),
            is_string($info['username'] ?? null) && $info['username'] !== '' ? $info['username'] : null,
            $type === 'channel' ? 'channel' : 'group',
            $rights,
            is_array($info['photo'] ?? null) && is_string($info['photo']['small_file_id'] ?? null) ? $info['photo']['small_file_id'] : null,
        );
    }

    /**
     * What a `ChatMember` object says the bot may do.
     *
     * @param array<string, mixed> $member
     * @return array{post: bool, edit: bool, delete: bool, pin: bool}
     */
    public static function rights(array $member, string $chatType): array
    {
        $status = is_string($member['status'] ?? null) ? $member['status'] : '';
        $isAdmin = $status === 'administrator' || $status === 'creator';
        $flag = static fn (string $key): bool => ($member[$key] ?? false) === true;
        if (!$isAdmin) {
            return ['post' => false, 'edit' => false, 'delete' => false, 'pin' => false];
        }
        if ($status === 'creator') {
            return ['post' => true, 'edit' => true, 'delete' => true, 'pin' => true];
        }
        if ($chatType === 'channel') {
            return ['post' => $flag('can_post_messages'), 'edit' => $flag('can_edit_messages'), 'delete' => $flag('can_delete_messages'), 'pin' => $flag('can_edit_messages')];
        }

        return ['post' => true, 'edit' => true, 'delete' => $flag('can_delete_messages'), 'pin' => $flag('can_pin_messages')];
    }

    /**
     * Bot tokens look like `123456:ABC…`: the part before the colon is the bot's user id, so no API call is needed to learn it.
     */
    public static function botIdFromToken(#[\SensitiveParameter] string $token): ?int
    {
        return preg_match('/^(\d{5,15}):/', $token, $m) === 1 ? (int) $m[1] : null;
    }

    /**
     * @throws PlatformError
     */
    public function botId(TelegramClient $client): int
    {
        $me = $client->getMe();
        $id = $me['id'] ?? null;

        return is_int($id) ? $id : throw new PlatformError(ErrorKind::Auth, 'getMe returned no bot id.', 'Токен бота не подошёл.');
    }
}
