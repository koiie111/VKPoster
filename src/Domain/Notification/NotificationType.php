<?php

declare(strict_types=1);

namespace App\Domain\Notification;

/**
 * The kinds of messages the service sends about publishing, each switchable per person and per way of delivery.
 */
enum NotificationType: string
{
    case PublishFailed = 'publish_failed';
    case ChannelProblem = 'channel_problem';
    case PublishOk = 'publish_ok';

    public function label(): string
    {
        return match ($this) {
            self::PublishFailed => 'Пост не удалось опубликовать',
            self::ChannelProblem => 'С каналом что-то не так',
            self::PublishOk => 'Пост опубликован',
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::PublishFailed => 'Расскажем, что случилось и что делать. Если не уверены, что пост вышел, тоже напишем.',
            self::ChannelProblem => 'Бота убрали из канала, не хватает прав или токен перестал работать.',
            self::PublishOk => 'Короткое сообщение о каждом вышедшем посте. Приходит только автору.',
        };
    }

    /**
     * @return array{email: bool, telegram: bool} what is on until the person chooses otherwise
     */
    public function defaults(): array
    {
        return match ($this) {
            self::PublishFailed, self::ChannelProblem => ['email' => true, 'telegram' => true],
            self::PublishOk => ['email' => false, 'telegram' => false],
        };
    }
}
