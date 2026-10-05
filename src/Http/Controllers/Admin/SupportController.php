<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditLog;
use App\Domain\Support\Tickets;
use App\Http\Admin\AdminInput;
use App\Http\FormFlash;
use App\Http\WorkspaceRequest;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;

/**
 * Admin: customer support. The queue of tickets, a ticket with the customer's context, the reply (by email or in the Telegram chat the ticket
 * came from), internal notes, status and assignee.
 */
final class SupportController
{
    public function __construct(
        private readonly View $view,
        private readonly Tickets $tickets,
        private readonly AuditLog $audit,
        private readonly FormFlash $flash,
    ) {
    }

    public function index(Request $request): Response
    {
        $staff = WorkspaceRequest::user($request);
        $filters = [
            'status' => AdminInput::choice($request, 'status', array_keys(Tickets::STATUSES)),
            'assignee' => AdminInput::choice($request, 'assignee', ['none', 'me:' . $staff->id]),
            'q' => AdminInput::text($request, 'q', 100),
        ];

        return $this->view->response('admin/support/index.twig', [
            'filters' => $filters,
            'result' => $this->tickets->page($filters, AdminInput::page($request)),
            'base' => '/admin/support' . AdminInput::query($filters),
            'statuses' => Tickets::STATUSES,
            'mine' => 'me:' . $staff->id,
        ]);
    }

    public function show(string $publicId): Response
    {
        $ticket = $this->tickets->find($publicId) ?? throw new HttpException(404, 'Not found');

        return $this->view->response('admin/support/show.twig', [
            'ticket' => $ticket,
            'messages' => $this->tickets->messages((int) $ticket['id']),
            'statuses' => Tickets::STATUSES,
        ]);
    }

    public function reply(Request $request, string $publicId): Response
    {
        $staff = WorkspaceRequest::user($request);
        $ticket = $this->tickets->find($publicId) ?? throw new HttpException(404, 'Not found');
        $body = AdminInput::text($request, 'body', 4000);
        if ($body === '') {
            $this->flash->toast('Напишите ответ.', 'error');

            return Response::redirect('/admin/support/' . $ticket['public_id']);
        }
        if ($request->input('kind') === 'note') {
            $this->tickets->note($ticket, $staff, $body);
            $this->flash->toast('Заметка записана. Клиент её не увидит.');
        } else {
            $via = $this->tickets->reply($ticket, $staff, $body);
            $this->audit->record('admin.support', $staff->id, 'ticket', (string) $ticket['public_id'], ['action' => 'reply', 'via' => $via]);
            $this->flash->toast($via === 'none' ? 'Ответ записан, но отправить его некуда: у обращения нет ни почты, ни чата Telegram.' : ($via === 'telegram' ? 'Ответ отправлен в Telegram клиента.' : 'Ответ отправлен на почту клиента.'), $via === 'none' ? 'warning' : 'success');
        }

        return Response::redirect('/admin/support/' . $ticket['public_id']);
    }

    public function update(Request $request, string $publicId): Response
    {
        $staff = WorkspaceRequest::user($request);
        $ticket = $this->tickets->find($publicId) ?? throw new HttpException(404, 'Not found');
        $status = AdminInput::choice($request, 'status', array_keys(Tickets::STATUSES));
        if ($status !== '') {
            $this->tickets->setStatus((string) $ticket['public_id'], $status);
        }
        $assignee = AdminInput::text($request, 'assignee', 12);
        if ($assignee === 'me') {
            $this->tickets->assign((string) $ticket['public_id'], $staff->id);
        } elseif ($assignee === 'none') {
            $this->tickets->assign((string) $ticket['public_id'], null);
        }
        $this->audit->record('admin.support', $staff->id, 'ticket', (string) $ticket['public_id'], ['action' => 'updated', 'status' => $status, 'assignee' => $assignee]);
        $this->flash->toast('Обращение обновлено.');

        return Response::redirect('/admin/support/' . $ticket['public_id']);
    }
}
