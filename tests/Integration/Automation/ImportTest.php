<?php

declare(strict_types=1);

namespace App\Tests\Integration\Automation;

use App\Domain\Automation\AutomationException;
use App\Domain\Automation\Import\ImportBatch;
use App\Domain\Automation\Import\ImportBatchJob;
use App\Domain\Automation\Import\ImportException;
use App\Domain\Automation\Import\ImportRepository;
use App\Domain\Automation\Import\ImportService;
use App\Domain\Post\PostStatus;
use App\Domain\Workspace\Role;
use App\Tests\Support\AutomationTestCase;
use App\Tests\Support\MediaFixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

#[CoversClass(ImportService::class)]
#[CoversClass(ImportRepository::class)]
#[CoversClass(ImportBatchJob::class)]
final class ImportTest extends AutomationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->clock->set('2026-10-12 06:00:00');
    }

    private function imports(): ImportService
    {
        return $this->app->container()->get(ImportService::class);
    }

    private function csv(string $body, string $name = 'posts.csv'): string
    {
        $path = $this->tempFile($body);
        self::assertFileExists($path);

        return $path;
    }

    private function rows(): array
    {
        return $this->db->select('SELECT * FROM posts ORDER BY id');
    }

    public function testAFileIsCheckedFirstAndImportedAfterConfirmation(): void
    {
        $body = "Дата;Время;Каналы;Текст;Медиа\n"
            . "15.10.2026;10:30;Тестовый канал;Первый пост;https://files.example/a.jpg\n"
            . "16.10.2026;11:00;Тестовый канал;Второй пост;\n"
            . "17.10.2026;12:00;Нет такого;Третий;\n";
        $this->http->expect('GET', 'https://files.example/a.jpg', 200, MediaFixtures::jpeg(), ['Content-Type' => 'image/jpeg']);
        $batch = $this->imports()->preview($this->context, $this->csv($body), 'posts.csv', ImportBatch::MODE_SCHEDULE);
        self::assertSame([2, 1], [$batch->okCount(), $batch->errorCount()]);
        self::assertSame([], $this->rows(), 'the check creates nothing');

        $this->imports()->confirm($this->context, $batch);
        self::assertSame([], $this->rows(), 'the posts are made by the worker');
        $this->runJobs();
        $posts = $this->rows();
        self::assertCount(2, $posts);
        self::assertSame(['scheduled', 'scheduled'], array_column($posts, 'status'));
        self::assertSame('2026-10-15 07:30:00.000000', $posts[0]['scheduled_at'], '10:30 in Moscow');
        self::assertCount(1, json_decode((string) $posts[0]['media_ids_json'], true));
        $done = $this->app->container()->get(ImportRepository::class)->find($this->context, $batch->publicId);
        self::assertSame(['done', 2], [$done?->status, $done?->createdCount]);
        $this->http->assertAllConsumed();

        $this->expectExceptionObject(new AutomationException('Этот файл уже импортирован или импортируется.'));
        $this->imports()->confirm($this->context, $batch);
    }

    public function testDraftImportNeedsNoDatesAndAnAuthorMayDoIt(): void
    {
        $author = $this->contextFor($this->workspace, $this->memberOf($this->workspace, 'author@example.com', Role::Author));
        $body = "Каналы,Текст\nТестовый канал,Черновик без даты\n";
        $batch = $this->imports()->preview($author, $this->csv($body), 'd.csv', ImportBatch::MODE_DRAFT);
        $this->imports()->confirm($author, $batch);
        $this->runJobs();
        self::assertSame('draft', $this->rows()[0]['status']);

        $this->expectException(AutomationException::class);
        $this->imports()->preview($author, $this->csv($body), 'd.csv', ImportBatch::MODE_SCHEDULE);
    }

    public function testFormulaLookingCellsAreImportedAsTextAndExportedSafely(): void
    {
        $body = "Дата;Время;Каналы;Текст\n"
            . "15.10.2026;10:00;Тестовый канал;=cmd|' /C calc'!A0\n"
            . "bad-date;10:00;Тестовый канал;=HYPERLINK(\"http://evil\")\n";
        $batch = $this->imports()->preview($this->context, $this->csv($body), 'f.csv', ImportBatch::MODE_SCHEDULE);
        self::assertSame("=cmd|' /C calc'!A0", $batch->rows[0]->text, 'text is stored as it is and never evaluated');
        $report = $this->imports()->errorReport($batch);
        self::assertStringContainsString("'=HYPERLINK", $report, 'the report shows the text of a bad row as text, not as a formula');
        self::assertStringNotContainsString(';=HYPERLINK', $report);
        self::assertStringNotContainsString('"=HYPERLINK', $report);
        self::assertStringContainsString('Дата «bad-date» не понятна', $report);
    }

    public function testRowsThatFailOnCreationAreReported(): void
    {
        $body = "Дата;Время;Каналы;Текст;Медиа\n15.10.2026;10:30;Тестовый канал;С плохой ссылкой;https://files.example/missing.jpg\n16.10.2026;10:30;Тестовый канал;Нормальный;\n";
        $this->http->expect('GET', 'https://files.example/missing.jpg', 404);
        $batch = $this->imports()->preview($this->context, $this->csv($body), 'm.csv', ImportBatch::MODE_SCHEDULE);
        $this->imports()->confirm($this->context, $batch);
        $this->runJobs();
        $done = $this->app->container()->get(ImportRepository::class)->find($this->context, $batch->publicId);
        self::assertSame(1, $done?->createdCount);
        self::assertSame(2, $done?->failed[0]['line']);
        self::assertStringContainsString('Строка', $this->imports()->errorReport($done ?? $batch));
        self::assertCount(1, $this->rows());
    }

    public function testAnXlsxFileIsReadWithoutCalculatingFormulas(): void
    {
        $sheet = new Spreadsheet();
        $active = $sheet->getActiveSheet();
        foreach (['Дата', 'Время', 'Каналы', 'Текст'] as $i => $title) {
            $active->setCellValue([$i + 1, 1], $title);
        }
        $active->setCellValueExplicit('A2', '15.10.2026', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $active->setCellValueExplicit('B2', '10:30', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $active->setCellValue('C2', 'Тестовый канал');
        $active->setCellValue('D2', '=1+1');
        $path = sys_get_temp_dir() . '/import-' . bin2hex(random_bytes(4)) . '.xlsx';
        (new Xlsx($sheet))->save($path);
        try {
            $batch = $this->imports()->preview($this->context, $path, 'posts.xlsx', ImportBatch::MODE_SCHEDULE);
        } finally {
            @unlink($path);
        }
        self::assertTrue($batch->rows[0]->ok());
        self::assertSame('=1+1', $batch->rows[0]->text, 'a formula is read as its text, not as 2');
    }

    public function testBrokenOrHugeFilesAndOthersFilesAreRefused(): void
    {
        foreach ([
            ['', 'Файл пустой'],
            ["Дата;Каналы;Текст\n", 'нет ни одной строки'],
            [str_repeat('x', 2 * 1024 * 1024 + 1), 'больше 2 МБ'],
            ["a;b\n1;2\n", 'первой строке'],
        ] as [$body, $message]) {
            try {
                $this->imports()->preview($this->context, $this->csv($body), 'x.csv', ImportBatch::MODE_DRAFT);
                self::fail('must be refused: ' . $message);
            } catch (ImportException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
        $notZip = $this->csv('not a zip at all');
        try {
            $this->imports()->preview($this->context, $notZip, 'x.xlsx', ImportBatch::MODE_DRAFT);
            self::fail('a broken xlsx');
        } catch (ImportException $e) {
            self::assertStringContainsString('Excel', $e->getMessage());
        }

        // Somebody else cannot confirm a colleague's file, and a file without good rows can not be confirmed.
        $batch = $this->imports()->preview($this->context, $this->csv("Каналы;Текст\nТестовый канал;Пост\n"), 'a.csv', ImportBatch::MODE_DRAFT);
        $editor = $this->contextFor($this->workspace, $this->memberOf($this->workspace, 'editor@example.com', Role::Editor));
        try {
            $this->imports()->confirm($editor, $batch);
            self::fail('only the uploader (or an administrator) confirms');
        } catch (AutomationException $e) {
            self::assertTrue($e->forbidden);
        }
        $none = $this->imports()->preview($this->context, $this->csv("Каналы;Текст\nНет такого;Пост\n"), 'b.csv', ImportBatch::MODE_DRAFT);
        $this->expectException(AutomationException::class);
        $this->imports()->confirm($this->context, $none);
    }

    public function testTheTemplateIsAValidFileAndPruneForgetsOldBatches(): void
    {
        $template = ImportService::template();
        self::assertStringStartsWith("\xEF\xBB\xBFДата;Время;Каналы;Текст;Медиа", $template);
        $batch = $this->imports()->preview($this->context, $this->csv(str_replace(['Название канала', 'Другой канал'], 'Тестовый канал', $template)), 'template.csv', ImportBatch::MODE_DRAFT);
        self::assertSame(2, $batch->okCount());
        $this->at('2026-12-01 00:00:00');
        self::assertSame(1, $this->imports()->prune());
    }
}
