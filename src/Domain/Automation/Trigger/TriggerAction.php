<?php

declare(strict_types=1);

namespace App\Domain\Automation\Trigger;

/**
 * What a trigger does.
 */
enum TriggerAction: string
{
    case Delete = 'delete';
    case Pin = 'pin';
    case Unpin = 'unpin';
    case PublishNext = 'publish_next';
    case PauseQueue = 'pause_queue';
    case ResumeQueue = 'resume_queue';
    case Notify = 'notify';

    public function label(): string
    {
        return match ($this) {
            self::Delete => 'удалить пост из соцсети',
            self::Pin => 'закрепить пост',
            self::Unpin => 'открепить пост',
            self::PublishNext => 'опубликовать следующий пост',
            self::PauseQueue => 'поставить очередь на паузу',
            self::ResumeQueue => 'снять очередь с паузы',
            self::Notify => 'прислать уведомление',
        };
    }

    /**
     * Whether the action works on a published post (so it needs one to act on).
     */
    public function actsOnPost(): bool
    {
        return in_array($this, [self::Delete, self::Pin, self::Unpin], true);
    }
}
