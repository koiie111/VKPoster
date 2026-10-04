<?php

declare(strict_types=1);

namespace App\Http\Controllers\Media;

use App\Domain\Media\FolderRepository;
use App\Domain\Media\MediaException;
use App\Domain\Media\MediaFetcher;
use App\Domain\Media\MediaKind;
use App\Domain\Media\MediaLimits;
use App\Domain\Media\MediaPresenter;
use App\Domain\Media\MediaRepository;
use App\Domain\Media\MediaService;
use App\Domain\Media\PlatformRequirements;
use App\Domain\Workspace\WorkspaceContext;
use App\Http\FormFlash;
use App\Http\WorkspaceRequest;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\Http\UploadedFile;
use App\Kernel\View\View;

/**
 * The media library pages and actions: grid with folders, search and filters, uploading (files and by link,
 * as JSON for the drag-and-drop widget or as a plain form), the item page, renaming, moving and deleting.
 */
final class MediaController
{
    private const PLATFORMS = ['telegram' => 'Telegram', 'vk' => 'ВКонтакте', 'max' => 'MAX', 'instagram' => 'Instagram'];

    public function __construct(
        private readonly View $view,
        private readonly FormFlash $flash,
        private readonly MediaRepository $media,
        private readonly FolderRepository $folders,
        private readonly MediaService $service,
        private readonly MediaFetcher $fetcher,
        private readonly MediaPresenter $presenter,
        private readonly MediaLimits $limits,
        private readonly PlatformRequirements $requirements,
    ) {
    }

    public function index(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $folders = $this->folders->all($context);
        $folderRaw = WorkspaceRequest::text($request->input('folder'));
        $folder = $folderRaw === '' ? null : $this->folders->find($context, $folderRaw);
        $kind = MediaKind::tryFrom(WorkspaceRequest::text($request->input('kind')));
        $search = mb_substr(WorkspaceRequest::text($request->input('q')), 0, 100);
        $pageRaw = $request->input('page');
        $result = $this->media->page($context, $folder?->id, $kind, $search, is_string($pageRaw) && ctype_digit($pageRaw) ? (int) $pageRaw : 1);

        $query = array_filter(['folder' => $folder?->publicId, 'kind' => $kind?->value, 'q' => $search], static fn (?string $v): bool => $v !== null && $v !== '');
        $base = '/w/' . $context->workspacePublicId . '/media';
        $used = $this->media->usedBytes($context);
        $quota = $this->service->quotaFor($context);

        return $this->view->response('workspace/media/index.twig', [
            'workspace' => $context,
            'files' => array_map($this->presenter->present(...), $result['rows']),
            'result' => $result,
            'folders' => $folders,
            'folder' => $folder,
            'filters' => ['q' => $search, 'kind' => $kind->value ?? ''],
            'kinds' => ['' => 'Все типы', MediaKind::Image->value => 'Фото', MediaKind::Video->value => 'Видео', MediaKind::Document->value => 'Документы'],
            'base' => $base,
            'page_base' => $base . ($query === [] ? '' : '?' . http_build_query($query)),
            'usage' => [
                'used' => MediaPresenter::size($used),
                'quota' => $quota === null ? 'без ограничений' : MediaPresenter::size($quota),
                'percent' => $quota !== null && $quota > 0 ? min(100, (int) round($used * 100 / $quota)) : 0,
                'used_bytes' => $used,
                'quota_bytes' => $quota,
            ],
            'max_file' => $this->limits->maxFileBytes,
            'max_file_text' => MediaPresenter::size($this->limits->maxFileBytes),
        ]);
    }

    public function show(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $media = $this->media->find($context, $this->param($request, 'mediaId')) ?? throw new HttpException(404, 'Not found');
        $folders = $this->folders->all($context);
        $currentFolder = '';
        foreach ($folders as $candidate) {
            if ($candidate->id === $media->folderId) {
                $currentFolder = $candidate->publicId;
            }
        }
        $problems = [];
        foreach (self::PLATFORMS as $key => $label) {
            $problems[$key] = ['label' => $label, 'issues' => $this->requirements->problems($media, $key)];
        }

        return $this->view->response('workspace/media/show.twig', [
            'workspace' => $context,
            'item' => $this->presenter->present($media),
            'media' => $media,
            'uploaded_at' => $media->createdAt,
            'folders' => $folders,
            'current_folder' => $currentFolder,
            'platforms' => $problems,
            'crops' => $media->isImage() && !$media->animated ? ['1x1' => '1:1', '4x5' => '4:5', '191x100' => '1.91:1', '9x16' => '9:16'] : [],
            'base' => '/w/' . $context->workspacePublicId . '/media',
        ]);
    }

