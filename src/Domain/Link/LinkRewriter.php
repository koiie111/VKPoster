<?php

declare(strict_types=1);

namespace App\Domain\Link;

use App\Domain\Post\PostException;
use App\Domain\Post\ResolvedVariant;
use App\Domain\Workspace\WorkspaceContext;
use App\Support\Clock;

/**
 * Prepares the links of one post variant for one channel just before it goes out: refuses addresses from the blocklist, adds the UTM
 * tags of the channel's rule, then (when the workspace asked for it) swaps each address for a short one. Tags go on first, so the
 * short link leads to the tagged address. Links in the text and in the buttons are treated alike; a link that can not be shortened
 * (an address on the short domain, an internal host) stays as it is.
 *
 * With `$persist = false` (checks in the editor) nothing is stored: a sample short address of the same length stands in, so the
 * character count is right.
 */
final class LinkRewriter
{
    public function __construct(
        private readonly LinkSettings $settings,
        private readonly UtmTagger $tagger,
        private readonly ShortLinks $short,
        private readonly UrlGuard $guard,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param string $text the variant's text in the editor's markup
     * @param list<array{text: string, url: string}> $buttons
     * @return array{text: string, buttons: list<array{text: string, url: string}>}
     * @throws PostException when an address is on the blocklist
     */
    public function rewrite(WorkspaceContext $context, ResolvedVariant $resolved, string $text, array $buttons, bool $persist): array
    {
        $variant = $resolved->variant;
        $rule = $this->settings->effectiveRule($context->workspaceId, $variant->channelId);
        $shorten = $this->settings->shortens($context->workspaceId);
        $now = $this->clock->now();
        $vars = ['platform' => $variant->platform->value, 'channel' => $variant->channelName, 'date' => $now->format('Y-m-d'), 'month' => $now->format('Y-m')];

        $map = function (string $url) use ($rule, $shorten, $vars, $persist, $variant, $context): string {
            if ($this->guard->isBlocked($url)) {
                throw new PostException('Ссылка на ' . (parse_url($url, PHP_URL_HOST) ?? 'этот сайт') . ' заблокирована: сайт в списке запрещённых. Уберите её из поста.');
            }
            $tagged = $this->tagger->tagUrl($url, $rule, $vars);
            if (!$shorten || $this->guard->problem($tagged) !== null) {
                return $tagged;
            }
            if (!$persist) {
                return $this->short->sampleUrl();
            }
            try {
                return $this->short->urlFor($this->short->forVariant($context->workspaceId, $variant->id, $variant->platform->value, $tagged));
            } catch (LinkException) {
                return $tagged;
            }
        };

        $outButtons = [];
        foreach ($buttons as $button) {
            $outButtons[] = preg_match('~^https?://~i', $button['url']) === 1 ? ['text' => $button['text'], 'url' => $map($button['url'])] : $button;
        }

        return ['text' => UtmTagger::mapUrls($text, $map), 'buttons' => $outButtons];
    }
}
