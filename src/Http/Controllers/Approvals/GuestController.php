<?php

declare(strict_types=1);

namespace App\Http\Controllers\Approvals;

use App\Domain\Approval\ApprovalWorkflow;
use App\Domain\Approval\GuestLinks;
use App\Domain\Approval\PostComments;
use App\Domain\Billing\Entitlements;
use App\Domain\Media\MediaRepository;
use App\Domain\Post\PostRepository;
use App\Domain\Post\TextFormatter;
use App\Http\Controllers\Media\MediaFileController;
use App\Http\WorkspaceRequest;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\Security\Signer;
use App\Kernel\View\View;

/** Guest preview capability; no member login, internal history or workspace navigation is exposed. */
final class GuestController
{
    public function __construct(private readonly GuestLinks $links, private readonly ApprovalWorkflow $workflow, private readonly PostComments $comments, private readonly PostRepository $posts, private readonly Entitlements $entitlements, private readonly MediaRepository $media, private readonly MediaFileController $files, private readonly Signer $signer, private readonly View $view)
    {
    }
    public function show(Request $request): Response
    {
        $token = $this->param($request, 'token');
        $r = $this->links->resolve($token);
        $ctx = $r['context'];
        $white = $this->entitlements->hasFeature($ctx->workspaceId, 'white_label');
        $settings = $this->workflow->settings($ctx->workspaceId);
        $cards = [];
        if (!$r['locked']) {
            foreach ($this->links->posts($token) as $post) {
                $variants = [];
                foreach ($this->posts->variants($ctx, $post) as $variant) {
                    $media = [];
                    foreach ($this->media->findMany($ctx, $variant->mediaIds ?? $post->mediaIds) as $asset) {
                        $media[] = ['id' => $asset->publicId,'kind' => $asset->kind->value];
                    }
                    $variants[] = ['name' => $variant->channelName, 'html' => TextFormatter::toHtml($variant->text ?? $post->baseText), 'media' => $media];
                }
                $cards[] = ['post' => $post, 'html' => TextFormatter::toHtml($post->baseText), 'variants' => $variants, 'revision' => $this->workflow->revision($ctx, $post), 'can_approve' => $post->status === \App\Domain\Post\PostStatus::PendingApproval && $this->workflow->canApprove($ctx, $post), 'comments' => $this->comments->thread($ctx, $post, (int) $r['link']['id'])];
            }
        }
        return $this->view->response('guest/approval.twig', ['guest_error' => $request->attribute('guest_error'), 'token' => $token, 'locked' => $r['locked'], 'cards' => $cards, 'branding' => $white, 'brand_name' => $settings['brand_name'] !== '' ? $settings['brand_name'] : $ctx->workspaceName, 'brand_logo' => $white ? $settings['brand_logo'] : '', 'workspace_name' => $ctx->workspaceName])->withHeader('Cache-Control', 'no-store')->withHeader('Referrer-Policy', 'no-referrer');
    }
    public function unlock(Request $request): Response
    {
        $token = $this->param($request, 'token');
        try {
            $this->links->unlock($token, WorkspaceRequest::text($request->input('pin')), $request->ip());
        } catch (HttpException $e) {
            if (!in_array($e->status, [403,429,503], true)) {
                throw $e;
            }
            return $this->show($request->withAttribute('guest_error', $e->getMessage()))->withStatus($e->status);
        }
        return Response::redirect('/g/' . $token);
    }
    public function approve(Request $request): Response
    {
        $this->links->act($this->param($request, 'token'), $this->param($request, 'postId'), WorkspaceRequest::text($request->input('revision')), null);
        return Response::redirect('/g/' . $this->param($request, 'token'));
    }
    public function comment(Request $request): Response
    {
        $this->links->act($this->param($request, 'token'), $this->param($request, 'postId'), '', WorkspaceRequest::text($request->input('body')), WorkspaceRequest::text($request->input('parent')));
        return Response::redirect('/g/' . $this->param($request, 'token'));
    }
    public function theme(Request $request): Response
    {
        $r = $this->links->resolve($this->param($request, 'token'));
        $color = $this->entitlements->hasFeature($r['context']->workspaceId, 'white_label') ? $this->workflow->settings($r['context']->workspaceId)['brand_color'] : '#4338ca';
        if (preg_match('/^#[0-9a-f]{6}$/D', $color) !== 1) {
            $color = '#4338ca';
        }
        return new Response(200, '.guest-brand{border-top:4px solid ' . $color . '}', ['Content-Type' => 'text/css; charset=utf-8', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
    public function file(Request $request): Response
    {
        $r = $this->links->authorized($this->param($request, 'token'));
        $id = $this->param($request, 'mediaId');
        $allowed = [];
        if ($this->param($request, 'postId') === 'brand') {
            if ($this->entitlements->hasFeature($r['context']->workspaceId, 'white_label')) {
                $allowed[] = $this->workflow->settings($r['context']->workspaceId)['brand_logo'];
            }
        } else {
            $post = $this->links->post($this->param($request, 'token'), $this->param($request, 'postId'));
            $allowed = $post->mediaIds;
            foreach ($this->posts->variants($r['context'], $post) as $v) {
                $allowed = array_merge($allowed, $v->mediaIds ?? []);
            }
        }
        if (!in_array($id, $allowed, true) || $this->media->find($r['context'], $id) === null) {
            throw new HttpException(404, 'Not found');
        }
        // Reuse the library's MIME, Range, sandbox and storage delivery. The signed URL stays server-side.
        $path = '/media/' . $id . '/original';
        $url = $this->signer->signUrl($path, 30);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $query = ['expires' => $query['expires'] ?? '', 'signature' => $query['signature'] ?? ''];
        $internal = Request::create('GET', $path, $query, headers: $request->headers)->withAttribute('route_params', ['id' => $id, 'variant' => 'original']);
        return $this->files->show($internal)->withHeader('Cache-Control', 'no-store');
    }
    private function param(Request $request, string $name): string
    {
        $params = $request->attribute('route_params');
        return is_array($params) ? WorkspaceRequest::text($params[$name] ?? '') : '';
    }
}
