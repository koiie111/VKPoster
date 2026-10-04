<?php

declare(strict_types=1);

namespace App\Tests\Feature\Post;

use App\Domain\Post\PostStatus;
use App\Domain\Workspace\Role;
use App\Http\Controllers\Posts\CalendarController;
use App\Http\Controllers\Posts\PostController;
use App\Http\Controllers\Posts\PostEditorView;
use App\Http\Controllers\Posts\PostForm;
use App\Tests\Support\PostTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(PostController::class)]
#[CoversClass(PostForm::class)]
#[CoversClass(PostEditorView::class)]
#[CoversClass(CalendarController::class)]
final class PostPagesTest extends PostTestCase
{
    /**
     * @return array{\App\Domain\User\User, \App\Domain\Workspace\Workspace, \App\Domain\Channel\Channel}
     */
    private function ownerWithChannel(): array
    {
        [$owner, $workspace] = $this->ownerSession();
        $channel = $this->fakeChannel($workspace, $owner);

        return [$owner, $workspace, $channel];
    }

    private function postsUrl(\App\Domain\Workspace\Workspace $workspace, string $suffix = ''): string
    {
        return $this->base($workspace) . '/posts' . $suffix;
    }

    private function futureDate(): string
    {
        return $this->clock->now()->modify('+2 days')->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('Y-m-d');
    }

    public function testGuestsAreSentToLoginAndOutsidersGetNotFound(): void
    {
        [, $workspace] = $this->ownerWithWorkspace();
        $this->useBrowser();
        foreach (['/posts/new', '/calendar', '/templates'] as $path) {
            $response = $this->get($this->base($workspace) . $path);
            self::assertSame(302, $response->status, $path);
            self::assertStringStartsWith('/login', (string) $response->header('Location'));
        }
        $this->createUser('stranger@example.com');
        $this->actAs($this->app->container()->get(\App\Domain\User\UserRepository::class)->findByEmail('stranger@example.com') ?? throw new \LogicException());
        foreach (['/posts/new', '/calendar', '/templates'] as $path) {
            self::assertSame(404, $this->get($this->base($workspace) . $path)->status, 'a workspace of somebody else: ' . $path);
        }
    }

    public function testTheEditorOffersTheChannelsAndHidesPlanningFromAuthors(): void
    {
        [$owner, $workspace, $channel] = $this->ownerWithChannel();
        $page = $this->get($this->postsUrl($workspace, '/new'));
        self::assertSame(200, $page->status);
        self::assertStringContainsString($channel->title, $page->body);
        self::assertStringContainsString('Запланировать пост', $page->body);
        self::assertStringContainsString('Опубликовать сейчас', $page->body);

        $this->useBrowser();
        $this->actAs($this->memberOf($workspace, 'author@example.com', Role::Author));
        $page = $this->get($this->postsUrl($workspace, '/new'));
        self::assertSame(200, $page->status);
        self::assertStringContainsString('Сохранить черновик', $page->body);
        self::assertStringNotContainsString('Запланировать пост', $page->body);
        self::assertStringContainsString('Вы сохраняете черновик', $page->body);

        $this->useBrowser();
        $this->actAs($this->memberOf($workspace, 'viewer@example.com', Role::Viewer));
        self::assertSame(403, $this->get($this->postsUrl($workspace, '/new'))->status);
        self::assertSame(200, $this->get($this->base($workspace) . '/calendar')->status, 'a viewer may look at the calendar');
        self::assertNotNull($owner);
    }

    public function testTheEditorWithoutChannelsPointsToConnectingOne(): void
    {
        [, $workspace] = $this->ownerSession();

        $page = $this->get($this->postsUrl($workspace, '/new'));

        self::assertSame(200, $page->status);
        self::assertStringContainsString('Сначала подключите канал', $page->body);
    }

