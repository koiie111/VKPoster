<?php

declare(strict_types=1);

namespace App\Domain\Automation\Crosspost;

use App\Domain\Audit\AuditLog;
use App\Domain\Automation\AutomationException;
use App\Domain\Billing\Entitlements;
use App\Domain\Billing\PlanLimitException;
use App\Domain\Channel\Channel;
use App\Domain\Channel\ChannelMode;
use App\Domain\Channel\ChannelRepository;
use App\Domain\Post\PostService;
use App\Domain\Workspace\Permissions;
use App\Domain\Workspace\WorkspaceContext;
use App\Integrations\Social\Contracts\Platform;
use App\Integrations\Social\PlatformRegistry;

/**
 * Creating and changing cross-posting rules, with what the plan allows (the feature, and a number of rules) and what makes sense:
 * the source is a Telegram channel connected through the service's bot (only that bot receives the channel's posts), the targets
 * are other channels the member may use.
 */
final class CrosspostService
{
    public function __construct(
        private readonly CrosspostRepository $rules,
        private readonly ChannelRepository $channels,
        private readonly PostService $posts,
        private readonly PlatformRegistry $registry,
        private readonly Entitlements $entitlements,
        private readonly Permissions $permissions,
        private readonly AuditLog $audit,
    ) {
    }

    /**
     * @param list<string> $targetPublicIds
     * @throws AutomationException
     */
    public function create(WorkspaceContext $context, string $sourcePublicId, string $name, array $targetPublicIds, CrosspostFilter $filter, CrosspostTransform $transform, int $delayMinutes, string $mode, bool $syncEdits): CrosspostRule
    {
        $this->authorize($context);
        $this->assertRoom($context);
        $source = $this->source($context, $sourcePublicId);
        [$name, $targets] = $this->check($context, $source, $name, $targetPublicIds, $delayMinutes, $mode);
        $rule = $this->rules->create($context, $source->id, $name, $filter, $transform, $delayMinutes, $mode, $syncEdits, $targets);
        $this->audit->record('crosspost.created', $context->userId, 'crosspost', $rule->publicId, ['name' => $name], $context->workspaceId);

        return $rule;
    }

    /**
     * @param list<string> $targetPublicIds
     * @throws AutomationException
     */
    public function update(WorkspaceContext $context, CrosspostRule $rule, string $name, array $targetPublicIds, CrosspostFilter $filter, CrosspostTransform $transform, int $delayMinutes, string $mode, bool $syncEdits): void
    {
        $this->authorize($context);
        $source = $this->channels->findById($context, $rule->sourceChannelId) ?? throw new AutomationException('Канал-источник отключён.');
        [$name, $targets] = $this->check($context, $source, $name, $targetPublicIds, $delayMinutes, $mode);
        $this->rules->update($context, $rule, $name, $filter, $transform, $delayMinutes, $mode, $syncEdits, $targets);
        $this->audit->record('crosspost.updated', $context->userId, 'crosspost', $rule->publicId, ['name' => $name], $context->workspaceId);
    }

    public function setEnabled(WorkspaceContext $context, CrosspostRule $rule, bool $enabled): void
    {
        $this->authorize($context);
        if ($enabled && !$rule->enabled) {
            $this->assertRoom($context, $rule);
        }
        $this->rules->setEnabled($context, $rule, $enabled);
        $this->audit->record($enabled ? 'crosspost.enabled' : 'crosspost.disabled', $context->userId, 'crosspost', $rule->publicId, [], $context->workspaceId);
    }

    public function delete(WorkspaceContext $context, CrosspostRule $rule): void
    {
        $this->authorize($context);
        $this->rules->delete($context, $rule);
        $this->audit->record('crosspost.deleted', $context->userId, 'crosspost', $rule->publicId, ['name' => $rule->name], $context->workspaceId);
    }

