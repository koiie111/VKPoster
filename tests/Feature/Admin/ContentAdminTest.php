<?php

declare(strict_types=1);

namespace App\Tests\Feature\Admin;

use App\Domain\Admin\StaffRole;
use App\Domain\Content\Announcements;
use App\Domain\Content\Documents;
use App\Domain\Content\SiteContent;
use App\Domain\Legal\LegalDocuments;
use App\Domain\Notification\MailTemplates;
use App\Http\Controllers\Admin\AnnouncementsAdminController;
use App\Http\Controllers\Admin\ContentController;
use App\Http\Controllers\Admin\MailTemplatesController;
use App\Tests\Support\AdminTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * What the owner writes on the site: documents with versions and consent, help articles, landing texts and FAQ, in-app notices, and the
 * texts of system emails.
 */
#[CoversClass(Documents::class)]
#[CoversClass(SiteContent::class)]
#[CoversClass(Announcements::class)]
#[CoversClass(MailTemplates::class)]
#[CoversClass(ContentController::class)]
#[CoversClass(AnnouncementsAdminController::class)]
#[CoversClass(MailTemplatesController::class)]
#[CoversClass(LegalDocuments::class)]
final class ContentAdminTest extends AdminTestCase
{
    public function testOnlyContentRolesEditContent(): void
    {
        foreach ([StaffRole::Support, StaffRole::Finance, StaffRole::Analyst] as $role) {
            $this->staff($role);
            foreach (['/admin/content', '/admin/content/texts', '/admin/announcements', '/admin/mail-templates'] as $path) {
                self::assertSame(403, $this->get($path)->status, $role->value . ' ' . $path);
            }
        }
        $this->staff(StaffRole::Content);
        foreach (['/admin/content', '/admin/content/texts', '/admin/content/new', '/admin/announcements', '/admin/mail-templates', '/admin/mail-templates/verify_email', '/admin/content/legal/offer', '/admin/content/help/getting-started'] as $path) {
            self::assertSame(200, $this->get($path)->status, $path);
        }
        self::assertSame(404, $this->get('/admin/content/legal/nosuchdoc')->status);
        self::assertSame(404, $this->get('/admin/mail-templates/not_a_mail')->status);
    }

    public function testDraftIsNotLiveAndAnOlderVersionIsRefused(): void
    {
        $this->ownerWithWorkspace('reader@example.com');
        $this->staff(StaffRole::Content);
        $live = $this->app->container()->get(LegalDocuments::class)->find('offer');
        self::assertNotNull($live);
        $body = $live->markdown . "\n\nНовый пункт.";

        $this->post('/admin/content', ['kind' => 'legal', 'slug' => 'offer', 'title' => $live->title, 'version' => '2099-01-01', 'required' => '1', 'body' => $body, 'action' => 'draft']);
        self::assertSame($live->markdown, $this->app->container()->get(LegalDocuments::class)->find('offer')?->markdown, 'a draft is not on the site');
        $editor = $this->get('/admin/content/legal/offer')->body;
        self::assertStringContainsString('Это черновик', $editor);
        self::assertStringContainsString('Новый пункт.', $editor);

        $this->post('/admin/content', ['kind' => 'legal', 'slug' => 'offer', 'title' => $live->title, 'version' => '2000-01-01', 'required' => '1', 'body' => $body, 'action' => 'publish']);
        self::assertSame($live->markdown, $this->app->container()->get(LegalDocuments::class)->find('offer')?->markdown, 'an older version cannot be published over a newer text');
        $this->post('/admin/content', ['kind' => 'legal', 'slug' => 'offer', 'title' => 'x', 'version' => 'not a date', 'body' => $body, 'action' => 'publish']);
        $this->post('/admin/content', ['kind' => 'legal', 'slug' => 'Bad Slug!', 'title' => 'Документ', 'version' => '2099-01-01', 'body' => $body, 'action' => 'publish']);
        self::assertSame([], $this->db->select("SELECT 1 FROM cms_pages WHERE slug = 'Bad Slug!'"));
        self::assertSame(1, (int) $this->db->select("SELECT COUNT(*) AS c FROM cms_pages WHERE status = 'draft'")[0]['c'], 'only the one draft exists');

        $this->post('/admin/content/legal/offer/discard', []);
        self::assertSame(0, (int) $this->db->select("SELECT COUNT(*) AS c FROM cms_pages")[0]['c']);
    }