    public function testSavingADraftThenPlanningItThroughTheForm(): void
    {
        [, $workspace, $channel] = $this->ownerWithChannel();

        $saved = $this->post($this->postsUrl($workspace), ['intent' => 'draft', 'text' => 'Черновик', 'channels' => [$channel->publicId]]);
        self::assertSame(302, $saved->status);
        self::assertMatchesRegularExpression('~/posts/[0-9A-Z]{26}/edit$~', (string) $saved->header('Location'));
        $row = $this->db->select('SELECT public_id, status FROM posts')[0];
        self::assertSame('draft', $row['status']);

        $edit = $this->get((string) $saved->header('Location'));
        self::assertSame(200, $edit->status);
        self::assertStringContainsString('Черновик', $edit->body);

        $planned = $this->post($this->postsUrl($workspace, '/' . $row['public_id']), [
            'intent' => 'schedule', 'text' => 'Теперь готов', 'channels' => [$channel->publicId],
            'publish_date' => $this->futureDate(), 'publish_time' => '12:30',
        ]);
        self::assertSame(302, $planned->status);
        self::assertStringContainsString('/calendar', (string) $planned->header('Location'));
        self::assertSame(1, $this->db->select('SELECT COUNT(*) AS n FROM posts')[0]['n'], 'the draft was updated, not duplicated');
        $post = $this->db->select('SELECT status, scheduled_at, base_text FROM posts')[0];
        self::assertSame('scheduled', $post['status']);
        self::assertSame('Теперь готов', $post['base_text']);
        self::assertStringEndsWith('09:30:00.000000', (string) $post['scheduled_at'], '12:30 Moscow is 09:30 UTC');
        self::assertSame(1, $this->db->select('SELECT COUNT(*) AS n FROM publications WHERE status = ?', ['queued'])[0]['n']);
    }

    public function testPublishNowQueuesTheJob(): void
    {
        [, $workspace, $channel] = $this->ownerWithChannel();

        $response = $this->post($this->postsUrl($workspace), ['intent' => 'now', 'text' => 'Срочно', 'channels' => [$channel->publicId]]);

        self::assertSame(302, $response->status);
        self::assertSame(1, $this->db->select('SELECT COUNT(*) AS n FROM jobs WHERE queue = ?', ['publish'])[0]['n']);
        self::assertSame('scheduled', $this->db->select('SELECT status FROM posts')[0]['status']);
    }

    public function testAnInvalidFormComesBackWithEverythingTheUserTyped(): void
    {
        [, $workspace, $channel] = $this->ownerWithChannel();

        $response = $this->post($this->postsUrl($workspace), [
            'intent' => 'schedule', 'text' => str_repeat('я', 4200), 'channels' => [$channel->publicId],
            'publish_date' => $this->futureDate(), 'publish_time' => '12:00',
        ]);

        self::assertSame(422, $response->status);
        self::assertStringContainsString('лимита', $response->body);
        self::assertStringContainsString(str_repeat('я', 4200), $response->body, 'nothing the person typed is lost');
        self::assertSame(0, $this->db->select('SELECT COUNT(*) AS n FROM posts')[0]['n']);
    }

    public function testAPastTimeIsRefusedNextToTheField(): void
    {
        [, $workspace, $channel] = $this->ownerWithChannel();

        $response = $this->post($this->postsUrl($workspace), ['intent' => 'schedule', 'text' => 'Опоздали', 'channels' => [$channel->publicId], 'publish_date' => '2020-01-01', 'publish_time' => '10:00']);

        self::assertSame(422, $response->status);
        self::assertStringContainsString('Это время уже прошло', $response->body);
        self::assertStringContainsString('Опоздали', $response->body);
        self::assertSame(0, $this->db->select('SELECT COUNT(*) AS n FROM posts')[0]['n']);
    }

    public function testAnAuthorCanSaveADraftButNotPlan(): void
    {
        [, $workspace, $channel] = $this->ownerWithChannel();
        $this->useBrowser();
        $this->actAs($this->memberOf($workspace, 'author@example.com', Role::Author));

        $draft = $this->post($this->postsUrl($workspace), ['intent' => 'draft', 'text' => 'Мой черновик', 'channels' => [$channel->publicId]]);
        self::assertSame(302, $draft->status);

        $plan = $this->post($this->postsUrl($workspace), ['intent' => 'schedule', 'text' => 'Хочу сам', 'channels' => [$channel->publicId], 'publish_date' => $this->futureDate(), 'publish_time' => '12:00']);
        self::assertSame(422, $plan->status);
        self::assertStringContainsString('редакторы и администраторы', $plan->body);
        self::assertSame(1, $this->db->select('SELECT COUNT(*) AS n FROM posts')[0]['n']);
        self::assertSame(0, $this->db->select('SELECT COUNT(*) AS n FROM publications')[0]['n']);
    }