    /**
     * Upload one file (field `file`) or several (`files[]`). Answers JSON for fetch/XHR clients, redirects otherwise.
     */
    public function upload(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $folder = WorkspaceRequest::text($request->input('folder'));
        $files = [];
        foreach (['file', 'files'] as $field) {
            $entry = $request->files[$field] ?? null;
            foreach (is_array($entry) ? $entry : ($entry === null ? [] : [$entry]) as $file) {
                $files[] = $file;
            }
        }
        if ($files === []) {
            return $this->outcome($request, $context, [], ['Файл не выбран или слишком большой для загрузки.']);
        }

        $items = [];
        $errors = [];
        foreach (array_slice($files, 0, 20) as $file) {
            try {
                $items[] = $this->store($context, $file, $folder);
            } catch (MediaException $e) {
                $errors[] = ($file->clientName !== '' ? $file->clientName . ': ' : '') . $e->getMessage();
            }
        }

        return $this->outcome($request, $context, $items, $errors);
    }

    public function uploadUrl(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $temp = null;
        try {
            $fetched = $this->fetcher->fetch(WorkspaceRequest::text($request->input('url')));
            $temp = $fetched['path'];
            $result = $this->service->upload($context, $temp, $fetched['name'], WorkspaceRequest::text($request->input('folder')));
            $items = [$this->item($result->media, $result->duplicate)];
            $errors = [];
        } catch (MediaException $e) {
            $items = [];
            $errors = [$e->getMessage()];
        } finally {
            if ($temp !== null) {
                @unlink($temp);
            }
        }

        return $this->outcome($request, $context, $items, $errors);
    }

    public function rename(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $media = $this->media->find($context, $this->param($request, 'mediaId')) ?? throw new HttpException(404, 'Not found');
        $name = MediaService::displayName(WorkspaceRequest::text($request->input('name')), pathinfo($media->storageKey, PATHINFO_EXTENSION));
        if (mb_strlen(WorkspaceRequest::text($request->input('name'))) < 1 || mb_strlen($name) > 150) {
            $this->flash->invalid([], ['name' => ['Название должно быть от 1 до 150 символов.']]);
        } else {
            $this->media->rename($context, $media, $name);
            $this->flash->toast('Название сохранено.');
        }

        return Response::redirect($this->itemUrl($context, $media->publicId));
    }

    public function move(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $media = $this->media->find($context, $this->param($request, 'mediaId')) ?? throw new HttpException(404, 'Not found');
        $folderRaw = WorkspaceRequest::text($request->input('folder'));
        $folder = $folderRaw === '' ? null : $this->folders->find($context, $folderRaw);
        if ($folderRaw !== '' && $folder === null) {
            $this->flash->invalid([], ['folder' => ['Такой папки нет.']]);
        } else {
            $this->media->move($context, [$media->publicId], $folder?->id);
            $this->flash->toast($folder === null ? 'Файл убран из папки.' : 'Файл перемещён в папку «' . $folder->name . '».');
        }

        return Response::redirect($this->itemUrl($context, $media->publicId));
    }

    public function delete(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $media = $this->media->find($context, $this->param($request, 'mediaId')) ?? throw new HttpException(404, 'Not found');
        try {
            $this->service->delete($context, $media);
        } catch (MediaException $e) {
            $this->flash->toast($e->getMessage(), 'error');

            return Response::redirect($this->itemUrl($context, $media->publicId));
        }
        $this->flash->toast('Файл «' . $media->originalName . '» удалён.');

        return Response::redirect('/w/' . $context->workspacePublicId . '/media');
    }

    /**
     * @throws MediaException
     * @return array<string, mixed>
     */
    private function store(WorkspaceContext $context, UploadedFile $file, string $folder): array
    {
        if ($file->error === UPLOAD_ERR_INI_SIZE || $file->error === UPLOAD_ERR_FORM_SIZE) {
            throw new MediaException(sprintf('Файл больше %s.', MediaPresenter::size($this->limits->maxFileBytes)));
        }
        if (!$file->isValid()) {
            throw new MediaException('Файл не загрузился. Попробуйте ещё раз.');
        }
        $result = $this->service->upload($context, $file->path, $file->clientName, $folder);

        return $this->item($result->media, $result->duplicate);
    }

    /**
     * @return array<string, mixed>
     */
    private function item(\App\Domain\Media\Media $media, bool $duplicate): array
    {
        return $this->presenter->present($media) + ['duplicate' => $duplicate];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param list<string> $errors
     */
    private function outcome(Request $request, WorkspaceContext $context, array $items, array $errors): Response
    {
        if ($request->wantsJson()) {
            return Response::json(['ok' => $errors === [], 'items' => $items, 'errors' => $errors], $items === [] && $errors !== [] ? 422 : 200);
        }
        if ($items !== []) {
            $new = count(array_filter($items, static fn (array $i): bool => $i['duplicate'] !== true));
            $this->flash->toast($new > 0 ? ($new === 1 ? 'Файл добавлен в медиатеку.' : 'Файлы добавлены в медиатеку: ' . $new . '.') : 'Такой файл уже есть в медиатеке.');
        }
        foreach ($errors as $error) {
            $this->flash->toast($error, 'error');
        }

        return Response::redirect('/w/' . $context->workspacePublicId . '/media');
    }

    private function itemUrl(WorkspaceContext $context, string $publicId): string
    {
        return '/w/' . $context->workspacePublicId . '/media/' . $publicId;
    }

    private function param(Request $request, string $name): string
    {
        $params = $request->attribute('route_params');

        return is_array($params) && is_string($params[$name] ?? null) ? $params[$name] : '';
    }
}
