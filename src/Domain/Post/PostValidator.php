<?php

declare(strict_types=1);

namespace App\Domain\Post;

use App\Domain\Channel\Channel;
use App\Domain\Media\PlatformRequirements;
use App\Domain\Workspace\WorkspaceContext;
use App\Integrations\Social\PlatformRegistry;

/**
 * Everything that can be checked before a post is planned, per channel and in Russian: the options, that the post is not empty,
 * that its files still exist and suit the platform, and the adapter's own rules (`PlatformAdapter::validate`). The same checks run
 * when the person plans a post, when they move it in the calendar, and live in the editor.
 */
final class PostValidator
{
    public function __construct(
        private readonly PlatformRegistry $registry,
        private readonly PublishRequestBuilder $builder,
        private readonly PlatformRequirements $requirements,
    ) {
    }

    /**
     * @return list<string> empty = the variant can go out
     */
    public function problems(WorkspaceContext $context, ResolvedVariant $resolved, Channel $channel, bool $requireWorkingChannel): array
    {
        $problems = [];
        if ($requireWorkingChannel && $channel->status->value !== 'active') {
            $problems[] = $channel->status->value === 'paused'
                ? 'Канал на паузе. Возобновите его в разделе «Каналы» или уберите из поста.'
                : 'Канал сейчас не работает' . ($channel->lastError !== null ? ': ' . $channel->lastError : '') . '. Переподключите его в разделе «Каналы».';
        }
        if (!$this->registry->isEnabled($channel->platform)) {
            return ['Эта соцсеть сейчас отключена.'];
        }
        $options = $resolved->options->problems();
        $adapter = $this->registry->adapter($channel->platform);
        $capabilities = $adapter->capabilities();
        $empty = trim(TextFormatter::visible($resolved->text)) === '';
        if ($empty && $resolved->mediaIds === []) {
            $problems[] = 'Добавьте текст или файл: пустой пост опубликовать нельзя.';
        }
        if ($resolved->options->pin && !$capabilities->pin) {
            $problems[] = $channel->platform->label() . ' не умеет закреплять посты.';
        }
        if ($resolved->options->deleteAfterMinutes !== null && !$capabilities->delete) {
            $problems[] = $channel->platform->label() . ' не умеет удалять посты по таймеру.';
        }
        if ($resolved->options->firstComment !== '' && !$capabilities->firstComment) {
            $problems[] = $channel->platform->label() . ' не поддерживает первый комментарий.';
        }
        $problems = [...$problems, ...$options];
        try {
            $media = $this->builder->mediaFor($context, $resolved->mediaIds);
        } catch (PostException $e) {
            return [...$problems, $e->getMessage()];
        }
        foreach ($media as $item) {
            $problems = [...$problems, ...$this->requirements->problems($item, $channel->platform->value, false)];
        }
        try {
            $built = $this->builder->build($context, $resolved, $capabilities, false);
        } catch (PostException $e) {
            return [...$problems, $e->getMessage()];
        }

        return array_values(array_unique([...$problems, ...$adapter->validate($built->request)]));
    }
}
