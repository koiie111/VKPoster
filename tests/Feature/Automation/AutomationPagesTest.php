<?php

declare(strict_types=1);

namespace App\Tests\Feature\Automation;

use App\Domain\Automation\Queue\PostQueue;
use App\Domain\Post\PostStatus;
use App\Domain\Workspace\Role;
use App\Http\Controllers\Automation\AutomationController;
use App\Http\Controllers\Automation\AutomationHttp;
use App\Http\Controllers\Automation\CrosspostController;
use App\Http\Controllers\Automation\ImportController;
use App\Http\Controllers\Automation\QueueController;
use App\Http\Controllers\Automation\RecurringController;
use App\Http\Controllers\Automation\RssController;
use App\Http\Controllers\Automation\TriggerController;
use App\Http\Controllers\Automation\TriggerPanel;
use App\Kernel\Http\UploadedFile;
use App\Tests\Support\AutomationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(AutomationController::class)]
#[CoversClass(AutomationHttp::class)]
#[CoversClass(QueueController::class)]
#[CoversClass(RecurringController::class)]
#[CoversClass(TriggerController::class)]
#[CoversClass(TriggerPanel::class)]
#[CoversClass(CrosspostController::class)]
#[CoversClass(RssController::class)]
#[CoversClass(ImportController::class)]
final class AutomationPagesTest extends AutomationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actAs($this->owner);
    }

    private function url(string $path): string
    {
        return $this->base($this->workspace) . $path;
    }

    private function queueFor(string $name = 'Будни'): PostQueue
    {
        return $this->weekdayQueue(null, $name);
    }

    public function testEveryPageOpensForTheOwner(): void
    {
        $queue = $this->queueFor();
        $post = $this->draftPost('Для страниц');
        $this->queues()->enqueue($this->context, $post, $queue);
        foreach ([
            '/automation' => 'Автоматизация',
            '/queues' => 'Будни',
            '/queues/new' => 'Новая очередь',
            '/queues/' . $queue->publicId => 'Для страниц',
            '/queues/' . $queue->publicId . '/edit' => 'Настройки очереди',
            '/recurring' => 'Повторяющиеся посты',
            '/crosspost' => 'Кросспостинг',
            '/rss' => 'Ленты RSS',
            '/rss/new' => 'Новая лента RSS',
            '/import' => 'Загрузка постов из файла',
            '/channels/' . $this->channel->publicId . '/triggers' => 'Если → то',
            '/posts/' . $post->publicId => 'Если → то',
        ] as $path => $needle) {
            $page = $this->get($this->url($path));
            self::assertSame(200, $page->status, $path);
            self::assertStringContainsString($needle, $page->body, $path);
        }
        // The sidebar shows the new sections.
        $home = $this->get($this->url('/automation'))->body;
        self::assertStringContainsString('>Очереди<', $home);
        self::assertStringContainsString('>Автоматизация<', $home);
    }

    public function testGuestsGoToLoginAndOutsidersGetNotFound(): void
    {
        $queue = $this->queueFor();
        $this->useBrowser();
        foreach (['/automation', '/queues', '/queues/' . $queue->publicId, '/recurring', '/crosspost', '/rss', '/import'] as $path) {
            $response = $this->get($this->url($path));
            self::assertSame(302, $response->status, $path);
            self::assertStringStartsWith('/login', (string) $response->header('Location'));
        }
        $this->actAs($this->createUser('stranger@example.com'));
        foreach (['/automation', '/queues', '/queues/' . $queue->publicId, '/import'] as $path) {
            self::assertSame(404, $this->get($this->url($path))->status, 'a workspace of somebody else: ' . $path);
        }
    }

    public function testAQueueOfAnotherWorkspaceIsNotReachableThroughYours(): void
    {
        [$other, $otherWorkspace] = $this->ownerWithWorkspace('other@example.com');
        $this->givePlan($otherWorkspace, 'pro');
        $otherContext = $this->contextFor($otherWorkspace, $other);
        $foreignChannel = $this->fakeChannel($otherWorkspace, $other, 'fake-foreign');
        $foreign = $this->queues()->create($otherContext, 'Чужая', [['weekday' => 1, 'time' => '10:00']], [$foreignChannel->publicId]);

        foreach (['/queues/' . $foreign->publicId, '/queues/' . $foreign->publicId . '/edit'] as $path) {
            self::assertSame(404, $this->get($this->url($path))->status, $path);
        }
        foreach (['/queues/' . $foreign->publicId . '/pause', '/queues/' . $foreign->publicId . '/delete', '/queues/' . $foreign->publicId] as $path) {
            self::assertSame(404, $this->post($this->url($path))->status, $path);
        }
        self::assertSame(0, (int) $this->db->select('SELECT paused FROM queues WHERE id = ?', [$foreign->id])[0]['paused']);
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS n FROM queues WHERE id = ?', [$foreign->id])[0]['n']);

        // A post of another workspace can not be put into my queue, and a trigger can not be set on it.
        $mine = $this->queueFor('Моя');
        $foreignPost = $this->service()->saveDraft($otherContext, null, $this->draft('Чужой', [$foreignChannel]));
        self::assertSame(404, $this->post($this->url('/posts/' . $foreignPost->publicId . '/queue'), ['queue' => $mine->publicId])->status);
        self::assertSame(404, $this->post($this->url('/posts/' . $foreignPost->publicId . '/triggers'), ['event' => 'published', 'action' => 'notify'])->status);
        self::assertSame(404, $this->get($this->url('/channels/' . $foreignChannel->publicId . '/triggers'))->status);
    }

    public function testStateChangingRoutesNeedACsrfToken(): void
    {
        $queue = $this->queueFor();
        foreach (['/queues/' . $queue->publicId . '/pause', '/queues/' . $queue->publicId . '/delete', '/recurring', '/crosspost', '/rss', '/import'] as $path) {
            self::assertSame(419, $this->request('POST', $this->url($path), [])->status, $path);
        }
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS n FROM queues')[0]['n']);
    }

    public function testAnAuthorSeesThePagesButCannotChangeAnything(): void
    {
        $queue = $this->queueFor();
        $this->useBrowser();
        $this->actAs($this->memberOf($this->workspace, 'author@example.com', Role::Author));
        $page = $this->get($this->url('/queues'));
        self::assertSame(200, $page->status);
        self::assertStringNotContainsString('Новая очередь', $page->body);
        self::assertSame(403, $this->post($this->url('/queues/' . $queue->publicId . '/pause'))->status);
        self::assertSame(403, $this->post($this->url('/queues/' . $queue->publicId . '/delete'))->status);
        self::assertSame(403, $this->post($this->url('/queues'), ['name' => 'Х', 'channels' => [$this->channel->publicId], 'slots' => [1 => '10:00']])->status);
        self::assertSame(403, $this->post($this->url('/recurring'), ['frequency' => 'daily'])->status);
        self::assertSame(403, $this->post($this->url('/crosspost'), ['name' => 'Х'])->status);

        $this->useBrowser();
        $this->actAs($this->memberOf($this->workspace, 'viewer@example.com', Role::Viewer));
        foreach (['/queues', '/automation', '/import', '/rss'] as $path) {
            self::assertSame(403, $this->get($this->url($path))->status, 'a viewer: ' . $path);
        }
    }

    public function testCreatingAndChangingAQueueThroughTheForm(): void
    {
        $created = $this->post($this->url('/queues'), ['name' => 'Утро и вечер', 'channels' => [$this->channel->publicId], 'slots' => [1 => '9:00, 18:30', 2 => '', 3 => '09.00', 4 => '', 5 => '', 6 => '', 7 => '']]);
        self::assertSame(302, $created->status);
        $row = $this->db->select('SELECT public_id, name FROM queues')[0];
        self::assertSame('/w/' . $this->workspace->publicId . '/queues/' . $row['public_id'], $created->header('Location'));
        self::assertSame(['1 09:00', '1 18:30', '3 09:00'], array_map(static fn (array $s): string => $s['weekday'] . ' ' . $s['at_time'], $this->db->select('SELECT weekday, at_time FROM queue_slots ORDER BY weekday, at_time')));
        $page = $this->get($created->header('Location'));
        self::assertStringContainsString('Пн 09:00, 18:30', $page->body);

        // A bad time comes back to the form with what was typed and a message.
        $bad = $this->post($this->url('/queues/' . $row['public_id']), ['name' => 'Новое имя', 'channels' => [$this->channel->publicId], 'slots' => [1 => '25:99']]);
        self::assertSame(302, $bad->status);
        $form = $this->get((string) $bad->header('Location'));
        self::assertStringContainsString('не понятно', $form->body);
        self::assertStringContainsString('Новое имя', $form->body);

        $saved = $this->post($this->url('/queues/' . $row['public_id']), ['name' => 'Новое имя', 'channels' => [$this->channel->publicId], 'slots' => [2 => '12:00']]);
        self::assertSame(302, $saved->status);
        self::assertSame('Новое имя', $this->db->select('SELECT name FROM queues')[0]['name']);
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS n FROM queue_slots')[0]['n']);
    }

    public function testPostsGoIntoAQueueFromTheEditorAndThePostPageAndMoveInIt(): void
    {
        $queue = $this->queueFor();
        $editor = $this->get($this->url('/posts/new'));
        self::assertStringContainsString('В очередь', $editor->body);

        $saved = $this->post($this->url('/posts'), ['intent' => 'queue', 'queue' => $queue->publicId, 'text' => 'Из редактора', 'channels' => [$this->channel->publicId]]);
        self::assertSame(302, $saved->status);
        self::assertStringEndsWith('/queues/' . $queue->publicId, (string) $saved->header('Location'));
        $first = $this->db->select('SELECT public_id, status FROM posts')[0];
        self::assertSame('scheduled', $first['status']);

        $draft = $this->draftPost('С карточки поста');
        $page = $this->get($this->url('/posts/' . $draft->publicId));
        self::assertStringContainsString('Поставить в очередь', $page->body);
        $queued = $this->post($this->url('/posts/' . $draft->publicId . '/queue'), ['queue' => $queue->publicId]);
        self::assertSame(302, $queued->status);
        self::assertSame(PostStatus::Scheduled, $this->fresh($draft)->status);
        self::assertStringContainsString('стоит в очереди', $this->get($this->url('/posts/' . $draft->publicId))->body);

        $before = $this->plannedAt($draft);
        $moved = $this->post($this->url('/queues/' . $queue->publicId . '/posts/' . $draft->publicId . '/move'), ['direction' => 'up']);
        self::assertSame(302, $moved->status);
        self::assertNotSame($before, $this->plannedAt($draft));

        $removed = $this->post($this->url('/queues/' . $queue->publicId . '/posts/' . $draft->publicId . '/remove'));
        self::assertSame(302, $removed->status);
        self::assertSame(PostStatus::Draft, $this->fresh($draft)->status);

        // Without a queue the editor says so instead of failing.
        $none = $this->post($this->url('/posts'), ['intent' => 'queue', 'queue' => '', 'text' => 'Без очереди', 'channels' => [$this->channel->publicId]]);
        self::assertSame(422, $none->status);
    }

    public function testPausingAndDeletingAQueueAndTheEvergreenPool(): void
    {
        $queue = $this->queueFor();
        $post = $this->draftPost('В очереди');
        $this->queues()->enqueue($this->context, $post, $queue);
        self::assertSame(302, $this->post($this->url('/queues/' . $queue->publicId . '/pause'))->status);
        self::assertStringContainsString('Очередь на паузе', $this->get($this->url('/queues/' . $queue->publicId))->body);
        self::assertSame(PostStatus::Draft, $this->fresh($post)->status);
        self::assertSame(302, $this->post($this->url('/queues/' . $queue->publicId . '/resume'))->status);
        self::assertSame(PostStatus::Scheduled, $this->fresh($post)->status);

        $pool = $this->post($this->url('/queues/' . $queue->publicId . '/pools'), ['name' => 'Полезное', 'interval' => '7', 'keep_ahead' => '2']);
        self::assertSame(302, $pool->status);
        $poolId = $this->db->select('SELECT public_id FROM evergreen_pools')[0]['public_id'];
        $evergreen = $this->draftPost('Вечнозелёный');
        self::assertSame(302, $this->post($this->url('/queues/' . $queue->publicId . '/pools/' . $poolId . '/posts'), ['post' => $evergreen->publicId])->status);
        $page = $this->get($this->url('/queues/' . $queue->publicId))->body;
        self::assertStringContainsString('Полезное', $page);
        self::assertStringContainsString('Вечнозелёный', $page);
        self::assertSame(302, $this->post($this->url('/queues/' . $queue->publicId . '/pools/' . $poolId . '/toggle'))->status);
        self::assertSame(0, (int) $this->db->select('SELECT enabled FROM evergreen_pools')[0]['enabled']);

        self::assertSame(302, $this->post($this->url('/queues/' . $queue->publicId . '/delete'))->status);
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS n FROM queues')[0]['n']);
        self::assertSame(PostStatus::Scheduled, $this->fresh($post)->status, 'the posts stay planned');
    }

    public function testRecurringRulesAreMadeAndListedAndStopped(): void
    {
        $master = $this->draftPost('Рубрика');
        $page = $this->get($this->url('/recurring?post=' . $master->publicId));
        self::assertSame(200, $page->status);
        self::assertStringContainsString('selected', $page->body);
        $made = $this->post($this->url('/recurring'), ['post' => $master->publicId, 'frequency' => 'weekdays', 'weekdays' => ['1', '3'], 'time' => '10:00', 'starts_on' => $this->clock->now()->format('Y-m-d'), 'ends_on' => '', 'max_count' => '5']);
        self::assertSame(302, $made->status);
        $list = $this->get($this->url('/recurring'))->body;
        self::assertStringContainsString('По пн, ср в 10:00, не больше 5 раз', $list);
        $id = (string) $this->db->select('SELECT public_id FROM recurring_rules')[0]['public_id'];
        self::assertSame(302, $this->post($this->url('/recurring/' . $id . '/pause'))->status);
        self::assertStringContainsString('Остановлен', $this->get($this->url('/recurring'))->body);
        self::assertSame(302, $this->post($this->url('/recurring/' . $id . '/resume'))->status);
        self::assertSame(302, $this->post($this->url('/recurring/' . $id . '/delete'))->status);
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS n FROM recurring_rules')[0]['n']);

        // A rule without days is explained, not saved.
        $bad = $this->post($this->url('/recurring'), ['post' => $master->publicId, 'frequency' => 'weekdays', 'time' => '10:00', 'starts_on' => $this->clock->now()->format('Y-m-d')]);
        self::assertSame(302, $bad->status);
        self::assertStringContainsString('день недели', $this->get($this->url('/recurring'))->body);
    }

    public function testTriggersAreAddedToAPostAndAChannelAndRemoved(): void
    {
        $post = $this->draftPost('С правилами');
        $added = $this->post($this->url('/posts/' . $post->publicId . '/triggers'), ['event' => 'published', 'action' => 'notify', 'delay' => '2', 'delay_unit' => 'hours', 'message' => 'Проверь охват']);
        self::assertSame(302, $added->status);
        $page = $this->get($this->url('/posts/' . $post->publicId))->body;
        self::assertStringContainsString('Когда пост опубликован и пройдёт 2 часа → прислать уведомление: «Проверь охват»', $page);
        self::assertSame(120, (int) $this->db->select('SELECT delay_minutes FROM triggers')[0]['delay_minutes']);

        $channel = $this->post($this->url('/channels/' . $this->channel->publicId . '/triggers'), ['event' => 'failed', 'action' => 'notify', 'message' => 'Сбой']);
        self::assertSame(302, $channel->status);
        self::assertStringContainsString('Если публикация не удалась', $this->get($this->url('/channels/' . $this->channel->publicId . '/triggers'))->body);

        $bad = $this->post($this->url('/posts/' . $post->publicId . '/triggers'), ['event' => 'failed', 'action' => 'delete']);
        self::assertSame(302, $bad->status);
        self::assertSame(2, (int) $this->db->select('SELECT COUNT(*) AS n FROM triggers')[0]['n']);

        $id = (string) $this->db->select('SELECT public_id FROM triggers WHERE post_id IS NOT NULL')[0]['public_id'];
        self::assertSame(302, $this->post($this->url('/triggers/' . $id . '/delete'), ['back' => 'post'])->status);
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS n FROM triggers')[0]['n']);
    }

    public function testCrosspostRulesAreCreatedEditedAndSwitched(): void
    {
        $source = $this->makeChannel($this->workspace, $this->owner, '-1001234567890', 'Мой канал');
        self::assertStringContainsString('Создайте правило', $this->get($this->url('/crosspost'))->body);
        $form = $this->get($this->url('/crosspost/new'));
        self::assertSame(200, $form->status);
        self::assertStringContainsString($source->title, $form->body);

        $created = $this->post($this->url('/crosspost'), [
            'name' => 'ТГ в тест', 'source' => $source->publicId, 'targets' => [$this->channel->publicId], 'include_tags' => '#новости', 'exclude_tags' => '', 'skip_forwarded' => '1',
            'signatures' => '@mychannel', 'links' => [['from' => 'a', 'to' => 'b']], 'tail' => 'Хвост', 'mode' => 'draft', 'delay' => '0', 'sync_edits' => '1',
        ]);
        self::assertSame(302, $created->status);
        $list = $this->get($this->url('/crosspost'))->body;
        self::assertStringContainsString('ТГ в тест', $list);
        self::assertStringContainsString('в черновики на согласование', $list);
        $id = (string) $this->db->select('SELECT public_id FROM crosspost_rules')[0]['public_id'];

        $edit = $this->get($this->url('/crosspost/' . $id . '/edit'));
        self::assertSame(200, $edit->status);
        self::assertStringContainsString('#новости', $edit->body);
        self::assertStringContainsString('@mychannel', $edit->body);

        self::assertSame(302, $this->post($this->url('/crosspost/' . $id), ['name' => 'Переименовано', 'targets' => [$this->channel->publicId], 'mode' => 'immediate', 'delay' => '30'])->status);
        $row = $this->db->select('SELECT name, mode, delay_minutes FROM crosspost_rules')[0];
        self::assertSame(['Переименовано', 'immediate', 30], [$row['name'], $row['mode'], (int) $row['delay_minutes']]);
        self::assertSame(302, $this->post($this->url('/crosspost/' . $id . '/disable'))->status);
        self::assertSame(0, (int) $this->db->select('SELECT enabled FROM crosspost_rules')[0]['enabled']);
        self::assertSame(302, $this->post($this->url('/crosspost/' . $id . '/delete'))->status);

        // A source that is not Telegram is explained.
        $bad = $this->post($this->url('/crosspost'), ['name' => 'Плохо', 'source' => $this->channel->publicId, 'targets' => [$this->channel->publicId], 'mode' => 'draft']);
        self::assertSame(302, $bad->status);
        self::assertStringContainsString('бота сервиса', $this->get($this->url('/crosspost/new'))->body);
    }

    public function testRssFeedsCanBeCheckedConnectedAndRemoved(): void
    {
        $xml = '<?xml version="1.0"?><rss version="2.0"><channel><title>B</title><item><title>Запись</title><link>https://blog.example/1</link><guid>1</guid><description>Кратко</description></item></channel></rss>';
        $this->http->expect('GET', 'https://blog.example/feed.xml', 200, $xml);
        $preview = $this->post($this->url('/rss/preview'), ['name' => 'Блог', 'url' => 'https://blog.example/feed.xml', 'template' => '{title}!', 'channels' => [$this->channel->publicId], 'mode' => 'draft', 'interval' => '60']);
        self::assertSame(200, $preview->status);
        self::assertStringContainsString('Так будут выглядеть', $preview->body);
        self::assertStringContainsString('Запись!', $preview->body);
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS n FROM rss_feeds')[0]['n']);

        $this->http->expect('GET', 'https://blog.example/feed.xml', 200, $xml);
        $created = $this->post($this->url('/rss'), ['name' => 'Блог', 'url' => 'https://blog.example/feed.xml', 'template' => '', 'channels' => [$this->channel->publicId], 'mode' => 'draft', 'interval' => '60']);
        self::assertSame(302, $created->status);
        self::assertStringContainsString('Блог', $this->get($this->url('/rss'))->body);
        $id = (string) $this->db->select('SELECT public_id FROM rss_feeds')[0]['public_id'];
        self::assertSame(200, $this->get($this->url('/rss/' . $id . '/edit'))->status);
        self::assertSame(302, $this->post($this->url('/rss/' . $id . '/disable'))->status);
        self::assertSame(302, $this->post($this->url('/rss/' . $id . '/delete'))->status);

        $this->http->expect('GET', 'https://blog.example/broken', 200, '<html>no</html>');
        $broken = $this->post($this->url('/rss/preview'), ['url' => 'https://blog.example/broken']);
        self::assertStringContainsString('Лента не прочиталась', $broken->body);
        $bad = $this->post($this->url('/rss/preview'), ['url' => 'file:///etc/passwd']);
        self::assertStringContainsString('http:// или https://', $bad->body);
    }

    public function testImportFromAFileEndToEnd(): void
    {
        $template = $this->get($this->url('/import/template.csv'));
        self::assertSame(200, $template->status);
        self::assertStringStartsWith('text/csv', (string) $template->header('Content-Type'));
        self::assertStringContainsString('attachment', (string) $template->header('Content-Disposition'));

        $path = $this->tempFile("Дата;Время;Каналы;Текст\n15.10.2099;10:30;Тестовый канал;Пост из файла\n16.10.2099;99:99;Тестовый канал;Плохое время\n");
        $file = new UploadedFile('posts.csv', $path, (int) filesize($path), UPLOAD_ERR_OK);
        $uploaded = $this->request('POST', $this->url('/import'), ['_token' => $this->csrfToken(), 'mode' => 'schedule'], [], ['file' => $file]);
        self::assertSame(302, $uploaded->status);
        $show = $this->get((string) $uploaded->header('Location'));
        self::assertSame(200, $show->status);
        self::assertStringContainsString('В части строк есть ошибки', $show->body);
        self::assertStringContainsString('Время «99:99» не понятно', $show->body);
        self::assertStringContainsString('Импортировать 1 пост', $show->body);
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS n FROM posts')[0]['n']);

        $errors = $this->get((string) $uploaded->header('Location') . '/errors.csv');
        self::assertSame(200, $errors->status);
        self::assertStringContainsString('Время «99:99»', $errors->body);

        $confirmed = $this->post((string) $uploaded->header('Location') . '/confirm');
        self::assertSame(302, $confirmed->status);
        $this->runJobs();
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS n FROM posts')[0]['n']);
        self::assertStringContainsString('создано постов 1', $this->get((string) $uploaded->header('Location'))->body);

        // Nothing chosen, and an unreadable file, are explained.
        $none = $this->post($this->url('/import'), ['mode' => 'draft']);
        self::assertSame(302, $none->status);
        $empty = $this->tempFile('');
        $emptyFile = new UploadedFile('empty.csv', $empty, 0, UPLOAD_ERR_OK);
        $response = $this->request('POST', $this->url('/import'), ['_token' => $this->csrfToken(), 'mode' => 'draft'], [], ['file' => $emptyFile]);
        self::assertSame($this->url('/import'), $response->header('Location'));
    }

    public function testThePagesExplainTheMissingPlan(): void
    {
        $this->givePlan($this->workspace, 'free');
        foreach (['/queues' => 'Очереди есть на тарифах', '/recurring' => 'Повторы есть на тарифах', '/crosspost' => 'Кросспостинг есть на тарифах', '/rss' => 'Ленты есть на тарифах'] as $path => $needle) {
            $page = $this->get($this->url($path));
            self::assertSame(200, $page->status, $path);
            self::assertStringContainsString($needle, $page->body, $path);
        }
        $refused = $this->post($this->url('/queues'), ['name' => 'Х', 'channels' => [$this->channel->publicId], 'slots' => [1 => '10:00']]);
        self::assertSame(302, $refused->status);
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS n FROM queues')[0]['n']);
    }
}
