<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditLog;
use App\Domain\Notification\MailTemplates;
use App\Http\Admin\AdminInput;
use App\Http\FormFlash;
use App\Http\WorkspaceRequest;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;
use App\Support\Markdown;

/**
 * Admin: the texts of system emails. Edit the subject and the body with `{placeholders}`, see the preview with example values, go back to
 * the text written in the template.
 */
final class MailTemplatesController
{
    public function __construct(
        private readonly View $view,
        private readonly MailTemplates $templates,
        private readonly AuditLog $audit,
        private readonly FormFlash $flash,
    ) {
    }

    public function index(): Response
    {
        $rows = [];
        foreach (MailTemplates::EDITABLE as $key => $info) {
            $rows[] = ['key' => $key, 'title' => $info['title'], 'edited' => $this->templates->override($key) !== ['subject' => null, 'body_md' => null]];
        }

        return $this->view->response('admin/mail_templates.twig', ['templates' => $rows, 'editing' => null]);
    }

    public function edit(string $template): Response
    {
        $info = MailTemplates::EDITABLE[$template] ?? throw new HttpException(404, 'Not found');
        $saved = $this->templates->override($template);
        $rows = [];
        foreach (MailTemplates::EDITABLE as $key => $i) {
            $rows[] = ['key' => $key, 'title' => $i['title'], 'edited' => $this->templates->override($key) !== ['subject' => null, 'body_md' => null]];
        }
        $sample = MailTemplates::sample($template);
        $preview = $saved['body_md'] === null ? null : Markdown::toHtml(MailTemplates::fill($saved['body_md'], $sample));

        return $this->view->response('admin/mail_templates.twig', [
            'templates' => $rows,
            'editing' => ['key' => $template, 'title' => $info['title'], 'placeholders' => $info['placeholders'], 'subject' => $saved['subject'] ?? '', 'body' => $saved['body_md'] ?? '', 'preview' => $preview, 'edited' => $saved['body_md'] !== null || $saved['subject'] !== null],
        ]);
    }

    public function save(Request $request, string $template): Response
    {
        $staff = WorkspaceRequest::user($request);
        $errors = $this->templates->save($template, AdminInput::text($request, 'subject', 220), is_string($body = $request->input('body')) ? trim(mb_substr($body, 0, 5200)) : '', $staff->id);
        if ($errors !== []) {
            $this->flash->toast(implode(' ', $errors), 'error');

            return Response::redirect('/admin/mail-templates/' . $template);
        }
        $this->audit->record('admin.content_changed', $staff->id, 'mail_template', $template, ['action' => 'saved']);
        $this->flash->toast('Письмо сохранено. Новые письма уйдут с этим текстом.');

        return Response::redirect('/admin/mail-templates/' . $template);
    }

    public function reset(Request $request, string $template): Response
    {
        if (!isset(MailTemplates::EDITABLE[$template])) {
            throw new HttpException(404, 'Not found');
        }
        $this->templates->reset($template);
        $this->audit->record('admin.content_changed', WorkspaceRequest::user($request)->id, 'mail_template', $template, ['action' => 'reset']);
        $this->flash->toast('Вернули текст, который написан в шаблоне.');

        return Response::redirect('/admin/mail-templates/' . $template);
    }
}