    public function testPublishingANewVersionAsksEverybodyForConsentAgain(): void
    {
        [$user] = $this->ownerWithWorkspace('reader@example.com');
        $before = $this->app->container()->get(LegalDocuments::class)->consentVersion();
        $this->actAs($user);
        self::assertSame(200, $this->getApp()->status);

        $this->staff(StaffRole::Content);
        $offer = $this->app->container()->get(LegalDocuments::class)->find('offer');
        self::assertNotNull($offer);
        $this->post('/admin/content', ['kind' => 'legal', 'slug' => 'offer', 'title' => $offer->title, 'version' => '2099-12-31', 'required' => '1', 'body' => $offer->markdown . "\n\nИзменение условий.", 'action' => 'publish']);
        $after = $this->app->container()->get(LegalDocuments::class);
        self::assertSame('2099-12-31', $after->consentVersion());
        self::assertNotSame($before, $after->consentVersion());
        self::assertStringContainsString('Изменение условий.', $this->get('/legal/offer')->body, 'the site shows the new text');
        self::assertContains('admin.content_changed', $this->adminActions());

        $this->actAs($user);
        self::assertSame('/consent', $this->get('/app')->header('Location'), 'the next visit asks for the new consent');

        // History: an earlier revision goes back into a draft and can be published again.
        $this->staff(StaffRole::Content);
        $id = (int) $this->db->select("SELECT id FROM cms_pages WHERE kind = 'legal' AND slug = 'offer' ORDER BY id DESC LIMIT 1")[0]['id'];
        $this->post('/admin/content/revisions/' . $id . '/restore', ['back' => '/admin/content/legal/offer']);
        self::assertSame(1, (int) $this->db->select("SELECT COUNT(*) AS c FROM cms_pages WHERE status = 'draft'")[0]['c']);
        self::assertStringContainsString('Опубликован', $this->get('/admin/content/legal/offer')->body);
        self::assertSame(404, $this->post('/admin/content/revisions/999999/restore', [])->status);
    }

    public function testHelpArticlesCanBeAddedAndOverridden(): void
    {
        $this->staff(StaffRole::Content);
        $this->post('/admin/content', ['kind' => 'help', 'slug' => 'my-new-guide', 'title' => 'Мой новый гид', 'body' => "Первый абзац гида.\n\n- пункт один\n- пункт два", 'action' => 'publish']);
        $this->useBrowser();
        $page = $this->get('/help/my-new-guide');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('Мой новый гид', $page->body);
        self::assertStringContainsString('<li>пункт один</li>', $page->body);
        self::assertStringContainsString('Мой новый гид', $this->get('/help')->body);

        $this->staff(StaffRole::Content);
        $this->post('/admin/content', ['kind' => 'help', 'slug' => 'getting-started', 'title' => 'Начало работы (новое)', 'body' => 'Совсем другой текст.', 'action' => 'publish']);
        $this->useBrowser();
        self::assertStringContainsString('Совсем другой текст.', $this->get('/help/getting-started')->body);
    }

    public function testMarkdownCannotInjectMarkup(): void
    {
        $this->staff(StaffRole::Content);
        $preview = $this->post('/admin/content/preview', ['title' => 'T', 'body' => "<script>alert(1)</script>\n\n[x](javascript:alert(2))\n\n<img src=x onerror=alert(3)>"]);
        self::assertSame(200, $preview->status);
        self::assertStringNotContainsString('<script>alert(1)', $preview->body);
        self::assertStringNotContainsString('href="javascript:', $preview->body);
        self::assertStringNotContainsString('<img src=x', $preview->body);
        self::assertStringContainsString('&lt;script&gt;', $preview->body);
    }

