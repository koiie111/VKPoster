<?php

declare(strict_types=1);

namespace App\Domain\Automation\Import;

use App\Domain\Audit\AuditLog;
use App\Domain\Automation\AutomationException;
use App\Domain\Channel\ChannelRepository;
use App\Domain\Media\MediaException;
use App\Domain\Media\MediaFetcher;
use App\Domain\Media\MediaService;
use App\Domain\Post\PostDraft;
use App\Domain\Post\PostException;
use App\Domain\Post\PostOptions;
use App\Domain\Post\PostService;
use App\Domain\Post\PublishingContexts;
use App\Domain\Post\ScheduleTime;
use App\Domain\Post\VariantInput;
use App\Domain\Workspace\Permissions;
use App\Domain\Workspace\WorkspaceContext;
use App\Kernel\Database\Connection;
use App\Kernel\Queue\Queue;
use App\Support\Clock;
use App\Support\DbTime;
use Throwable;

/**
 * Bulk import of posts from a CSV or XLSX file, in two steps so nothing is created by surprise: `preview` reads and checks the file
 * and keeps the result (every row with its errors), `confirm` queues the creation of the good rows as drafts or as scheduled posts.
 * Files linked from the rows are downloaded through the SSRF guard when the posts are created.
 */
final class ImportService
{
    public function __construct(
        private readonly Connection $db,
        private readonly ImportRepository $batches,
        private readonly ChannelRepository $channels,
        private readonly PostService $posts,
        private readonly MediaFetcher $fetcher,
        private readonly MediaService $media,
        private readonly Permissions $permissions,
        private readonly PublishingContexts $contexts,
        private readonly Queue $queue,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @throws AutomationException|ImportException
     */
    public function preview(WorkspaceContext $context, string $path, string $filename, string $mode): ImportBatch
    {
        $this->authorize($context, $mode);
        $rows = SheetReader::read($path, $filename);
        $channels = [];
        foreach ($this->channels->all($context, $this->posts->allowedChannels($context)) as $channel) {
            $entry = ['id' => $channel->publicId, 'names' => [$channel->displayName()]];
            foreach ([$channel->displayName(), $channel->title, $channel->publicId] as $key) {
                $channels[mb_strtolower(trim($key))] ??= $entry;
            }
        }
        $parsed = ImportParser::parse($rows, $channels, $mode === ImportBatch::MODE_SCHEDULE, $this->clock->now(), $context->timezone);
        $batch = $this->batches->create($context, $filename, $mode, $parsed);
        $this->audit->record('import.previewed', $context->userId, 'import', $batch->publicId, ['rows' => count($parsed), 'ok' => $batch->okCount()], $context->workspaceId);

        return $batch;
    }

    /**
     * @throws AutomationException
     */
    public function confirm(WorkspaceContext $context, ImportBatch $batch): void
    {
        $this->authorize($context, $batch->mode);
        if ($batch->userId !== $context->userId && !$this->permissions->allows($context->role, 'workspace.settings')) {
            throw new AutomationException('Этот файл загрузил другой участник.', forbidden: true);
        }
        if ($batch->okCount() === 0) {
            throw new AutomationException('В файле нет ни одной строки, которую можно импортировать. Исправьте ошибки и загрузите файл снова.');
        }
        if (!$this->batches->transition($context, $batch, 'preview', 'processing')) {
            throw new AutomationException('Этот файл уже импортирован или импортируется.');
        }
        $this->queue->dispatch(new ImportBatchJob($batch->id));
        $this->audit->record('import.confirmed', $context->userId, 'import', $batch->publicId, ['mode' => $batch->mode], $context->workspaceId);
    }

    /**
     * Create the posts of a confirmed batch (worker).
     */
    public function run(int $batchId): void
    {
        $row = $this->db->table('import_batches')->where('id', '=', $batchId)->first();
        if ($row === null) {
            return;
        }
        $context = $this->contexts->forWorkspace((int) $row['workspace_id'], (int) $row['user_id']);
        $batch = $context === null ? null : $this->batches->find($context, (string) $row['public_id']);
        if ($context === null || $batch === null || !$this->batches->transition($context, $batch, 'processing', 'running')) {
            return;
        }
        $created = 0;
        $failed = [];
        foreach ($batch->rows as $item) {
            if (!$item->ok()) {
                continue;
            }
            try {
                $this->createPost($context, $batch->mode, $item);
                ++$created;
            } catch (PostException | MediaException $e) {
                $failed[] = ['line' => $item->line, 'message' => $e->getMessage()];
            } catch (Throwable) {
                $failed[] = ['line' => $item->line, 'message' => 'Не удалось создать пост из этой строки.'];
            }
        }
        $this->batches->finish($context, $batch, $created, $failed);
        $this->audit->record('import.finished', null, 'import', $batch->publicId, ['created' => $created, 'failed' => count($failed)], $context->workspaceId);
    }

    /**
     * The report of the rows that did not make it: those with errors in the check and those that failed on creation. A spreadsheet-safe CSV.
     */
    public function errorReport(ImportBatch $batch): string
    {
        $table = [['Строка', 'Что не так', 'Дата', 'Время', 'Каналы', 'Текст']];
        $byLine = [];
        foreach ($batch->rows as $row) {
            $byLine[$row->line] = $row;
            if (!$row->ok()) {
                $table[] = [$row->line, implode(' ', $row->errors), $row->date, $row->time, implode('; ', $row->channelNames), mb_substr($row->text, 0, 200)];
            }
        }
        foreach ($batch->failed as $failure) {
            $row = $byLine[$failure['line']] ?? null;
            $table[] = [$failure['line'], $failure['message'], $row?->date, $row?->time, $row === null ? '' : implode('; ', $row->channelNames), $row === null ? '' : mb_substr($row->text, 0, 200)];
        }

        return CsvWriter::render($table);
    }

    /**
     * The file to fill in: headers and two example rows.
     */
    public static function template(): string
    {
        return CsvWriter::render([
            ['Дата', 'Время', 'Каналы', 'Текст', 'Медиа'],
            ['20.10.2026', '10:00', 'Название канала; Другой канал', 'Текст поста. **Жирный** и _курсив_ работают.', 'https://example.com/photo.jpg'],
            ['21.10.2026', '18:30', 'Название канала', 'Второй пост без файлов', ''],
        ]);
    }

    /**
     * Housekeeping: checked files are kept for a month.
     */
    public function prune(): int
    {
        return $this->db->table('import_batches')->where('created_at', '<', DbTime::format($this->clock->now()->modify('-30 days')))->delete();
    }

    /**
     * @throws PostException|MediaException
     */
    private function createPost(WorkspaceContext $context, string $mode, ImportRow $item): void
    {
        $mediaIds = [];
        foreach ($item->media as $url) {
            $file = $this->fetcher->fetch($url);
            try {
                $mediaIds[] = $this->media->upload($context, $file['path'], $file['name'] !== '' ? $file['name'] : 'import')->media->publicId;
            } finally {
                @unlink($file['path']);
            }
        }
        $draft = new PostDraft($item->text, $mediaIds, new PostOptions(), false, array_map(static fn (string $id): VariantInput => new VariantInput($id), $item->channelIds));
        if ($mode === ImportBatch::MODE_SCHEDULE && $item->date !== null && $item->time !== null) {
            $at = ScheduleTime::parse($item->date, $item->time, $context->timezone, $this->clock->now());
            $this->posts->schedule($context, null, $draft, $at);

            return;
        }
        $this->posts->saveDraft($context, null, $draft);
    }

    /**
     * @throws AutomationException
     */
    private function authorize(WorkspaceContext $context, string $mode): void
    {
        $permission = $mode === ImportBatch::MODE_SCHEDULE ? 'posts.publish' : 'posts.draft';
        if (!$this->permissions->allows($context->role, $permission)) {
            throw new AutomationException($mode === ImportBatch::MODE_SCHEDULE ? 'Планировать посты могут редакторы и администраторы. Импортируйте файл в черновики.' : 'У вас нет права создавать посты.', forbidden: true);
        }
    }
}
