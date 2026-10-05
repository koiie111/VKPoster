<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\AdminDirectory;
use App\Domain\Admin\StaffAccess;
use App\Domain\Admin\UserDirectory;
use App\Domain\Support\Tickets;
use App\Http\Admin\AdminInput;
use App\Http\WorkspaceRequest;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;

/**
 * Admin: one search box for a person, a workspace, a payment or a ticket, by id, email or name. An exact id goes straight to the page; otherwise
 * the matches are listed in groups. Each group is searched only when the role may open that part of the back office.
 */
final class SearchController
{
    private const LIMIT = 8;

    public function __construct(
        private readonly View $view,
        private readonly UserDirectory $users,
        private readonly AdminDirectory $directory,
        private readonly Tickets $tickets,
        private readonly StaffAccess $staff,
    ) {
    }

    public function index(Request $request): Response
    {
        $viewer = WorkspaceRequest::user($request);
        $q = AdminInput::text($request, 'q', 100);
        $groups = ['users' => [], 'workspaces' => [], 'payments' => [], 'tickets' => []];
        if ($q !== '') {
            $isId = preg_match('/^[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}$/', $q) === 1;
            if ($this->staff->can($viewer, 'users.view')) {
                $groups['users'] = array_slice($this->users->page(['q' => $q], 1)['rows'], 0, self::LIMIT);
            }
            if ($this->staff->can($viewer, 'workspaces.view')) {
                $groups['workspaces'] = array_slice($this->directory->workspaces($q, 1)['rows'], 0, self::LIMIT);
            }
            if ($this->staff->can($viewer, 'finance.view')) {
                $groups['payments'] = array_slice($this->directory->payments('', $q, 1)['rows'], 0, self::LIMIT);
            }
            if ($this->staff->can($viewer, 'support.view')) {
                $groups['tickets'] = array_slice($this->tickets->page(['q' => $q], 1)['rows'], 0, self::LIMIT);
            }
            // One exact hit goes straight to its page.
            $total = array_sum(array_map('count', $groups));
            if ($total === 1 || ($isId && $total >= 1) || (ctype_digit($q) && $groups['users'] !== [] && (int) $groups['users'][0]['id'] === (int) $q)) {
                foreach ([['users', '/admin/users/', 'id'], ['workspaces', '/admin/workspaces/', 'public_id'], ['payments', '/admin/payments/', 'public_id'], ['tickets', '/admin/support/', 'public_id']] as [$group, $base, $key]) {
                    if ($groups[$group] !== []) {
                        return Response::redirect($base . $groups[$group][0][$key]);
                    }
                }
            }
        }

        return $this->view->response('admin/search.twig', ['q' => $q, 'found' => $groups, 'total' => array_sum(array_map('count', $groups))]);
    }
}
