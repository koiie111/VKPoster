<?php

declare(strict_types=1);

namespace App\Http\Controllers\Media;

use App\Domain\Media\FolderRepository;
use App\Http\FormFlash;
use App\Http\WorkspaceRequest;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;

/**
 * Creating, renaming and deleting library folders. Deleting a folder keeps its files.
 */
final class FolderController
{
    public function __construct(
        private readonly FolderRepository $folders,
        private readonly FormFlash $flash,
    ) {
    }

    public function create(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $name = $this->name($request);
        $base = '/w/' . $context->workspacePublicId . '/media';
        if ($name === null) {
            $this->flash->toast('Название папки — от 1 до 100 символов.', 'error');

            return Response::redirect($base);
        }
        $folder = $this->folders->create($context, $name);
        if ($folder === null) {
            $this->flash->toast('Папка «' . $name . '» уже есть.', 'error');

            return Response::redirect($base);
        }
        $this->flash->toast('Папка «' . $name . '» создана.');

        return Response::redirect($base . '?folder=' . $folder->publicId);
    }

    public function rename(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $folder = $this->folders->find($context, $this->id($request)) ?? throw new HttpException(404, 'Not found');
        $name = $this->name($request);
        $to = '/w/' . $context->workspacePublicId . '/media?folder=' . $folder->publicId;
        if ($name === null) {
            $this->flash->toast('Название папки — от 1 до 100 символов.', 'error');
        } elseif (!$this->folders->rename($context, $folder, $name)) {
            $this->flash->toast('Папка «' . $name . '» уже есть.', 'error');
        } else {
            $this->flash->toast('Папка переименована.');
        }

        return Response::redirect($to);
    }

    public function delete(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $folder = $this->folders->find($context, $this->id($request)) ?? throw new HttpException(404, 'Not found');
        $this->folders->delete($context, $folder);
        $this->flash->toast('Папка «' . $folder->name . '» удалена, файлы остались в медиатеке.');

        return Response::redirect('/w/' . $context->workspacePublicId . '/media');
    }

    private function name(Request $request): ?string
    {
        $name = (string) preg_replace('/\s+/u', ' ', WorkspaceRequest::text($request->input('name')));
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name));
        $length = mb_strlen($name);

        return $length >= 1 && $length <= 100 ? $name : null;
    }

    private function id(Request $request): string
    {
        $params = $request->attribute('route_params');

        return is_array($params) && is_string($params['folderId'] ?? null) ? $params['folderId'] : '';
    }
}