    public function testAnAuthorCannotOpenOrChangeSomebodyElsesPost(): void
    {
        [$owner, $workspace, $channel] = $this->ownerWithChannel();
        [$post] = $this->scheduled($this->contextFor($workspace, $owner), [$channel]);
        $this->useBrowser();
        $this->actAs($this->memberOf($workspace, 'author@example.com', Role::Author));

        self::assertSame(200, $this->get($this->postsUrl($workspace, '/' . $post->publicId))->status, 'looking is fine');
        $edit = $this->get($this->postsUrl($workspace, '/' . $post->publicId . '/edit'));
        self::assertSame(302, $edit->status, 'but the editor is not theirs');
        self::assertSame(403, $this->post($this->postsUrl($workspace, '/' . $post->publicId . '/cancel'))->status);
        self::assertSame(403, $this->post($this->postsUrl($workspace, '/' . $post->publicId . '/move'), ['date' => $this->futureDate()])->status);
        self::assertSame(403, $this->post($this->postsUrl($workspace, '/' . $post->publicId), ['intent' => 'draft', 'text' => 'взлом', 'channels' => [$channel->publicId]])->status);
        self::assertSame('scheduled', $this->db->select('SELECT status FROM posts')[0]['status']);
    }

    public function testEveryPostRouteIsClosedToAnotherWorkspace(): void
    {
        [$ownerA, $workspaceA] = $this->ownerWithWorkspace('a@example.com', 'Анна');
        $channelA = $this->fakeChannel($workspaceA, $ownerA);
        [$post, $publications] = $this->scheduled($this->contextFor($workspaceA, $ownerA), [$channelA]);
        [$ownerB, $workspaceB] = $this->ownerWithWorkspace('b@example.com', 'Борис');
        $this->actAs($ownerB);
        $mine = $this->postsUrl($workspaceB);
        $theirs = $this->postsUrl($workspaceA);

        // Their post through my workspace: not found.
        foreach (['', '/edit'] as $suffix) {
            self::assertSame(404, $this->get($mine . '/' . $post->publicId . $suffix)->status, 'GET ' . $suffix);
        }
        foreach (['/cancel', '/duplicate', '/delete', '/edit-published', '/publications/' . $publications[0]->publicId . '/retry', '/publications/' . $publications[0]->publicId . '/settle', '/publications/' . $publications[0]->publicId . '/remove'] as $suffix) {
            self::assertSame(404, $this->post($mine . '/' . $post->publicId . $suffix)->status, 'POST ' . $suffix);
        }
        $move = $this->post($mine . '/' . $post->publicId . '/move', ['date' => $this->futureDate()], ['Accept' => 'application/json']);
        self::assertSame(404, $move->status);
        self::assertSame(404, $this->post($mine . '/' . $post->publicId, ['intent' => 'draft', 'text' => 'x'])->status);

        // Their workspace directly: not a member.
        self::assertSame(404, $this->get($theirs . '/' . $post->publicId)->status);
        self::assertSame(404, $this->post($theirs, ['intent' => 'draft', 'text' => 'x'])->status);
        self::assertSame(404, $this->get($this->base($workspaceA) . '/calendar')->status);

        // My workspace, their channel: refused, nothing created.
        $own = $this->post($mine, ['intent' => 'draft', 'text' => 'в чужой канал', 'channels' => [$channelA->publicId]]);
        self::assertSame(422, $own->status);
        self::assertSame(1, $this->db->select('SELECT COUNT(*) AS n FROM posts')[0]['n']);
        self::assertSame('scheduled', $this->db->select('SELECT status FROM posts')[0]['status']);
    }