    /**
     * Telegram channels that can be a source: connected through the service's bot.
     *
     * @return list<Channel>
     */
    public function sources(WorkspaceContext $context): array
    {
        return array_values(array_filter($this->channels->all($context, $this->posts->allowedChannels($context)), static fn (Channel $c): bool => $c->platform === Platform::Telegram && $c->mode === ChannelMode::SharedBot));
    }

    /**
     * @throws AutomationException
     */
    private function authorize(WorkspaceContext $context): void
    {
        if (!$this->permissions->allows($context->role, 'channels.manage')) {
            throw new AutomationException('Кросспостинг настраивают владелец и администраторы.', forbidden: true);
        }
        try {
            $this->entitlements->assertFeature($context->workspaceId, 'crosspost', 'Автокросспостинг');
        } catch (PlanLimitException $e) {
            throw new AutomationException($e->getMessage(), planLimit: true);
        }
    }

    /**
     * Rules that are on count against the plan: switching one off frees a place.
     *
     * @throws AutomationException
     */
    private function assertRoom(WorkspaceContext $context, ?CrosspostRule $except = null): void
    {
        $limit = $this->entitlements->limit($context->workspaceId, 'crosspost_rules');
        if ($limit === null) {
            return;
        }
        $active = count(array_filter($this->rules->all($context), static fn (CrosspostRule $r): bool => $r->enabled && ($except === null || $r->id !== $except->id)));
        if ($active >= $limit) {
            $plan = $this->entitlements->plan($context->workspaceId);

            throw new AutomationException(sprintf('На тарифе «%s» можно включить правил кросспостинга: %d. Выключите одно из них или перейдите на тариф выше.', $plan->name, $limit), planLimit: true);
        }
    }

    /**
     * @throws AutomationException
     */
    private function source(WorkspaceContext $context, string $publicId): Channel
    {
        $source = $this->channels->find($context, $publicId);
        $allowed = $this->posts->allowedChannels($context);
        if ($source === null || ($allowed !== null && !in_array($source->id, $allowed, true))) {
            throw new AutomationException('Выберите канал, из которого переносить посты.');
        }
        if ($source->platform !== Platform::Telegram || $source->mode !== ChannelMode::SharedBot) {
            throw new AutomationException('Переносить посты можно из Telegram-канала, подключённого через бота сервиса: только он получает новые посты канала.');
        }

        return $source;
    }

    /**
     * @param list<string> $targetPublicIds
     * @return array{string, list<int>}
     * @throws AutomationException
     */
    private function check(WorkspaceContext $context, Channel $source, string $name, array $targetPublicIds, int $delayMinutes, string $mode): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw new AutomationException('Назовите правило: от 1 до 100 символов.');
        }
        if (!in_array($mode, [CrosspostRule::MODE_DRAFT, CrosspostRule::MODE_IMMEDIATE], true)) {
            throw new AutomationException('Выберите, что делать с постами: сохранять в черновики или публиковать.');
        }
        if ($delayMinutes < 0 || $delayMinutes > CrosspostRule::MAX_DELAY_MINUTES) {
            throw new AutomationException('Задержка — от 0 минут до 7 дней.');
        }
        $allowed = $this->posts->allowedChannels($context);
        $targets = [];
        foreach ($targetPublicIds as $publicId) {
            $channel = $this->channels->find($context, $publicId);
            if ($channel === null || ($allowed !== null && !in_array($channel->id, $allowed, true))) {
                throw new AutomationException('Один из выбранных каналов не найден или недоступен вам.');
            }
            if ($channel->id === $source->id) {
                throw new AutomationException('Нельзя переносить пост в тот же канал, откуда он пришёл.');
            }
            if (!$this->registry->isEnabled($channel->platform)) {
                throw new AutomationException('Сеть «' . $channel->platform->label() . '» сейчас отключена.');
            }
            $targets[$channel->id] = $channel->id;
        }
        if ($targets === []) {
            throw new AutomationException('Выберите хотя бы один канал, куда переносить посты.');
        }

        return [$name, array_values($targets)];
    }
}
