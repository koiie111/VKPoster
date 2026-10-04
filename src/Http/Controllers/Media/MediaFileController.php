<?php

declare(strict_types=1);

namespace App\Http\Controllers\Media;

use App\Domain\Media\Media;
use App\Domain\Media\MediaException;
use App\Domain\Media\MediaKind;
use App\Domain\Media\MediaLookup;
use App\Domain\Media\VariantService;
use App\Domain\Media\VariantSpec;
use App\Domain\User\User;
use App\Domain\Workspace\Permissions;
use App\Domain\Workspace\WorkspaceContext;
use App\Domain\Workspace\WorkspaceRepository;
use App\Integrations\Storage\MediaStorage;
use App\Integrations\Storage\StorageException;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\Security\Signer;

/**
 * The only way library files leave the server: `/media/{id}/{variant}`. Access is either a member of the
 * owning workspace with the right to view media (session), or a valid unexpired signature (links made for
 * networks that download media themselves). Anything else is 404 (not a member) or 403 (bad signature).
 *
 * Files are served with their detected type, `nosniff`, a sandboxing CSP and, for documents, as downloads,
 * so even a hostile file cannot run code in our origin. `Range` requests are supported (Safari needs them for video).
 */
final class MediaFileController
{
    public function __construct(
        private readonly MediaLookup $lookup,
        private readonly WorkspaceRepository $workspaces,
        private readonly Permissions $permissions,
        private readonly VariantService $variants,
        private readonly MediaStorage $storage,
        private readonly Signer $signer,
    ) {
    }

    public function show(Request $request): Response
    {
        $params = $request->attribute('route_params');
        $params = is_array($params) ? $params : [];
        $id = is_string($params['id'] ?? null) ? $params['id'] : '';
        $variant = is_string($params['variant'] ?? null) ? $params['variant'] : '';

        $signature = $request->query['signature'] ?? null;
        $signed = is_string($signature);
        if ($signed && !$this->signer->verifyUrl($request->path . '?' . http_build_query($request->query))) {
            throw new HttpException(403, 'Forbidden');
        }
        $media = $this->lookup->find($id);
        if ($media === null) {
            throw new HttpException(404, 'Not found');
        }
        $context = $this->contextFor($media, $signed ? null : $request->attribute('user'));
        if ($context === null) {
            throw new HttpException(404, 'Not found');
        }

        $object = $this->resolve($context, $media, $variant);
        $etag = '"' . substr(hash('sha256', $media->sha256 . '|' . $object['key']), 0, 32) . '"';
        $headers = [
            'Content-Type' => $object['mime'],
            'X-Content-Type-Options' => 'nosniff',
            // Nothing in a user file may run in our origin, whatever the browser decides the type is.
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cross-Origin-Resource-Policy' => $signed ? 'cross-origin' : 'same-origin',
            // A watermarked rendition changes when the watermark settings do, so it is revalidated soon.
            'Cache-Control' => str_contains($variant, '-wm') ? 'private, max-age=60' : 'private, max-age=3600',
            'ETag' => $etag,
            'Accept-Ranges' => 'bytes',
            'Content-Disposition' => $this->disposition($media, $object['mime'], $request->query['download'] ?? null),
        ];
        if ($request->header('If-None-Match') === $etag) {
            return new Response(304, '', ['ETag' => $etag, 'Cache-Control' => $headers['Cache-Control']]);
        }

        try {
            $total = $this->storage->size($object['key']);
            $stream = $this->storage->read($object['key']);
        } catch (StorageException) {
            throw new HttpException(404, 'Not found');
        }
        $range = $this->range($request->header('Range'), $total);
        if ($range === false) {
            fclose($stream);

            return new Response(416, '', ['Content-Range' => 'bytes */' . $total]);
        }
        if ($range !== null) {
            [$start, $end] = $range;
            $headers['Content-Range'] = sprintf('bytes %d-%d/%d', $start, $end, $total);
            $headers['Content-Length'] = (string) ($end - $start + 1);

            return Response::stream($stream, $headers, 206, $start, $end - $start + 1);
        }
        $headers['Content-Length'] = (string) $total;

        return Response::stream($stream, $headers);
    }

    /**
     * @return array{key: string, mime: string, size: int, width: int, height: int}
     */
    private function resolve(WorkspaceContext $context, Media $media, string $variant): array
    {
        if ($variant === 'thumb') {
            if ($media->thumbKey === null) {
                throw new HttpException(404, 'Not found');
            }

            return ['key' => $media->thumbKey, 'mime' => $media->kind === MediaKind::Image ? 'image/webp' : 'image/jpeg', 'size' => 0, 'width' => 0, 'height' => 0];
        }
        $spec = VariantSpec::fromSlug($variant);
        if ($spec === null) {
            throw new HttpException(404, 'Not found');
        }
        try {
            return $this->variants->get($context, $media, $spec);
        } catch (MediaException) {
            throw new HttpException(404, 'Not found');
        }
    }

    /**
     * The workspace context for serving: for a signed link the file's own workspace (the signature is the
     * authority), otherwise a member's context, only when their role may view media.
     */
    private function contextFor(Media $media, mixed $user): ?WorkspaceContext
    {
        $workspace = $this->workspaces->findById($media->workspaceId);
        if ($workspace === null) {
            return null;
        }
        if ($user === null) {
            $owner = $this->workspaces->membership($workspace->id, $workspace->ownerId);

            return $owner === null ? null : WorkspaceContext::from($workspace, $owner);
        }
        if (!$user instanceof User) {
            return null;
        }
        $membership = $this->workspaces->membership($workspace->id, $user->id);
        if ($membership === null || !$this->permissions->allows($membership->role, 'media.view')) {
            return null;
        }

        return WorkspaceContext::from($workspace, $membership);
    }

    private function disposition(Media $media, string $mime, mixed $download): string
    {
        $attachment = $download === '1' || $media->kind === MediaKind::Document;
        $name = $media->originalName;
        $ascii = (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);
        $ascii = $ascii === '' || $ascii === '_' ? 'file' : $ascii;

        return ($attachment ? 'attachment' : 'inline') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name);
    }

    /**
     * @return array{int, int}|false|null the byte range (inclusive), false when unsatisfiable, null when no (usable) Range header
     */
    private function range(?string $header, int $total): array|false|null
    {
        if ($header === null || preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $m) !== 1 || ($m[1] === '' && $m[2] === '')) {
            return null;
        }
        if ($m[1] === '') {
            $length = (int) $m[2];
            if ($length < 1) {
                return false;
            }
            $start = max(0, $total - $length);
            $end = $total - 1;
        } else {
            $start = (int) $m[1];
            $end = $m[2] === '' ? $total - 1 : min((int) $m[2], $total - 1);
        }
        if ($start >= $total || $start > $end) {
            return false;
        }

        return [$start, $end];
    }
}
