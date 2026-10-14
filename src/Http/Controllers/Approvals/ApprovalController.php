<?php

declare(strict_types=1);

namespace App\Http\Controllers\Approvals;

use App\Domain\Approval\ApprovalWorkflow;
use App\Domain\Approval\GuestLinks;
use App\Domain\Approval\PostComments;
use App\Domain\Post\Post;
use App\Domain\Post\PostRepository;
use App\Domain\Billing\Entitlements;
use App\Domain\Workspace\WorkspaceContext;
use App\Http\FormFlash;
use App\Http\WorkspaceRequest;
use App\Kernel\Database\Connection;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;

/** Thin workspace approval/settings/comment/link endpoints; domain services repeat tenant/permission checks. */
final class ApprovalController
{
    public function __construct(private readonly Connection $db, private readonly View $view, private readonly PostRepository $posts, private readonly ApprovalWorkflow $workflow, private readonly PostComments $comments, private readonly GuestLinks $links, private readonly Entitlements $entitlements, private readonly FormFlash $flash)
    {
    }
    public function index(Request $request): Response
    {
        $ctx = WorkspaceRequest::context($request);
        $rows = $this->db->select("SELECT * FROM posts WHERE workspace_id=? AND status IN ('draft','pending_approval','approved','changes_requested') ORDER BY updated_at DESC,id DESC LIMIT 100", [$ctx->workspaceId]);
        $posts = array_values(array_filter(array_map(PostRepository::hydrate(...), $rows), fn (Post $p): bool => $this->workflow->visible($ctx, $p)));
        return $this->view->response('workspace/approvals/index.twig', ['workspace' => $ctx, 'posts' => $posts, 'links' => $ctx->role->value === 'client' || $ctx->role->value === 'author' || $ctx->role->value === 'viewer' ? [] : $this->links->history($ctx), 'created_link' => $this->flash->session()->getFlash('_guest_link_created'), 'guest_available' => $this->entitlements->hasFeature($ctx->workspaceId, 'guest_links')])->withHeader('Cache-Control', 'no-store');
    }
    public function settings(Request $request): Response
    {
        $ctx = WorkspaceRequest::context($request);
        $members = $request->input('approvers', []);
        try {
            $this->workflow->configure($ctx, $request->input('required') === '1', is_array($members) ? array_values(array_filter($members, 'is_string')) : [], WorkspaceRequest::text($request->input('brand_name')), WorkspaceRequest::text($request->input('brand_color', '#4338ca')), WorkspaceRequest::text($request->input('brand_logo')));
        } catch (\App\Domain\Billing\PlanLimitException $e) {
            $this->flash->refusal($e->getMessage(), true, $ctx->workspacePublicId);
            return Response::redirect('/w/' . $ctx->workspacePublicId . '/settings');
        } catch (HttpException $e) {
            if ($e->status !== 422) {
                throw $e;
            }
            $this->flash->invalid(array_diff_key($request->body, ['pin' => true]), ['approval' => $e->getMessage()]);
            return Response::redirect('/w/' . $ctx->workspacePublicId . '/settings');
        }
        $this->flash->toast('Настройки согласования сохранены.');
        return Response::redirect('/w/' . $ctx->workspacePublicId . '/settings');
    }
    public function submit(Request $request): Response
    {
        $ctx = WorkspaceRequest::context($request);
        try {
            $this->workflow->submit($ctx, $this->post($ctx, $request));
        } catch (\App\Domain\Billing\PlanLimitException $e) {
            $this->flash->refusal($e->getMessage(), true, $ctx->workspacePublicId);
        }
        return $this->back($ctx, $request);
    }
    public function decide(Request $request): Response
    {
        $ctx = WorkspaceRequest::context($request);
        $decision = WorkspaceRequest::text($request->input('decision'));
        if (!in_array($decision, ['approved', 'changes_requested'], true)) {
            throw new HttpException(422, 'Выберите решение.');
        }
        $this->workflow->decide($ctx, $this->post($ctx, $request), WorkspaceRequest::text($request->input('revision')), $decision === 'approved');
        return $this->back($ctx, $request);
    }
    public function comment(Request $request): Response
    {
        $ctx = WorkspaceRequest::context($request);
        try {
            $this->comments->add($ctx, $this->post($ctx, $request), WorkspaceRequest::text($request->input('body')), WorkspaceRequest::text($request->input('parent')));
        } catch (HttpException $e) {
            if ($e->status !== 422) {
                throw $e;
            }
            $this->flash->invalid(['body' => $request->input('body')], ['comment' => $e->getMessage()]);
        }
        return $this->back($ctx, $request);
    }
    public function share(Request $request): Response
    {
        $ctx = WorkspaceRequest::context($request);
        $input = $request->input('posts', []);
        $ids = is_array($input) ? array_values(array_filter($input, 'is_string')) : [];
        try {
            $url = $this->links->create($ctx, $ids, (int) WorkspaceRequest::text($request->input('ttl_hours', '72')), WorkspaceRequest::text($request->input('pin')), WorkspaceRequest::text($request->input('from')), WorkspaceRequest::text($request->input('to')));
        } catch (\App\Domain\Billing\PlanLimitException $e) {
            $this->flash->refusal($e->getMessage(), true, $ctx->workspacePublicId);
            return Response::redirect('/w/' . $ctx->workspacePublicId . '/approvals');
        } catch (HttpException $e) {
            if ($e->status !== 422) {
                throw $e;
            }
            $this->flash->invalid(array_diff_key($request->body, ['pin' => true]), ['guest' => $e->getMessage()]);
            return Response::redirect('/w/' . $ctx->workspacePublicId . '/approvals');
        }
        $this->flash->session()->flash('_guest_link_created', $url);
        $this->flash->toast('Ссылка создана. Скопируйте её сейчас.');
        return Response::redirect('/w/' . $ctx->workspacePublicId . '/approvals')->withHeader('Cache-Control', 'no-store');
    }
    public function revoke(Request $request): Response
    {
        $ctx = WorkspaceRequest::context($request);
        $this->links->revoke($ctx, $this->param($request, 'linkId'));
        $this->flash->toast('Гостевая ссылка отозвана.');
        return Response::redirect('/w/' . $ctx->workspacePublicId . '/approvals');
    }
    private function post(WorkspaceContext $ctx, Request $request): Post
    {
        $p = $this->posts->find($ctx, $this->param($request, 'postId')) ?? throw new HttpException(404, 'Not found');
        return $this->workflow->visible($ctx, $p) ? $p : throw new HttpException(404, 'Not found');
    }
    private function back(WorkspaceContext $ctx, Request $request): Response
    {
        $this->flash->toast('Изменения сохранены.');
        return Response::redirect('/w/' . $ctx->workspacePublicId . '/posts/' . $this->param($request, 'postId'));
    }
    private function param(Request $request, string $name): string
    {
        $params = $request->attribute('route_params');
        return is_array($params) ? WorkspaceRequest::text($params[$name] ?? '') : '';
    }
}
