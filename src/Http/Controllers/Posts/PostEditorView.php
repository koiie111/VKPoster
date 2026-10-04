<?php

declare(strict_types=1);

namespace App\Http\Controllers\Posts;

use App\Domain\Channel\Channel;
use App\Domain\Channel\ChannelRepository;
use App\Domain\Channel\ChannelStatus;
use App\Domain\Media\MediaPresenter;
use App\Domain\Media\MediaRepository;
use App\Domain\Post\Post;
use App\Domain\Post\PostDraft;
use App\Domain\Post\PostOptions;
use App\Domain\Post\PostRepository;
use App\Domain\Post\ScheduleTime;
use App\Domain\Post\VariantInput;
use App\Domain\Workspace\ChannelAccessRepository;
use App\Domain\Workspace\WorkspaceContext;
use App\Integrations\Social\PlatformRegistry;
use App\Support\Clock;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Everything the editor template needs, as plain arrays: the channels the member may use (with what each platform can do), the
 * current content (common and per channel), the library files behind the media ids, and the schedule fields in the workspace time zone.
 */
final class PostEditorView
{
    public function __construct(
        private readonly ChannelRepository $channels,
        private readonly ChannelAccessRepository $access,
        private readonly PlatformRegistry $registry,
        private readonly MediaRepository $media,
        private readonly MediaPresenter $presenter,
        private readonly PostRepository $posts,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $extra further template variables (errors, the post itself, ...)
     * @return array<string, mixed>
     */
    public function build(WorkspaceContext $context, PostDraft $draft, ?Post $post, string $date, string $time, array $extra = []): array
    {
        $cards = $this->channelCards($context);
        $selected = [];
        $variants = [];
        foreach ($draft->variants as $variant) {
            $selected[strtoupper($variant->channelId)] = true;
            $variants[strtoupper($variant->channelId)] = $variant;
        }
        $ids = $draft->mediaIds;
        foreach ($draft->variants as $variant) {
            $ids = [...$ids, ...($variant->mediaIds ?? [])];
        }
        $library = [];
        foreach ($this->media->findMany($context, array_values(array_unique($ids))) as $item) {
            $library[$item->publicId] = $this->presenter->present($item);
        }
        $tiles = static function (array $list) use ($library): array {
            $result = [];
            foreach ($list as $id) {
                $result[] = $library[strtoupper($id)] ?? ['id' => strtoupper($id), 'name' => 'Файл удалён из медиатеки', 'kind' => 'missing', 'kind_label' => 'Файл', 'thumb' => null, 'url' => '', 'missing' => true];
            }

            return $result;
        };

        foreach ($cards as &$card) {
            $card['selected'] = isset($selected[$card['id']]);
            $own = $variants[$card['id']] ?? null;
            $card['variant'] = [
                'text' => $own?->text,
                'text_custom' => $own !== null && $own->text !== null,
                'media' => $tiles($own->mediaIds ?? $draft->mediaIds),
                'media_custom' => $own !== null && $own->mediaIds !== null,
                'options' => self::optionsView($own->options ?? $draft->options),
                'options_custom' => $own !== null && $own->options !== null,
            ];
        }
        unset($card);

        $now = $this->clock->now();
        $tz = $context->timezone;

        return [
            'workspace' => $context,
            'channels' => $cards,
            'text' => $draft->text,
            'media' => $tiles($draft->mediaIds),
            'options' => self::optionsView($draft->options),
            'per_network' => $draft->perNetwork,
            'post' => $post,
            'date' => $date,
            'time' => $time,
            'timezone' => $tz,
            'timezone_label' => ScheduleTime::label($tz, $now),
            'min_date' => $now->setTimezone(new DateTimeZone($tz))->format('Y-m-d'),
            'base' => '/w/' . $context->workspacePublicId,
        ] + $extra;
    }

    /**
     * A blank draft, optionally with a date taken from the calendar.
     */
    public function blank(): PostDraft
    {
        return new PostDraft('', [], new PostOptions(), false, []);
    }

    /**
     * What the stored post looks like in the form.
     */
    public function draftOf(WorkspaceContext $context, Post $post): PostDraft
    {
        $variants = [];
        foreach ($this->posts->variants($context, $post) as $variant) {
            $channel = $variant->channelId === null ? null : $this->channels->findById($context, $variant->channelId);
            if ($channel === null) {
                continue;
            }
            $variants[] = new VariantInput($channel->publicId, $variant->text, $variant->mediaIds, $variant->options);
        }

        return new PostDraft($post->baseText, $post->mediaIds, $post->options, $post->perNetwork, $variants);
    }

    /**
     * Suggested date and time for a new post: the given day (or tomorrow) at 12:00 in the workspace time zone.
     *
     * @return array{string, string}
     */
    public function defaultSchedule(WorkspaceContext $context, ?string $day): array
    {
        $zone = new DateTimeZone($context->timezone);
        $local = $this->clock->now()->setTimezone($zone);
        if ($day !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1 && checkdate((int) substr($day, 5, 2), (int) substr($day, 8, 2), (int) substr($day, 0, 4))) {
            $candidate = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $day . ' 12:00', $zone);
            if ($candidate !== false && $candidate > $this->clock->now()) {
                return [$day, '12:00'];
            }
            if ($candidate !== false && $day === $local->format('Y-m-d')) {
                $soon = $local->modify('+1 hour');

                return [$soon->format('Y-m-d'), $soon->format('H:00')];
            }
        }
        $tomorrow = $local->modify('+1 day');

        return [$tomorrow->format('Y-m-d'), '12:00'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function channelCards(WorkspaceContext $context): array
    {
        $cards = [];
        foreach ($this->channels->all($context, $this->access->allowed($context, $context->userId)) as $channel) {
            if (!$this->registry->isEnabled($channel->platform)) {
                continue;
            }
            $caps = $this->registry->adapter($channel->platform)->capabilities();
            $cards[] = [
                'id' => $channel->publicId,
                'name' => $channel->displayName(),
                'handle' => $channel->handle(),
                'platform' => $channel->platform->value,
                'platform_label' => $channel->platform->label(),
                'badge' => $channel->platform->badgeKey(),
                'status' => $channel->status->value,
                'usable' => $channel->status === ChannelStatus::Active,
                'problem' => self::problem($channel),
                'caps' => [
                    'max_text' => $caps->maxText,
                    'max_caption' => $caps->maxCaption,
                    'max_media' => $caps->maxMedia,
                    'buttons' => $caps->buttons,
                    'silent' => $caps->silent,
                    'pin' => $caps->pin,
                    'delete' => $caps->delete,
                    'first_comment' => $caps->firstComment,
                    'disable_preview' => $caps->disablePreview,
                    'format' => $caps->textFormat,
                ],
                'selected' => false,
                'variant' => [],
            ];
        }

        return $cards;
    }

    private static function problem(Channel $channel): ?string
    {
        return match ($channel->status) {
            ChannelStatus::Active => null,
            ChannelStatus::Paused => 'на паузе',
            ChannelStatus::Error => 'нужно проверить',
            ChannelStatus::Revoked => 'нужно переподключить',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function optionsView(PostOptions $options): array
    {
        $minutes = $options->deleteAfterMinutes;
        $unit = 'hours';
        $value = '';
        if ($minutes !== null) {
            if ($minutes % 1440 === 0) {
                [$value, $unit] = [(string) intdiv($minutes, 1440), 'days'];
            } elseif ($minutes % 60 === 0) {
                [$value, $unit] = [(string) intdiv($minutes, 60), 'hours'];
            } else {
                [$value, $unit] = [(string) $minutes, 'minutes'];
            }
        }

        return [
            'buttons' => $options->buttons,
            'silent' => $options->silent,
            'disable_preview' => $options->disablePreview,
            'pin' => $options->pin,
            'delete_after' => $value,
            'delete_after_unit' => $unit,
            'first_comment' => $options->firstComment,
        ];
    }
}
