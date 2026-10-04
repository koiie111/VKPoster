<?php

declare(strict_types=1);

namespace App\Http\Controllers\Media;

use App\Domain\Media\FolderRepository;
use App\Domain\Media\MediaKind;
use App\Domain\Media\MediaPresenter;
use App\Domain\Media\MediaRepository;
use App\Http\WorkspaceRequest;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;

/**
 * The library as JSON for the editor's "choose a file" window: one page of files, newest first, with search, type and folder.
 */
final class PickerController
{
    public function __construct(
        private readonly MediaRepository $media,
        private readonly FolderRepository $folders,
        private readonly MediaPresenter $presenter,
    ) {
    }

    public function list(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $folderRaw = WorkspaceRequest::text($request->input('folder'));
        $folder = $folderRaw === '' ? null : $this->folders->find($context, $folderRaw);
        $kind = MediaKind::tryFrom(WorkspaceRequest::text($request->input('kind')));
        $pageRaw = $request->input('page');
        $result = $this->media->page($context, $folder?->id, $kind, mb_substr(WorkspaceRequest::text($request->input('q')), 0, 100), is_string($pageRaw) && ctype_digit($pageRaw) ? (int) $pageRaw : 1);

        return Response::json([
            'items' => array_map($this->presenter->present(...), $result['rows']),
            'page' => $result['page'],
            'pages' => $result['pages'],
            'total' => $result['total'],
            'folders' => array_map(static fn ($f): array => ['id' => $f->publicId, 'name' => $f->name], $this->folders->all($context)),
        ]);
    }
}