    public function testAutosaveCreatesADraftOnceThereIsSomethingToKeep(): void
    {
        [, $workspace, $channel] = $this->ownerWithChannel();
        $json = ['Accept' => 'application/json'];

        $empty = $this->post($this->postsUrl($workspace, '/autosave'), ['text' => '  '], $json);
        self::assertSame(200, $empty->status);
        self::assertTrue(json_decode($empty->body, true)['skipped']);
        self::assertSame(0, $this->db->select('SELECT COUNT(*) AS n FROM posts')[0]['n']);

        $first = json_decode($this->post($this->postsUrl($workspace, '/autosave'), ['text' => 'Набираю', 'channels' => [$channel->publicId]], $json)->body, true);
        self::assertTrue($first['ok']);
        self::assertSame(1, $this->db->select('SELECT COUNT(*) AS n FROM posts')[0]['n']);

        $second = json_decode($this->post($this->postsUrl($workspace, '/autosave'), ['post' => $first['id'], 'text' => 'Набираю дальше', 'channels' => [$channel->publicId]], $json)->body, true);
        self::assertSame($first['id'], $second['id']);
        self::assertSame(1, $this->db->select('SELECT COUNT(*) AS n FROM posts')[0]['n']);
        self::assertSame('Набираю дальше', $this->db->select('SELECT base_text FROM posts')[0]['base_text']);
        self::assertSame(0, $this->db->select('SELECT COUNT(*) AS n FROM audit_log WHERE action LIKE ?', ['post.%'])[0]['n'], 'autosave does not fill the journal');
    }

    public function testAutosaveNeverTouchesAPlannedPost(): void
    {
        [$owner, $workspace, $channel] = $this->ownerWithChannel();
        [$post] = $this->scheduled($this->contextFor($workspace, $owner), [$channel]);

        $response = $this->post($this->postsUrl($workspace, '/autosave'), ['post' => $post->publicId, 'text' => 'Опасная правка', 'channels' => [$channel->publicId]], ['Accept' => 'application/json']);

        self::assertSame(409, $response->status);
        self::assertSame('Привет, **мир**!', $this->db->select('SELECT base_text FROM posts')[0]['base_text']);
    }

    public function testLiveValidationReportsProblemsPerChannel(): void
    {
        [, $workspace, $channel] = $this->ownerWithChannel();

        $ok = json_decode($this->post($this->postsUrl($workspace, '/validate'), ['text' => 'Нормально', 'channels' => [$channel->publicId]], ['Accept' => 'application/json'])->body, true);
        self::assertTrue($ok['ok']);
        self::assertSame([], $ok['problems']);

        $bad = json_decode($this->post($this->postsUrl($workspace, '/validate'), ['text' => str_repeat('ы', 4100), 'channels' => [$channel->publicId]], ['Accept' => 'application/json'])->body, true);
        self::assertStringContainsString('лимита', $bad['problems'][$channel->publicId][0]);
        self::assertSame(0, $this->db->select('SELECT COUNT(*) AS n FROM posts')[0]['n'], 'validation writes nothing');
    }

    public function testThePostPageShowsEachPublicationAndItsJournal(): void
    {
        [$owner, $workspace, $channel] = $this->ownerWithChannel();
        $context = $this->contextFor($workspace, $owner);
        [$post] = $this->scheduled($context, [$channel], '+1 minute', "**Громкий** анонс\nвторая строка");
        $this->clock->advance(61);
        $this->fake->failNext(\App\Integrations\Social\Contracts\ErrorKind::Permanent, 'refused by the network');
        $this->drain();

        $page = $this->get($this->postsUrl($workspace, '/' . $post->publicId));

        self::assertSame(200, $page->status);
        $text = $this->text($page);
        self::assertStringContainsString('Не удалось', $text);
        self::assertStringContainsString('Тестовая ошибка', $text);
        self::assertStringContainsString('Журнал попыток (1)', $text);
        self::assertStringContainsString('refused by the network', $text, 'owners see the technical detail');
        self::assertStringContainsString('<b>Громкий</b>', $page->body);
        self::assertStringContainsString('Повторить', $text);
    }

