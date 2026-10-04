<?php

declare(strict_types=1);

namespace App\Integrations\Social\Max;

use App\Integrations\Social\Contracts\ChannelInfo;
use App\Integrations\Social\Contracts\ErrorKind;
use App\Integrations\Social\Contracts\PlatformError;

/**
 * Answers "what is this chat and what may our bot do there": used when connecting a channel and by the health check.
 */
final class MaxInspector
{
    /**
     * Turns what a person typed (`-72001234567`, `@name`, `name`, `max.ru/name`, `https://max.ru/name`) into a chat reference:
     * an int for a chat id, a string for a public link name, or null when it cannot be one (private invite links hide the channel).
     */
    public static function parseReference(string $input): int|string|null
    {
        $input = trim($input);
        if (preg_match('/^-?\d{5,20}$/', $input) === 1) {
            return (int) $input;
        }
        if (preg_match('~^(?:https?://)?(?:www\.)?max\.ru/([A-Za-z0-9][A-Za-z0-9_.\-]{2,63})/?$~i', $input, $m) === 1 && strtolower($m[1]) !== 'join') {
            return $m[1];
        }
        if (preg_match('/^@?([A-Za-z0-9][A-Za-z0-9_.\-]{2,63})$/', $input, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * @param int|string $chat chat id, or the link name of a public chat
     * @throws PlatformError when the chat is unknown, closed, not a channel or group, or the bot may not post there
     */
    public function inspect(MaxClient $client, int|string $chat): ChannelInfo
    {
        $info = is_int($chat) ? $client->getChat($chat) : $this->byLink($client, $chat);
        $type = is_string($info['type'] ?? null) ? $info['type'] : '';
        if (!in_array($type, ['channel', 'chat'], true)) {
            throw new PlatformError(ErrorKind::Permanent, 'MAX chat is not a channel or group.', 'Это не канал и не группа. Укажите канал или группу, в которой бот администратор.');
        }
        $id = $info['chat_id'] ?? null;
        if (!is_int($id)) {
            throw new PlatformError(ErrorKind::Permanent, 'MAX returned a chat without an id.');
        }
        self::assertActive($info);

        $rights = self::rights($client->getMembership($id), $type);
        if (!$rights['post']) {
            throw new PlatformError(ErrorKind::Auth, 'The bot may not post in this chat.', $type === 'channel'
                ? 'Бот добавлен в канал, но не может публиковать. Откройте «Администраторы» канала и включите боту право на публикацию сообщений.'
                : 'Бот не администратор этой группы. Назначьте бота администратором, чтобы он мог писать.');
        }

        $username = self::usernameFromLink($info['link'] ?? null);
        $title = is_string($info['title'] ?? null) && $info['title'] !== '' ? $info['title'] : ($username !== null ? '@' . $username : 'Канал ' . $id);

        return new ChannelInfo((string) $id, mb_substr($title, 0, 255), $username, $type === 'channel' ? 'channel' : 'group', $rights);
    }

    /**
     * A chat that is gone for us (the bot left or was removed, the chat was closed) is a permanent problem of the channel.
     *
     * @param array<string, mixed> $chat
     * @throws PlatformError
     */
    public static function assertActive(array $chat): void
    {
        $status = is_string($chat['status'] ?? null) ? $chat['status'] : 'active';
        if ($status !== 'active') {
            throw new PlatformError(ErrorKind::Auth, 'MAX chat status is ' . $status . '.', 'Бота убрали из этого канала или канал закрыт. Добавьте бота администратором снова.', null, 403);
        }
    }

    /**
     * What a membership object says the bot may do. In a channel only administrators can post; in a group chat any member can write.
     *
     * @param array<string, mixed> $member
     * @return array{post: bool, edit: bool, delete: bool, pin: bool}
     */
    public static function rights(array $member, string $chatType): array
    {
        if (($member['is_owner'] ?? false) === true) {
            return ['post' => true, 'edit' => true, 'delete' => true, 'pin' => true];
        }
        $isAdmin = ($member['is_admin'] ?? false) === true;
        if (!$isAdmin) {
            return ['post' => $chatType === 'chat', 'edit' => false, 'delete' => false, 'pin' => false];
        }
        $permissions = $member['permissions'] ?? null;
        if (!is_array($permissions)) {
            // An administrator without a permission list: nothing says otherwise, so trust the role and let a refusal show it.
            return ['post' => true, 'edit' => true, 'delete' => true, 'pin' => true];
        }
        $has = static fn (string $name): bool => in_array($name, $permissions, true);

        return ['post' => $has('write'), 'edit' => $has('edit'), 'delete' => $has('delete'), 'pin' => $has('pin_message')];
    }

    /**
     * `https://max.ru/mychannel` => `mychannel`; invite links (`/join/…`) and anything else => null.
     */
    public static function usernameFromLink(mixed $link): ?string
    {
        if (!is_string($link) || preg_match('~^(?:https?://)?(?:www\.)?max\.ru/([A-Za-z0-9][A-Za-z0-9_.\-]{2,63})/?$~i', $link, $m) !== 1 || strtolower($m[1]) === 'join') {
            return null;
        }

        return $m[1];
    }

    /**
     * @return array<string, mixed>
     * @throws PlatformError
     */
    private function byLink(MaxClient $client, string $name): array
    {
        // The documentation does not say whether the link is written with the "@"; try the bare name, then the other form.
        try {
            return $client->getChatByLink($name);
        } catch (PlatformError $e) {
            if ($e->platformCode !== 404) {
                throw $e;
            }

            return $client->getChatByLink('@' . $name);
        }
    }
}