    public function testLandingTextsAndFaq(): void
    {
        $this->staff(StaffRole::Content);
        $this->useBrowser();
        $default = $this->get('/');
        self::assertStringContainsString('Пишите посты заранее. Публикуем вовремя.', $default->body);

        $this->staff(StaffRole::Content);
        $this->post('/admin/content/texts', ['t' => ['hero.title' => 'Планируйте посты <b>спокойно</b>', 'pricing.title' => 'Цены', 'bogus.block' => 'x']]);
        $this->useBrowser();
        $landing = $this->get('/');
        self::assertStringContainsString('Планируйте посты &lt;b&gt;спокойно&lt;/b&gt;', $landing->body, 'a text is escaped, never markup');
        self::assertStringContainsString('>Цены<', $landing->body);
        self::assertStringContainsString('Три шага до первого поста', $landing->body, 'a block nobody changed keeps its text');

        $this->staff(StaffRole::Content);
        $this->post('/admin/content/faq', ['q' => ['Сколько стоит?', '', 'Пустой ответ'], 'a' => ['Дёшево, пробный период {trial_days} дн.', 'ответ без вопроса', '']]);
        $this->useBrowser();
        $faq = $this->get('/')->body;
        self::assertStringContainsString('Сколько стоит?', $faq);
        self::assertStringContainsString('пробный период 14 дн.', $faq);
        self::assertStringNotContainsString('Что умеет сервис?', $faq);
        self::assertStringNotContainsString('Пустой ответ', $faq);

        $this->staff(StaffRole::Content);
        $this->post('/admin/content/faq', ['q' => [''], 'a' => ['']]);
        $this->post('/admin/content/texts', ['t' => ['hero.title' => '', 'pricing.title' => '']]);
        $this->useBrowser();
        $restored = $this->get('/')->body;
        self::assertStringContainsString('Что умеет сервис?', $restored);
        self::assertStringContainsString('Пишите посты заранее. Публикуем вовремя.', $restored);
        self::assertSame('Мой {app_name}', 'Мой {app_name}');
        self::assertStringContainsString('ezposter опубликует его сам', $restored, '{app_name} is the name of the service');
    }

    public function testAnnouncementsReachTheRightPeopleAndCanBeClosed(): void
    {
        [$free, $freeSpace] = $this->ownerWithWorkspace('free@example.com', 'Бесплатный');
        [$pro, $proSpace] = $this->ownerWithWorkspace('pro@example.com', 'Платный');
        $this->givePlan($freeSpace, 'free');
        $this->givePlan($proSpace, 'pro');
        $this->fakeChannel($proSpace, $pro, 'p-1', 'Канал Про');
        $now = $this->clock->now();
        $notices = $this->app->container()->get(Announcements::class);
        self::assertSame([], $notices->save(null, 'Для всех', 'Общий текст', 'info', [], [], null, null, null));
        self::assertSame([], $notices->save(null, 'Только Про', 'Про-текст', 'warning', ['pro'], [], null, null, null));
        self::assertSame([], $notices->save(null, 'Важное для всех', 'Нельзя закрыть', 'critical', [], [], null, null, null));
        self::assertSame([], $notices->save(null, 'Не началось', 'Будущее', 'info', [], [], $now->modify('+1 day'), null, null));
        self::assertSame([], $notices->save(null, 'Кончилось', 'Прошлое', 'info', [], [], $now->modify('-3 days'), $now->modify('-1 day'), null));
        self::assertNotSame([], $notices->save(null, 'x', '', 'weird', [], [], null, null, null), 'a made-up level is refused');
        self::assertNotSame([], $notices->save(null, 'Конец раньше начала', '', 'info', [], [], $now->modify('+2 days'), $now->modify('+1 day'), null));

        $this->actAs($free);
        $freePage = $this->get('/w/' . $freeSpace->publicId);
        self::assertStringContainsString('Для всех', $freePage->body);
        self::assertStringNotContainsString('Только Про', $freePage->body);
        self::assertStringNotContainsString('Не началось', $freePage->body);
        self::assertStringNotContainsString('Кончилось', $freePage->body);

        $this->actAs($pro);
        self::assertStringContainsString('Только Про', $this->get('/w/' . $proSpace->publicId)->body);

        // Closing: stays closed for this person, others still see it; a critical one has no button and cannot be closed.
        $this->actAs($free);
        $id = (int) $this->db->select("SELECT id FROM announcements WHERE title = 'Для всех'")[0]['id'];
        $critical = (int) $this->db->select("SELECT id FROM announcements WHERE title = 'Важное для всех'")[0]['id'];
        $this->post('/announcements/' . $id . '/dismiss', []);
        $this->post('/announcements/' . $critical . '/dismiss', []);
        $after = $this->get('/w/' . $freeSpace->publicId)->body;
        self::assertStringNotContainsString('Общий текст', $after);
        self::assertStringContainsString('Нельзя закрыть', $after);
        self::assertStringNotContainsString('/announcements/' . $critical . '/dismiss', $after);
        $this->actAs($pro);
        self::assertStringContainsString('Общий текст', $this->get('/w/' . $proSpace->publicId)->body);
    }