    public function testNonAdministratorsDoNotSeeTechnicalDetails(): void
    {
        [$owner, $workspace, $channel] = $this->ownerWithChannel();
        $context = $this->contextFor($workspace, $owner);
        [$post] = $this->scheduled($context, [$channel], '+1 minute');
        $this->clock->advance(61);
        $this->fake->failNext(\App\Integrations\Social\Contracts\ErrorKind::Permanent, 'secret internals');
        $this->drain();
        $this->useBrowser();
        $this->actAs($this->memberOf($workspace, 'editor@example.com', Role::Editor));

        $text = $this->text($this->get($this->postsUrl($workspace, '/' . $post->publicId)));

        self::assertStringContainsString('Тестовая ошибка', $text);
        self::assertStringNotContainsString('secret internals', $text);
    }

    public function testPostTextIsEscapedOnThePostPage(): void
    {
        [$owner, $workspace, $channel] = $this->ownerWithChannel();
        $context = $this->contextFor($workspace, $owner);
        $post = $this->service()->saveDraft($context, null, $this->draft('<script>alert(1)</script> <img src=x onerror=alert(2)> [клик](https://ok.example/"onmouseover="alert(3))', [$channel]));

        $body = $this->get($this->postsUrl($workspace, '/' . $post->publicId))->body;

        self::assertStringNotContainsString('<script>alert(1)', $body);
        self::assertStringNotContainsString('<img src=x', $body);
        self::assertStringContainsString('&lt;script&gt;', $body);
        self::assertStringNotContainsString('"onmouseover=', $body, 'a quote in a link cannot break out of the attribute');
    }

    public function testCancelDuplicateAndDeleteThroughThePage(): void
    {
        [$owner, $workspace, $channel] = $this->ownerWithChannel();
        [$post] = $this->scheduled($this->contextFor($workspace, $owner), [$channel], '+1 day');
        $base = $this->postsUrl($workspace, '/' . $post->publicId);

        $this->post($base . '/duplicate');
        self::assertSame(2, $this->db->select('SELECT COUNT(*) AS n FROM posts')[0]['n']);
        self::assertSame(1, $this->db->select('SELECT COUNT(*) AS n FROM posts WHERE status = ?', ['draft'])[0]['n']);

        $this->post($base . '/cancel');
        self::assertSame('cancelled', $this->db->select('SELECT status FROM posts WHERE public_id = ?', [$post->publicId])[0]['status']);

        $this->post($base . '/delete');
        self::assertSame(1, $this->db->select('SELECT COUNT(*) AS n FROM posts')[0]['n']);
        self::assertContains('post.cancelled', $this->auditActions($workspace));
        self::assertContains('post.deleted', $this->auditActions($workspace));
    }

    public function testMovingInTheCalendarKeepsTheTimeOfDay(): void
    {
        [$owner, $workspace, $channel] = $this->ownerWithChannel();
        $post = $this->service()->schedule($this->contextFor($workspace, $owner), null, $this->draft('Переезд', [$channel]), new \DateTimeImmutable($this->futureDate() . ' 09:30:00', new \DateTimeZone('UTC')));
        $target = $this->clock->now()->modify('+9 days')->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('Y-m-d');

        $response = $this->post($this->postsUrl($workspace, '/' . $post->publicId . '/move'), ['date' => $target], ['Accept' => 'application/json']);

        self::assertSame(200, $response->status);
        self::assertTrue(json_decode($response->body, true)['ok']);
        $at = (string) $this->db->select('SELECT scheduled_at FROM posts')[0]['scheduled_at'];
        self::assertSame($target . ' 09:30:00.000000', $at, 'same time of day (12:30 Moscow = 09:30 UTC), new date');
        self::assertSame($at, (string) $this->db->select('SELECT run_at FROM publications')[0]['run_at']);
    }

