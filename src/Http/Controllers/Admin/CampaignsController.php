<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\UserDirectory;
use App\Domain\Audit\AuditLog;
use App\Domain\Campaign\Campaigns;
use App\Http\Admin\AdminInput;
use App\Http\Admin\StepUp;
use App\Http\FormFlash;
use App\Http\WorkspaceRequest;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;
use App\Support\Markdown;

/**
 * Admin: email campaigns. Write a text, choose who gets it (only people who agreed to news and have not left), look at the preview, send
 * a test to yourself, start the sending (which goes through the queue at the set speed) and watch the numbers.
 */
final class CampaignsController
{
    public function __construct(
        private readonly View $view,
        private readonly Campaigns $campaigns,
        private readonly UserDirectory $directory,
        private readonly AuditLog $audit,
        private readonly FormFlash $flash,
        private readonly StepUp $stepUp,
    ) {
    }

    public function index(): Response
    {
        $rows = [];
        foreach ($this->campaigns->all() as $campaign) {
            $campaign['stats'] = $this->campaigns->stats((int) $campaign['id']);
            $rows[] = $campaign;
        }

        return $this->view->response('admin/campaigns/index.twig', ['campaigns' => $rows]);
    }

    public function create(): Response
    {
        return $this->view->response('admin/campaigns/edit.twig', $this->formData(null, ['plans' => [], 'platforms' => [], 'activity' => 'any'], '', '', ''));
    }

    public function show(string $publicId): Response
    {
        $campaign = $this->campaigns->find($publicId) ?? throw new HttpException(404, 'Not found');

        return $this->view->response('admin/campaigns/edit.twig', $this->formData($campaign, $campaign['segment'], (string) $campaign['name'], (string) $campaign['subject'], (string) $campaign['body_md']));
    }

    public function save(Request $request, ?string $publicId = null): Response
    {
        $staff = WorkspaceRequest::user($request);
        [$name, $subject, $body, $segment] = $this->input($request);
        if ($name === '' || $subject === '' || trim($body) === '') {
            $this->flash->toast('Заполните название, тему и текст письма.', 'error');

            return Response::redirect($publicId === null ? '/admin/campaigns/new' : '/admin/campaigns/' . $publicId);
        }
        if ($publicId === null) {
            $publicId = $this->campaigns->create($name, $subject, $body, $segment, $staff->id);
        } elseif (!$this->campaigns->update($publicId, $name, $subject, $body, $segment)) {
            $this->flash->toast('Рассылку, которую уже запустили, менять нельзя.', 'error');

            return Response::redirect('/admin/campaigns/' . $publicId);
        }
        $this->audit->record('admin.campaign', $staff->id, 'campaign', $publicId, ['action' => 'saved']);
        $this->flash->toast('Рассылка сохранена.');

        return Response::redirect('/admin/campaigns/' . $publicId);
    }

    public function test(Request $request, string $publicId): Response
    {
        $staff = WorkspaceRequest::user($request);
        $campaign = $this->campaigns->find($publicId) ?? throw new HttpException(404, 'Not found');
        if ($staff->email === null) {
            $this->flash->toast('У вас в аккаунте нет почты, тест отправить некуда.', 'error');

            return Response::redirect('/admin/campaigns/' . $publicId);
        }
        try {
            $this->campaigns->test($staff->email, $staff->name, (string) $campaign['subject'], (string) $campaign['body_md'], $staff->id);
            $this->flash->toast('Тестовое письмо отправлено на ' . $staff->email . '.');
        } catch (\Throwable) {
            $this->flash->toast('Не удалось отправить тест: проверьте почтовые настройки.', 'error');
        }

        return Response::redirect('/admin/campaigns/' . $publicId);
    }

    public function start(Request $request, string $publicId): Response
    {
        $staff = WorkspaceRequest::user($request);
        $campaign = $this->campaigns->find($publicId) ?? throw new HttpException(404, 'Not found');
        if (($denied = $this->stepUp->guard($request, $staff, '/admin/campaigns/' . $publicId)) !== null) {
            return $denied;
        }
        $count = $this->campaigns->start($publicId);
        $this->audit->record('admin.campaign', $staff->id, 'campaign', $publicId, ['action' => 'started', 'recipients' => $count]);
        $this->flash->toast($campaign['status'] !== 'draft' ? 'Эта рассылка уже запущена.' : ($count === 0 ? 'В выбранной группе никого нет: письма не уйдут.' : 'Рассылка запущена: писем ' . $count . '. Они уходят постепенно.'), $campaign['status'] === 'draft' && $count > 0 ? 'success' : 'warning');

        return Response::redirect('/admin/campaigns/' . $publicId);
    }

    public function cancel(Request $request, string $publicId): Response
    {
        $this->campaigns->cancel($publicId);
        $this->audit->record('admin.campaign', WorkspaceRequest::user($request)->id, 'campaign', $publicId, ['action' => 'cancelled']);
        $this->flash->toast('Рассылка остановлена: что не ушло, не уйдёт.');

        return Response::redirect('/admin/campaigns/' . $publicId);
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: array<string, mixed>}
     */
    private function input(Request $request): array
    {
        $plans = $request->input('plans');
        $platforms = $request->input('platforms');

        return [
            AdminInput::text($request, 'name', 150),
            AdminInput::text($request, 'subject', 200),
            is_string($body = $request->input('body')) ? mb_substr($body, 0, 5000) : '',
            ['plans' => is_array($plans) ? $plans : [], 'platforms' => is_array($platforms) ? $platforms : [], 'activity' => AdminInput::text($request, 'activity', 10)],
        ];
    }

    /**
     * @param array<string, mixed>|null $campaign
     * @param array{plans: list<string>, platforms: list<string>, activity: string} $segment
     * @return array<string, mixed>
     */
    private function formData(?array $campaign, array $segment, string $name, string $subject, string $body): array
    {
        $preview = Markdown::toHtml(str_replace('{name}', 'Анна', $body === '' ? 'Здесь появится текст письма.' : $body));

        return [
            'campaign' => $campaign,
            'name' => $name,
            'subject' => $subject,
            'body' => $body,
            'segment' => $segment,
            'plans' => $this->directory->plans(),
            'platforms' => ['telegram' => 'Telegram', 'vk' => 'ВКонтакте', 'max' => 'MAX'],
            'reach' => $this->campaigns->count($segment),
            'stats' => $campaign === null ? null : $this->campaigns->stats((int) $campaign['id']),
            'preview' => $preview,
            'per_minute' => $this->campaigns->perMinute(),
        ];
    }
}
