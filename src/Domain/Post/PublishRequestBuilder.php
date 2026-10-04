<?php

declare(strict_types=1);

namespace App\Domain\Post;

use App\Domain\Media\Media;
use App\Domain\Media\MediaException;
use App\Domain\Media\MediaLimits;
use App\Domain\Media\MediaRepository;
use App\Domain\Media\MediaService;
use App\Domain\Media\VariantService;
use App\Domain\Media\VariantSpec;
use App\Domain\Workspace\WorkspaceContext;
use App\Integrations\Social\Contracts\Capabilities;
use App\Integrations\Social\Contracts\Platform;
use App\Integrations\Social\Contracts\PublishMedia;
use App\Integrations\Social\Contracts\PublishRequest;
use App\Integrations\Storage\MediaStorage;
use App\Integrations\Storage\StorageException;

/**
 * Turns what the editor stored (markup, library file ids, options) into the `PublishRequest` one platform understands: text in the
 * platform's format, only the options it supports, and the files copied from storage to temporary paths (a picture that is too
 * heavy for the platform is replaced by a smaller rendition). Without `$withFiles` the request carries file descriptions only, which
 * is all validation in the editor needs.
 */
final class PublishRequestBuilder
{
    public function __construct(
        private readonly MediaRepository $media,
        private readonly VariantService $variants,
        private readonly MediaStorage $storage,
        private readonly MediaService $service,
        private readonly MediaLimits $limits,
    ) {
    }

    /**
     * @throws PostException when a file of the post is gone from the library or cannot be prepared
     */
    public function build(WorkspaceContext $context, ResolvedVariant $resolved, Capabilities $capabilities, bool $withFiles): BuiltRequest
    {
        $text = $capabilities->textFormat === 'html' ? TextFormatter::toHtml($resolved->text) : TextFormatter::toPlain($resolved->text);
        $options = $resolved->options;
        $files = [];
        $temporary = [];
        try {
            foreach ($this->mediaFor($context, $resolved->mediaIds) as $item) {
                $files[] = $withFiles ? $this->prepare($context, $item, $resolved->variant->platform, $temporary) : new PublishMedia($item->kind, '', $item->originalName, $item->mime);
            }
        } catch (\Throwable $e) {
            foreach ($temporary as $file) {
                @unlink($file);
            }
            throw $e;
        }

        return new BuiltRequest(new PublishRequest(
            $text,
            $files,
            $capabilities->buttons ? $options->buttons : [],
            null,
            $capabilities->silent && $options->silent,
            $capabilities->textFormat === 'html' ? 'html' : null,
            $capabilities->disablePreview && $options->disablePreview,
        ), $temporary);
    }

    /**
     * @param list<string> $ids
     * @return list<Media>
     * @throws PostException
     */
    public function mediaFor(WorkspaceContext $context, array $ids): array
    {
        $found = $this->media->findMany($context, $ids);
        if (count($found) !== count(array_unique($ids))) {
            throw new PostException('Один из файлов поста удалён из медиатеки. Откройте пост и выберите файл заново.');
        }

        return $found;
    }

    /**
     * @param list<string> $temporary collects the temporary files created, for the caller to delete
     * @throws PostException
     */
    private function prepare(WorkspaceContext $context, Media $item, Platform $platform, array &$temporary): PublishMedia
    {
        $key = $item->storageKey;
        $mime = $item->mime;
        $rules = $this->limits->platforms[$platform->value] ?? [];
        $ceiling = is_int($rules['image_bytes'] ?? null) ? $rules['image_bytes'] : null;
        if ($item->isImage() && !$item->animated && $ceiling !== null && $item->size > $ceiling) {
            try {
                $variant = $this->variants->get($context, $item, new VariantSpec('original', false, null, VariantSpec::DEFAULT_MAX_EDGE, $ceiling));
            } catch (MediaException $e) {
                throw new PostException($e->getMessage());
            }
            $key = $variant['key'];
            $mime = $variant['mime'];
        }
        $path = $this->service->newTempFile();
        $temporary[] = $path;
        try {
            $in = $this->storage->read($key);
            $out = fopen($path, 'wb');
            if ($out === false) {
                fclose($in);
                throw new PostException('Не удалось подготовить файл «' . $item->originalName . '» к отправке.', [], false, true);
            }
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);
        } catch (StorageException) {
            throw new PostException('Не удалось прочитать файл «' . $item->originalName . '» из хранилища. Попробуйте позже.', [], false, true);
        }

        return new PublishMedia($item->kind, $path, $item->originalName, $mime);
    }
}