    public function testMovingToThePastOrAnInvalidDayIsRefusedWithAMessage(): void
    {
        [$owner, $workspace, $channel] = $this->ownerWithChannel();
        [$post] = $this->scheduled($this->contextFor($workspace, $owner), [$channel], '+3 days');

        foreach (['2020-01-01', '2026-02-30', '', 'junk'] as $date) {
            $response = $this->post($this->postsUrl($workspace, '/' . $post->publicId . '/move'), ['date' => $date], ['Accept' => 'application/json']);
            self::assertSame(422, $response->status, $date);
            self::assertFalse(json_decode($response->body, true)['ok']);
            self::assertNotSame('', json_decode($response->body, true)['message']);
        }
    }

    public function testMovingIsForEditorsOnly(): void
    {
        [$owner, $workspace, $channel] = $this->ownerWithChannel();
        [$post] = $this->scheduled($this->contextFor($workspace, $owner), [$channel], '+3 days');
        $this->useBrowser();
        $this->actAs($this->memberOf($workspace, 'viewer@example.com', Role::Viewer));

        self::assertSame(403, $this->post($this->postsUrl($workspace, '/' . $post->publicId . '/move'), ['date' => $this->futureDate()], ['Accept' => 'application/json'])->status);
    }

    public function testTheMediaPickerListsTheLibraryAsJson(): void
    {
        [$owner, $workspace] = $this->ownerSession();
        $media = $this->libraryPicture($this->contextFor($workspace, $owner), 'logo.jpg');

        $response = $this->get($this->base($workspace) . '/media-picker?q=logo');

        self::assertSame(200, $response->status);
        $data = json_decode($response->body, true);
        self::assertSame($media->publicId, $data['items'][0]['id']);
        self::assertSame(1, $data['pages']);
        self::assertStringNotContainsString('storage', $response->body, 'storage keys never leave the server');

        $this->useBrowser();
        $this->actAs($this->memberOf($workspace, 'viewer@example.com', Role::Viewer));
        self::assertSame(403, $this->get($this->base($workspace) . '/media-picker')->status);
    }

    public function testFormParsingKeepsOnlyWhatIsValid(): void
    {
        $request = \App\Kernel\Http\Request::create('POST', '/x', body: [
            'text' => "  Привет\r\nмир  ", 'intent' => 'weird', 'per_network' => '1',
            'channels' => ['01ARZ3NDEKTSV4RRFFQ69G5FAV', 'bad id', ['nested']], 'media' => ['01ARZ3NDEKTSV4RRFFQ69G5FAW', '01ARZ3NDEKTSV4RRFFQ69G5FAW', '<script>'],
            'delete_after' => '2', 'delete_after_unit' => 'days', 'silent' => '1',
            'v' => ['01ARZ3NDEKTSV4RRFFQ69G5FAV' => ['text_custom' => '1', 'text' => 'свой', 'media_custom' => '0', 'media' => ['01ARZ3NDEKTSV4RRFFQ69G5FAW']]],
        ]);

        $form = PostForm::fromRequest($request);

        self::assertSame('draft', $form->intent, 'an unknown intent is a draft, never a publication');
        self::assertSame("Привет\nмир", $form->draft->text);
        self::assertSame(['01ARZ3NDEKTSV4RRFFQ69G5FAW'], $form->draft->mediaIds);
        self::assertSame(2880, $form->draft->options->deleteAfterMinutes);
        self::assertTrue($form->draft->options->silent);
        self::assertCount(1, $form->draft->variants);
        self::assertSame('свой', $form->draft->variants[0]->text);
        self::assertNull($form->draft->variants[0]->mediaIds, 'a variant without the switch follows the post');
        self::assertNull($form->draft->variants[0]->options);

        $off = PostForm::fromRequest(\App\Kernel\Http\Request::create('POST', '/x', body: ['channels' => ['01ARZ3NDEKTSV4RRFFQ69G5FAV'], 'v' => ['01ARZ3NDEKTSV4RRFFQ69G5FAV' => ['text_custom' => '1', 'text' => 'свой']]]));
        self::assertNull($off->draft->variants[0]->text, 'own values are dropped while "separately" is off');
    }

