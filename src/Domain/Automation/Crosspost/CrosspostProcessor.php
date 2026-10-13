<?php

declare(strict_types=1);

namespace App\Domain\Automation\Crosspost;

use App\Domain\Channel\ChannelRepository;
use App\Domain\Channel\ChannelSystem;
use App\Domain\Media\MediaException;
use App\Domain\Media\MediaService;
use App\Domain\Post\PostDraft;
use App\Domain\Post\PostException;
use App\Domain\Post\PostOptions;
use App\Domain\Post\PostRepository;
use App\Domain\Post\PostService;
use App\Domain\Post\PublishingContexts;
use App\Domain\Post\VariantInput;
use App\Domain\Workspace\WorkspaceContext;
use App\Integrations\Social\Contracts\Platform;
use App\Integrations\Social\Contracts\PlatformError;
use App\Integrations\Social\Telegram\TelegramClient;
use App\Integrations\Social\Telegram\TelegramClientFactory;
use App\Kernel\Config;
use App\Kernel\Queue\Queue;
use App\Support\Clock;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Cross-posting from Telegram: the bot hands over every `channel_post` (`ingest`), albums are collected for a few seconds, and then
 * `process` turns the collected messages into one post per rule, for the channels the rule names.
 *
 * - A redelivered update is ignored (the buffer's unique key), and a rule never posts the same source message twice.
 * - Text keeps bold, italic, strikethrough and links; filters (hashtags, forwarded posts) and transformations (signature, links,
 *   tail) come from the rule. Photos and videos are downloaded through the bot (up to 20 MB, the Bot API limit) into the media
 *   library of the workspace; other kinds of attachment are not carried over.
 * - `draft` rules save a draft for a person to approve; `immediate` rules plan the post after the rule's delay. A post that can not be
 *   planned (a channel problem, a plan limit) is saved as a draft instead, with the reason in the rule's last note: nothing is lost.
 * - An edit of the source post is followed for rules with "sync edits" while the cross-post is a draft, planned or published; Telegram
 *   does not tell bots about deleted channel posts, so deletions can not be followed.
 *
 * Never throws for bad input: whatever arrives is untrusted, and Telegram would only resend the update.
 */
final class CrosspostProcessor
{
    private const ALBUM_WAIT_SECONDS = 5;
    private const MAX_FILE_BYTES = 20 * 1024 * 1024;
    private const MAX_FILES = 10;

    public function __construct(
        private readonly CrosspostSystem $system,
        private readonly CrosspostRepository $rules,
        private readonly ChannelSystem $channelSystem,
        private readonly ChannelRepository $channels,
        private readonly PostService $service,
        private readonly PostRepository $posts,
        private readonly MediaService $media,
        private readonly PublishingContexts $contexts,
        private readonly TelegramClientFactory $clients,
        private readonly Config $config,
        private readonly Queue $queue,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Called by the bot's update handler with the whole update. Cheap for channels without rules.
     *
     * @param array<string, mixed> $update
     */
    public function ingest(array $update): void
    {
        try {
            $raw = $update['channel_post'] ?? $update['edited_channel_post'] ?? null;
            if (!is_array($raw) || (is_array($raw['chat'] ?? null) ? $raw['chat']['type'] ?? '' : '') !== 'channel') {
                return;
            }
            $message = SourceMessage::fromTelegram($raw);
            if ($message === null || $this->ruleRows($message->chatId) === []) {
                return;
            }
            if (is_array($update['edited_channel_post'] ?? null)) {
                $this->edited($message);

                return;
            }
            if ($this->system->buffer($message)) {
                $this->queue->dispatch(new CrosspostJob($message->chatId, CrosspostSystem::groupKey($message)), $message->groupId !== null ? self::ALBUM_WAIT_SECONDS : 0);
            }
        } catch (Throwable $e) {
            $this->logger->error('Cross-posting ingest failed', ['exception' => $e]);
        }
    }

    /**
     * Turn the buffered messages of one post or album into posts, one per matching rule.
     */
    public function process(int $chatId, string $groupKey): void
    {
        $messages = $this->system->claim($chatId, $groupKey);
        if ($messages === []) {
            return;
        }
        $downloads = null;
        try {
            foreach ($this->ruleRows($chatId) as $row) {
                try {
                    $context = $this->contexts->forWorkspace($row['workspace_id'], $row['created_by']);
                    $rule = $context === null ? null : $this->rules->findById($context, $row['id']);
                    if ($context !== null && $rule !== null && $rule->enabled) {
                        $this->handle($context, $rule, $messages, $downloads);
                    }
                } catch (Throwable $e) {
                    $this->logger->error('Cross-posting rule failed', ['rule' => $row['id'], 'exception' => $e]);
                    $this->system->noteRun($row['id'], 'Не получилось перенести пост. Мы уже разбираемся.', 0);
                }
            }
        } finally {
            foreach ($downloads ?? [] as $file) {
                @unlink($file['path']);
            }
        }
    }

    /**
     * @param list<SourceMessage> $messages
     * @param list<array{path: string, name: string}>|null $downloads filled on the first rule that needs the files
     */
    private function handle(WorkspaceContext $context, CrosspostRule $rule, array $messages, ?array &$downloads): void
    {
        if (!$this->system->claimMessage($rule->id, $messages[0]->messageId)) {
            return; // this post was handled by this rule already
        }
        foreach (array_slice($messages, 1) as $other) {
            $this->system->claimMessage($rule->id, $other->messageId);
        }
        $caption = $this->captionOf($messages);
        $text = $caption->text;
        $reason = $rule->filter->reason($caption, $text);
        if ($reason !== null) {
            $this->system->noteRun($rule->id, $reason, 0);

            return;
        }
        $files = [];
        foreach ($messages as $message) {
            foreach ($message->media as $item) {
                $files[] = $item;
            }
        }
        $files = array_slice($files, 0, self::MAX_FILES);
        $markup = $rule->transform->apply(TelegramText::toMarkup($caption->text, $caption->entities));
        $mediaIds = [];
        $notes = [];
        if ($files !== []) {
            $downloads ??= $this->download($files, $notes);
            $mediaIds = $this->upload($context, $downloads, $notes);
        }
        if (trim($markup) === '' && $mediaIds === []) {
            $this->system->noteRun($rule->id, $notes !== [] ? implode(' ', $notes) : 'Пост без текста, фото и видео пропущен.', 0);

            return;
        }
        $variants = [];
        foreach ($rule->targetIds as $channelId) {
            $channel = $this->channels->findById($context, $channelId);
            if ($channel !== null) {
                $variants[] = new VariantInput($channel->publicId);
            }
        }
        if ($variants === []) {
            $this->system->noteRun($rule->id, 'Каналы, куда переносить посты, отключены.', 0);

            return;
        }
        $draft = new PostDraft($markup, $mediaIds, new PostOptions(), false, $variants);
        $post = null;
        try {
            if ($rule->mode === CrosspostRule::MODE_IMMEDIATE) {
                $at = $this->clock->now()->modify('+' . $rule->delayMinutes . ' minutes');
                try {
                    $post = $this->service->schedule($context, null, $draft, $at, $rule->delayMinutes === 0);
                } catch (PostException $e) {
                    $notes[] = 'Не удалось запланировать, сохранено в черновики: ' . $e->getMessage();
                }
            }
            $post ??= $this->service->saveDraft($context, null, $draft);
        } catch (PostException $e) {
            $this->system->noteRun($rule->id, 'Не удалось сохранить пост: ' . $e->getMessage(), 0);

            return;
        }
        $this->system->attachPost($rule->id, array_map(static fn (SourceMessage $m): int => $m->messageId, $messages), $post->id);
        $this->system->noteRun($rule->id, $notes === [] ? ($rule->mode === CrosspostRule::MODE_DRAFT ? 'Пост сохранён в черновики.' : 'Пост запланирован.') : implode(' ', $notes), 1);
    }

    /**
     * An album carries its text on one message; a single post is its own caption.
     *
     * @param non-empty-list<SourceMessage> $messages
     */
    private function captionOf(array $messages): SourceMessage
    {
        foreach ($messages as $message) {
            if (trim($message->text) !== '') {
                return $message;
            }
        }

        return $messages[0];
    }

    /**
     * @param list<array{kind: string, file_id: string, size: int}> $files
     * @param list<string> $notes
     * @return list<array{path: string, name: string}>
     */
    private function download(array $files, array &$notes): array
    {
        $token = $this->config->string('platforms.telegram.bot_token');
        $client = $token === '' ? null : $this->clients->make($token);
        $result = [];
        foreach ($files as $file) {
            if ($client === null) {
                break;
            }
            if ($file['size'] > self::MAX_FILE_BYTES) {
                $notes[] = 'Видео больше 20 МБ не переносится: Telegram не отдаёт такие файлы боту.';
                continue;
            }
            try {
                $path = $this->downloadOne($client, $file['file_id']);
                $result[] = ['path' => $path, 'name' => 'tg-' . substr(md5($file['file_id']), 0, 12) . ($file['kind'] === 'video' ? '.mp4' : '.jpg')];
            } catch (PlatformError) {
                $notes[] = 'Не удалось скачать файл из Telegram.';
            }
        }

        return $result;
    }

    /**
     * @throws PlatformError
     */
    private function downloadOne(TelegramClient $client, string $fileId): string
    {
        $bytes = $client->downloadFile($client->getFilePath($fileId), self::MAX_FILE_BYTES);
        $path = $this->media->newTempFile();
        file_put_contents($path, $bytes);

        return $path;
    }

    /**
     * @param list<array{path: string, name: string}> $downloads
     * @param list<string> $notes
     * @return list<string> public ids of the library files
     */
    private function upload(WorkspaceContext $context, array $downloads, array &$notes): array
    {
        $ids = [];
        foreach ($downloads as $file) {
            try {
                $ids[] = $this->media->upload($context, $file['path'], $file['name'])->media->publicId;
            } catch (MediaException $e) {
                $notes[] = $e->getMessage();
            }
        }

        return $ids;
    }

    private function edited(SourceMessage $message): void
    {
        $text = $message->text;
        foreach ($this->system->postsOfMessage($message->chatId, $message->messageId) as $item) {
            $header = $this->system->ruleHeader($item['rule_id']);
            if ($header === null || !$header['sync_edits'] || trim($text) === '') {
                continue;
            }
            $context = $this->contexts->forWorkspace($header['workspace_id'], $header['created_by']);
            $rule = $context === null ? null : $this->rules->findById($context, $item['rule_id']);
            $post = $context === null ? null : $this->posts->findById($context, $item['post_id']);
            if ($context === null || $rule === null || $post === null) {
                continue;
            }
            try {
                $changed = $this->service->syncText($context, $post, $rule->transform->apply(TelegramText::toMarkup($text, $message->entities)));
                $this->system->noteRun($rule->id, $changed ? 'Правка поста перенесена.' : 'Правку поста перенести не удалось: в соцсети такой пост менять нельзя.', 0);
            } catch (PostException $e) {
                $this->system->noteRun($rule->id, 'Правку поста перенести не удалось: ' . $e->getMessage(), 0);
            }
        }
    }

    /**
     * @return list<array{id: int, workspace_id: int, created_by: ?int}>
     */
    private function ruleRows(int $chatId): array
    {
        $rows = [];
        foreach ($this->channelSystem->sharedBotChannels(Platform::Telegram, (string) $chatId) as $channel) {
            array_push($rows, ...$this->system->rulesOfChannel($channel->id));
        }

        return $rows;
    }
}
