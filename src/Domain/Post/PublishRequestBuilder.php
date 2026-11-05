<?php

declare(strict_types=1);

namespace App\Domain\Post;

use App\Domain\Link\LinkRewriter;
use App\Domain\Media\Media;
use App\Domain\Media\MediaException;
use App\Domain\Media\MediaKind;
use App\Domain\Media\MediaLimits;
use App\Domain\Media\MediaRepository;
use App\Domain\Media\MediaService;
use App\Domain\Media\MediaUrls;
use App\Domain\Media\VariantService;
use App\Domain\Media\VideoVariantService;
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
        private readonly MediaUrls $urls,
        private readonly LinkRewriter $links,
        private readonly VideoVariantService $videoVariants,
    ) {
    }

    /**
     * @throws PostException when a file of the post is gone from the library or cannot be prepared
     */
    public function build(WorkspaceContext $context, ResolvedVariant $resolved, Capabilities $capabilities, bool $withFiles): BuiltRequest
    {
        $options = $resolved->options;
        // UTM tags and short links come first, so every length check and the platform text see the final addresses.
        $links = $this->links->rewrite($context, $resolved, $resolved->text, $capabilities->buttons ? $options->buttons : [], $withFiles);
        $text = $capabilities->textFormat === 'html' ? TextFormatter::toHtml($links['text']) : TextFormatter::toPlain($links['text']);
        $files = [];
        $temporary = [];
        $coverUrl = null;
        if ($resolved->kind === PostKind::ShortVideo && $options->shortVideo?->coverMediaId !== null) {
            $cover = $this->media->find($context, $options->shortVideo->coverMediaId);
            if ($cover === null || !$cover->isImage()) {
                throw new PostException('Обложка короткого видео удалена или не является изображением.');
            }
            if ($withFiles) {
                $coverUrl = $this->urls->signed($cover, 'original');
            }
        }
        try {
            foreach ($this->mediaFor($context, $resolved->mediaIds) as $item) {
                $files[] = match (true) {
                    !$withFiles => new PublishMedia($item->kind, '', $item->originalName, $item->mime),
                    $capabilities->publicMediaUrls => $this->publicLink($context, $item, $resolved->variant->platform, $resolved->kind),
                    default => $this->prepare($context, $item, $resolved->variant->platform, $temporary, $resolved->kind),
                };
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
            $links['buttons'],
            null,
            $capabilities->silent && $options->silent,
            $capabilities->textFormat === 'html' ? 'html' : null,
            $capabilities->disablePreview && $options->disablePreview,
            $resolved->kind === PostKind::ShortVideo ? 'reel' : $options->format,
            $resolved->kind,
            $options->shortVideo,
            $coverUrl,
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

    /** Caller owns the temporary JPEG and must delete it. */
    public function coverFile(WorkspaceContext $context, string $publicId): string
    {
        $cover = $this->media->find($context, $publicId);
        if ($cover === null || !$cover->isImage()) {
            throw new PostException('Обложка короткого видео удалена или недоступна.');
        }
        try {
            $variant = $this->variants->get($context, $cover, new VariantSpec('original', false, null, 1280, 2 * 1024 * 1024, true));
            $input = $this->storage->read($variant['key']);
            $path = $this->service->newTempFile();
            try {
                $output = fopen($path, 'wb');
                if ($output === false) {
                    throw new PostException('Не удалось подготовить обложку YouTube.');
                }
                try {
                    stream_copy_to_stream($input, $output);
                } finally {
                    fclose($output);
                }
            } catch (\Throwable $error) {
                @unlink($path);
                throw $error;
            } finally {
                fclose($input);
            }
            return $path;
        } catch (MediaException|StorageException $error) {
            throw new PostException('Не удалось подготовить обложку YouTube: ' . $error->getMessage());
        }
    }

    /**
     * For networks that download media themselves: a signed, expiring link to the file instead of a copy. Pictures go out as JPEG and,
     * when their shape is outside what the network takes, cropped to the nearest allowed ratio (the stage 05 renditions).
     *
     * @throws PostException
     */
    private function publicLink(WorkspaceContext $context, Media $item, Platform $platform, PostKind $kind): PublishMedia
    {
        if ($item->kind === MediaKind::Document) {
            throw new PostException('Файл «' . $item->originalName . '» — документ, а ' . $platform->label() . ' документы не принимает.');
        }
        if ($item->kind === MediaKind::Video) {
            $variant = $kind === PostKind::ShortVideo ? $this->videoVariants->get($context, $item) : null;
            if ($variant !== null) {
                $this->assertShortVideoSize($platform, $variant['size']);
            }
            return new PublishMedia($item->kind, '', $item->originalName, $variant['mime'] ?? $item->mime, $this->urls->signed($item, $variant['slug'] ?? 'original', $kind === PostKind::ShortVideo ? 14400 : null));
        }
        if ($item->animated) {
            throw new PostException('Файл «' . $item->originalName . '» — анимация, а ' . $platform->label() . ' принимает только обычные фото. Загрузите его как видео.');
        }
        $range = $this->limits->platforms[$platform->value]['image_ratio'] ?? null;
        $ratio = $item->ratio();
        $crop = 'original';
        if (is_array($range) && count($range) === 2 && $ratio !== null) {
            $crop = $ratio < (float) $range[0] - 0.001 ? '4x5' : ($ratio > (float) $range[1] + 0.001 ? '191x100' : 'original');
        }
        $spec = new VariantSpec($crop, false, null, VariantSpec::DEFAULT_MAX_EDGE, VariantSpec::JPEG_MAX_BYTES, true);

        return new PublishMedia($item->kind, '', $item->originalName, 'image/jpeg', $this->urls->signed($item, $spec->slug()));
    }

    /**
     * @param list<string> $temporary collects the temporary files created, for the caller to delete
     * @throws PostException
     */
    private function prepare(WorkspaceContext $context, Media $item, Platform $platform, array &$temporary, PostKind $kind = PostKind::Regular): PublishMedia
    {
        $key = $item->storageKey;
        $mime = $item->mime;
        if ($item->kind === MediaKind::Video && $kind === PostKind::ShortVideo) {
            $variant = $this->videoVariants->get($context, $item);
            $this->assertShortVideoSize($platform, $variant['size']);
            $key = $variant['key'];
            $mime = $variant['mime'];
        }
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

    private function assertShortVideoSize(Platform $platform, int $size): void
    {
        $max = $this->limits->platforms[$platform->value]['short_video_bytes'] ?? null;
        if (is_int($max) && $size > $max) {
            throw new PostException($platform->label() . ': подготовленное видео превышает лимит размера сети. Выберите файл поменьше.');
        }
    }
}