    public function testAdminManagesAnnouncements(): void
    {
        $this->staff(StaffRole::Content);
        $this->post('/admin/announcements', ['title' => 'Скидка', 'body' => '20% на год', 'level' => 'info', 'plans' => ['pro', 'bad plan!'], 'starts' => '', 'ends' => '2099-01-01T10:00']);
        $row = $this->db->select('SELECT * FROM announcements')[0];
        self::assertSame('["pro"]', $row['plans_json']);
        $page = $this->get('/admin/announcements/' . $row['id']);
        self::assertStringContainsString('Скидка', $page->body);
        $this->post('/admin/announcements/' . $row['id'], ['title' => 'Скидка 30%', 'body' => '', 'level' => 'warning']);
        self::assertSame('Скидка 30%', $this->db->select('SELECT title FROM announcements')[0]['title']);
        $this->post('/admin/announcements/' . $row['id'] . '/toggle', []);
        self::assertSame(0, (int) $this->db->select('SELECT is_active FROM announcements')[0]['is_active']);
        $this->post('/admin/announcements/' . $row['id'] . '/delete', []);
        self::assertSame([], $this->db->select('SELECT 1 FROM announcements'));
        self::assertSame(404, $this->get('/admin/announcements/999999')->status);
    }

    public function testMailTextsCanBeRewrittenPreviewedAndRestored(): void
    {
        $this->staff(StaffRole::Content);
        // Refusals: a placeholder the letter does not have, and a letter that lost its link.
        $templates = $this->app->container()->get(MailTemplates::class);
        $this->post('/admin/mail-templates/verify_email', ['subject' => 'Привет', 'body' => 'Здравствуйте, {nam}! Откройте {link}']);
        $afterTypo = $templates->override('verify_email');
        $this->post('/admin/mail-templates/verify_email', ['subject' => 'Привет', 'body' => 'Здравствуйте, {name}!']);
        $afterNoLink = $templates->override('verify_email');
        self::assertSame([null, null, null, null], [$afterTypo['subject'], $afterTypo['body_md'], $afterNoLink['subject'], $afterNoLink['body_md']]);

        $this->post('/admin/mail-templates/verify_email', ['subject' => 'Добро пожаловать, {name}', 'body' => "Здравствуйте, **{name}**!\n\nПодтвердите почту: [открыть]({link})"]);
        $editor = $this->get('/admin/mail-templates/verify_email')->body;
        self::assertStringContainsString('<strong>Анна</strong>', $editor, 'the preview uses an example name');
        self::assertStringContainsString('Добро пожаловать, {name}', $editor);

        $this->useBrowser();
        $this->post('/register', ['name' => '<i>Мария</i>', 'email' => 'maria@example.com', 'password' => 'a-long-unusual-passphrase', 'consent' => '1']);
        $this->drainQueue();
        $mail = $this->mailer->lastTo('maria@example.com');
        self::assertNotNull($mail);
        self::assertSame('Добро пожаловать, <i>Мария</i>', $mail->subject);
        self::assertStringContainsString('&lt;i&gt;Мария&lt;/i&gt;', $mail->html, 'a name is never read as markup');
        self::assertStringNotContainsString('<i>Мария</i>', $mail->html);
        self::assertStringContainsString('Подтвердите почту:', $mail->text);
        self::assertMatchesRegularExpression('#http://localhost/email/verify/[A-Za-z0-9_-]{43}#', $mail->text . $mail->html);

        // Back to the written template.
        $this->staff(StaffRole::Content);
        $this->post('/admin/mail-templates/verify_email/reset', []);
        $this->useBrowser();
        $this->post('/register', ['name' => 'Пётр', 'email' => 'petr@example.com', 'password' => 'a-long-unusual-passphrase', 'consent' => '1']);
        $this->drainQueue();
        $default = $this->mailer->lastTo('petr@example.com');
        self::assertNotNull($default);
        self::assertSame('Подтвердите адрес почты', $default->subject);
        self::assertStringContainsString('Осталось подтвердить адрес почты', $default->text);
    }
}
