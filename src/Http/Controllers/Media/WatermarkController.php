<?php

declare(strict_types=1);

namespace App\Http\Controllers\Media;

use App\Domain\Media\MediaException;
use App\Domain\Media\Watermark;
use App\Domain\Media\WatermarkRepository;
use App\Domain\Media\WatermarkService;
use App\Http\FormFlash;
use App\Http\WorkspaceRequest;
use App\Integrations\Storage\MediaStorage;
use App\Integrations\Storage\StorageException;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\Validation\Validator;
use App\Kernel\View\View;

/**
 * Watermark settings: upload a PNG logo, choose position, transparency, size and margin with a live preview,
 * pick the default, delete.
 */
final class WatermarkController
{
    /** @var array<string, string> position code => label (3×3 grid, row by row) */
    public const POSITIONS = [
        'tl' => 'Сверху слева', 'tc' => 'Сверху по центру', 'tr' => 'Сверху справа',
        'ml' => 'Посередине слева', 'mc' => 'По центру', 'mr' => 'Посередине справа',
        'bl' => 'Снизу слева', 'bc' => 'Снизу по центру', 'br' => 'Снизу справа',
    ];

    public function __construct(
        private readonly View $view,
        private readonly Validator $validator,
        private readonly FormFlash $flash,
        private readonly WatermarkRepository $watermarks,
        private readonly WatermarkService $service,
        private readonly MediaStorage $storage,
    ) {
    }

    public function show(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);

        return $this->view->response('workspace/media/watermarks.twig', [
            'workspace' => $context,
            'watermarks' => $this->watermarks->all($context),
            'positions' => self::POSITIONS,
            'base' => '/w/' . $context->workspacePublicId . '/media',
            'max' => WatermarkService::MAX_PER_WORKSPACE,
        ]);
    }

    public function create(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $back = '/w/' . $context->workspacePublicId . '/media/watermarks';
        $file = $request->file('logo');
        $name = mb_substr(WorkspaceRequest::text($request->input('name')), 0, 100);
        if ($file === null || !$file->isValid()) {
            $this->flash->toast('Выберите файл PNG с логотипом.', 'error');

            return Response::redirect($back);
        }
        try {
            $this->service->create($context, $name !== '' ? $name : 'Логотип', $file->path);
        } catch (MediaException $e) {
            $this->flash->toast($e->getMessage(), 'error');

            return Response::redirect($back);
        }
        $this->flash->toast('Водяной знак добавлен.');

        return Response::redirect($back);
    }

    public function update(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $watermark = $this->find($request);
        $input = [
            'name' => WorkspaceRequest::text($request->input('name')),
            'position' => WorkspaceRequest::text($request->input('position')),
            'opacity' => WorkspaceRequest::text($request->input('opacity')),
            'scale' => WorkspaceRequest::text($request->input('scale')),
            'margin' => WorkspaceRequest::text($request->input('margin')),
        ];
        $errors = $this->validator->make($input, [
            'name' => 'required|string|min:1|max:100',
            'position' => 'required|string|in:' . implode(',', Watermark::POSITIONS),
            'opacity' => 'required|int|min:5|max:100',
            'scale' => 'required|int|min:5|max:60',
            'margin' => 'required|int|min:0|max:20',
        ], ['name' => 'Название', 'position' => 'Положение', 'opacity' => 'Непрозрачность', 'scale' => 'Размер', 'margin' => 'Отступ'])->errors();
        if ($errors !== []) {
            $this->flash->invalid($input, $errors);
            $this->flash->toast('Проверьте настройки водяного знака.', 'error');
        } else {
            $this->service->update($context, $watermark, $input['name'], $input['position'], (int) $input['opacity'], (int) $input['scale'], (int) $input['margin'], $request->input('make_default') === '1');
            $this->flash->toast('Настройки водяного знака сохранены.');
        }

        return Response::redirect('/w/' . $context->workspacePublicId . '/media/watermarks');
    }

    public function delete(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $watermark = $this->find($request);
        $this->service->delete($context, $watermark);
        $this->flash->toast('Водяной знак «' . $watermark->name . '» удалён.');

        return Response::redirect('/w/' . $context->workspacePublicId . '/media/watermarks');
    }

    /**
     * The logo itself, for the settings page.
     */
    public function logo(Request $request): Response
    {
        $watermark = $this->find($request);
        try {
            $stream = $this->storage->read($watermark->storageKey);
        } catch (StorageException) {
            throw new HttpException(404, 'Not found');
        }

        return Response::stream($stream, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=300',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Content-Disposition' => 'inline; filename="watermark.png"',
        ]);
    }

    /**
     * Preview on a neutral picture with the settings from the query string (nothing is saved).
     */
    public function preview(Request $request): Response
    {
        $watermark = $this->find($request);
        $position = WorkspaceRequest::text($request->input('position'));
        $int = static function (mixed $v, int $min, int $max, int $default): int {
            return is_string($v) && ctype_digit($v) ? min($max, max($min, (int) $v)) : $default;
        };
        try {
            $png = $this->service->preview(
                $watermark,
                in_array($position, Watermark::POSITIONS, true) ? $position : $watermark->position,
                $int($request->input('opacity'), 5, 100, $watermark->opacity),
                $int($request->input('scale'), 5, 60, $watermark->scale),
                $int($request->input('margin'), 0, 20, $watermark->margin),
            );
        } catch (MediaException) {
            throw new HttpException(404, 'Not found');
        }

        return new Response(200, $png, ['Content-Type' => 'image/png', 'Cache-Control' => 'private, max-age=60', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }

    private function find(Request $request): Watermark
    {
        $context = WorkspaceRequest::context($request);
        $params = $request->attribute('route_params');
        $id = is_array($params) && is_string($params['watermarkId'] ?? null) ? $params['watermarkId'] : '';

        return $this->watermarks->find($context, $id) ?? throw new HttpException(404, 'Not found');
    }
}
