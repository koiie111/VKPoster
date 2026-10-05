<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditLog;
use App\Domain\Content\Documents;
use App\Domain\Content\SiteContent;
use App\Http\Admin\AdminInput;
use App\Http\FormFlash;
use App\Http\WorkspaceRequest;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;
use App\Support\Markdown;

/**
 * Admin: the content of the public site. Legal documents and help articles (revisions with a preview; a new version of a document people
 * must accept asks them for consent again), the landing texts and the FAQ.
 */
final class ContentController
{
    public function __construct(
        private readonly View $view,
        private readonly Documents $documents,
        private readonly SiteContent $site,
        private readonly AuditLog $audit,
        private readonly FormFlash $flash,
    ) {
    }

    public function index(): Response
    {
        return $this->view->response('admin/content/index.twig', ['documents' => $this->documents->all(), 'kinds' => Documents::KINDS]);
    }

    public function newDocument(): Response
    {
        return $this->view->response('admin/content/document.twig', $this->form('help', '', ['title' => '', 'version' => null, 'required' => false, 'body' => '', 'draft' => false, 'kind' => 'help', 'slug' => ''], true));
    }

    public function document(string $kind, string $slug): Response
    {
        $working = $this->documents->working($kind, $slug) ?? throw new HttpException(404, 'Not found');

        return $this->view->response('admin/content/document.twig', $this->form($kind, $slug, $working, false));
    }

    public function preview(Request $request): Response
    {
        $body = is_string($raw = $request->input('body')) ? mb_substr($raw, 0, 200000) : '';

        return $this->view->response('admin/content/preview.twig', ['html' => Markdown::toHtml($body), 'title' => AdminInput::text($request, 'title', 200)]);
    }

    public function save(Request $request): Response
    {
        $staff = WorkspaceRequest::user($request);
        $kind = AdminInput::text($request, 'kind', 8);
        $slug = AdminInput::text($request, 'slug', 60);
        $publish = $request->input('action') === 'publish';
        $errors = $this->documents->save(
            $kind,
            $slug,
            AdminInput::text($request, 'title', 200),
            AdminInput::text($request, 'version', 10),
            $request->input('required') === '1',
            is_string($raw = $request->input('body')) ? str_replace("\r\n", "\n", $raw) : '',
            $publish,
            $staff->id,
        );
        $back = '/admin/content/' . $kind . '/' . $slug;
        if ($errors !== []) {
            $this->flash->toast(implode(' ', $errors), 'error');

            return Response::redirect(preg_match('/^[a-z0-9-]{1,60}$/', $slug) === 1 && isset(Documents::KINDS[$kind]) && $this->documents->working($kind, $slug) !== null ? $back : '/admin/content/new');
        }
        $this->audit->record('admin.content_changed', $staff->id, 'document', $kind . ':' . $slug, ['action' => $publish ? 'published' : 'draft', 'version' => AdminInput::text($request, 'version', 10)]);
        $this->flash->toast($publish ? ($kind === 'legal' ? 'Опубликовано. Если версия документа новее, люди увидят запрос на новое согласие при входе.' : 'Опубликовано: статья уже на сайте.') : 'Черновик сохранён. На сайте пока прежний текст.');

        return Response::redirect($back);
    }

    public function restore(Request $request, string $id): Response
    {
        $staff = WorkspaceRequest::user($request);
        if (!$this->documents->restore((int) $id, $staff->id)) {
            throw new HttpException(404, 'Not found');
        }
        $this->flash->toast('Прежняя версия положена в черновик. Проверьте и опубликуйте её, если нужно.');
        $back = $request->input('back');

        return Response::redirect(is_string($back) && preg_match('#^/admin/content/(legal|help)/[a-z0-9-]{1,60}$#', $back) === 1 ? $back : '/admin/content');
    }

    public function discard(Request $request, string $kind, string $slug): Response
    {
        $this->documents->discardDraft($kind, $slug);
        $this->audit->record('admin.content_changed', WorkspaceRequest::user($request)->id, 'document', $kind . ':' . $slug, ['action' => 'draft discarded']);
        $this->flash->toast('Черновик удалён.');

        return Response::redirect($this->documents->live($kind, $slug) === null ? '/admin/content' : '/admin/content/' . $kind . '/' . $slug);
    }

    public function texts(): Response
    {
        $faq = $this->site->faq();
        $items = $faq ?? require dirname(__DIR__, 4) . '/resources/site/faq.php';

        return $this->view->response('admin/content/texts.twig', [
            'blocks' => SiteContent::BLOCKS,
            'saved' => $this->site->saved(),
            'faq' => $items,
            'faq_custom' => $faq !== null,
            'faq_slots' => SiteContent::MAX_FAQ,
        ]);
    }

    public function saveTexts(Request $request): Response
    {
        $staff = WorkspaceRequest::user($request);
        $input = $request->input('t');
        $texts = [];
        foreach (is_array($input) ? $input : [] as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $texts[$name] = $value;
            }
        }
        $this->site->saveTexts($texts, $staff->id);
        $this->audit->record('admin.content_changed', $staff->id, 'site_texts', 'landing', ['action' => 'saved']);
        $this->flash->toast('Тексты сохранены. Пустое поле возвращает текст по умолчанию.');

        return Response::redirect('/admin/content/texts');
    }

    public function saveFaq(Request $request): Response
    {
        $staff = WorkspaceRequest::user($request);
        $q = $request->input('q');
        $a = $request->input('a');
        $pairs = [];
        foreach (is_array($q) ? $q : [] as $i => $question) {
            if (is_string($question) && is_array($a) && is_string($a[$i] ?? null)) {
                $pairs[] = [$question, $a[$i]];
            }
        }
        $count = $this->site->saveFaq($pairs, $staff->id);
        $this->audit->record('admin.content_changed', $staff->id, 'site_texts', 'faq', ['action' => 'saved', 'count' => $count]);
        $this->flash->toast($count === 0 ? 'Вопросов не осталось: на сайте вернулся стандартный список.' : 'Вопросы сохранены: ' . $count . '.');

        return Response::redirect('/admin/content/texts');
    }

    /**
     * @param array{kind: string, slug: string, title: string, version: ?string, required: bool, body: string, draft: bool} $working
     * @return array<string, mixed>
     */
    private function form(string $kind, string $slug, array $working, bool $isNew): array
    {
        return [
            'is_new' => $isNew,
            'kind' => $kind,
            'slug' => $slug,
            'doc' => $working,
            'kinds' => Documents::KINDS,
            'history' => $isNew ? [] : $this->documents->history($kind, $slug),
            'live' => $isNew ? null : $this->documents->live($kind, $slug),
            'preview' => Markdown::toHtml($working['body']),
        ];
    }
}