    public function testTheCalendarShowsPostsInTheViewsAndFiltersThem(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->actAs($owner);
        $a = $this->fakeChannel($workspace, $owner, 'fake-a', 'Канал А');
        $b = $this->fakeChannel($workspace, $owner, 'fake-b', 'Канал Б');
        $context = $this->contextFor($workspace, $owner);
        $day = $this->clock->now()->modify('+2 days');
        $this->service()->schedule($context, null, $this->draft('Пост для А', [$a]), $day);
        $this->service()->schedule($context, null, $this->draft('Пост для Б', [$b]), $day->modify('+1 hour'));
        $this->service()->saveDraft($context, null, $this->draft('Просто идея', [$a]));
        $date = $day->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('Y-m-d');
        $url = $this->base($workspace) . '/calendar';

        $month = $this->text($this->get($url . '?date=' . $date));
        self::assertStringContainsString('Пост для А', $month);
        self::assertStringContainsString('Пост для Б', $month);

        $week = $this->text($this->get($url . '?view=week&date=' . $date));
        self::assertStringContainsString('Пост для А', $week);

        $list = $this->text($this->get($url . '?view=list&date=' . $date));
        self::assertStringContainsString('Просто идея', $list, 'drafts without a date are in the list');
        self::assertStringContainsString('Черновики без даты', $list);

        $onlyB = $this->text($this->get($url . '?channel=' . $b->publicId . '&date=' . $date));
        self::assertStringContainsString('Пост для Б', $onlyB);
        self::assertStringNotContainsString('Пост для А', $onlyB);

        $none = $this->text($this->get($url . '?state[]=published&date=' . $date));
        self::assertStringContainsString('Ничего не нашлось', $none);

        $junk = $this->get($url . '?view=nope&date=garbage&channel=zzz&state[]=weird&author=1');
        self::assertSame(200, $junk->status, 'unknown filters are ignored, not fatal');
    }

    public function testTheCalendarOfARestrictedMemberShowsOnlyTheirChannels(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $mine = $this->fakeChannel($workspace, $owner, 'fake-a', 'Мой канал');
        $theirs = $this->fakeChannel($workspace, $owner, 'fake-b', 'Чужой канал');
        $ownerContext = $this->contextFor($workspace, $owner);
        $this->scheduled($ownerContext, [$mine], '+1 day', 'Для моего');
        $this->scheduled($ownerContext, [$theirs], '+1 day', 'Для чужого');
        $client = $this->memberOf($workspace, 'client@example.com', Role::Client);
        $this->app->container()->get(\App\Domain\Workspace\ChannelAccessRepository::class)->set($ownerContext, $client->id, [$mine->id]);
        $this->actAs($client);

        $page = $this->text($this->get($this->base($workspace) . '/calendar?view=list'));

        self::assertStringContainsString('Для моего', $page);
        self::assertStringNotContainsString('Для чужого', $page);
        self::assertSame(403, $this->get($this->postsUrl($workspace, '/new'))->status, 'a client cannot write posts');
    }

    public function testTheWorkspaceHomeShowsWhatIsComingUp(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->actAs($owner);
        $empty = $this->text($this->get($this->base($workspace)));
        self::assertStringContainsString('Подключите первый канал', $empty);

        $channel = $this->fakeChannel($workspace, $owner);
        $this->scheduled($this->contextFor($workspace, $owner), [$channel], '+1 day', 'Скоро выйдет');
        $home = $this->text($this->get($this->base($workspace)));
        self::assertStringContainsString('Ближайшие публикации', $home);
        self::assertStringContainsString('Скоро выйдет', $home);
    }

    public function testDisconnectingAChannelFromThePageCancelsItsPosts(): void
    {
        [$owner, $workspace, $channel] = $this->ownerWithChannel();
        [$post] = $this->scheduled($this->contextFor($workspace, $owner), [$channel], '+1 day');

        $this->post($this->channelsUrl($workspace, '/' . $channel->publicId . '/delete'));

        self::assertSame(PostStatus::Cancelled->value, $this->db->select('SELECT status FROM posts WHERE id = ?', [$post->id])[0]['status']);
    }
}
