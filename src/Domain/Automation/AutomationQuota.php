<?php

declare(strict_types=1);

namespace App\Domain\Automation;

use App\Domain\Automation\Crosspost\CrosspostRepository;
use App\Domain\Automation\Crosspost\CrosspostRule;
use App\Domain\Automation\Rss\RssFeed;
use App\Domain\Automation\Rss\RssRepository;
use App\Domain\Billing\Entitlements;
use App\Domain\Workspace\WorkspaceContext;

/**
 * The plan's "rules" allowance (`crosspost_rules`) covers cross-posting rules and RSS feeds together, as in the price table ("1 rule" on
 * Start): both bring posts in without a person writing them. Only rules that are on count, so switching one off frees a place.
 */
final class AutomationQuota
{
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly CrosspostRepository $crosspost,
        private readonly RssRepository $rss,
    ) {
    }

    /**
     * Rules and feeds that are on.
     */
    public function used(WorkspaceContext $context, ?CrosspostRule $exceptRule = null, ?RssFeed $exceptFeed = null): int
    {
        $rules = count(array_filter($this->crosspost->all($context), static fn (CrosspostRule $r): bool => $r->enabled && ($exceptRule === null || $r->id !== $exceptRule->id)));
        $feeds = count(array_filter($this->rss->all($context), static fn (RssFeed $f): bool => $f->enabled && ($exceptFeed === null || $f->id !== $exceptFeed->id)));

        return $rules + $feeds;
    }

    /**
     * @return int|null null = unlimited
     */
    public function limit(WorkspaceContext $context): ?int
    {
        return $this->entitlements->limit($context->workspaceId, 'crosspost_rules');
    }

    /**
     * @throws AutomationException when one more rule or feed would go over the plan
     */
    public function assertRoom(WorkspaceContext $context, ?CrosspostRule $exceptRule = null, ?RssFeed $exceptFeed = null): void
    {
        $limit = $this->limit($context);
        if ($limit === null || $this->used($context, $exceptRule, $exceptFeed) < $limit) {
            return;
        }
        $plan = $this->entitlements->plan($context->workspaceId);

        throw new AutomationException(sprintf('На тарифе «%s» можно включить правил кросспостинга и лент RSS вместе: %d. Выключите одно из них или перейдите на тариф выше.', $plan->name, $limit), planLimit: true);
    }
}
