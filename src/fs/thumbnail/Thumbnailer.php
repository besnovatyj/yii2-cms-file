<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\thumbnail;

use Besnovatyj\File\fs\exception\StorageFailureException;
use Besnovatyj\File\fs\exception\TooLargeException;
use Besnovatyj\File\fs\exception\UnsupportedException;
use Besnovatyj\File\fs\FsLimits;
use Besnovatyj\File\fs\operation\DownloadStream;
use Besnovatyj\File\fs\operation\FsOperation;
use Besnovatyj\File\fs\operation\Previewer;
use Besnovatyj\File\fs\path\PathResolver;
use Besnovatyj\File\fs\path\VirtualPath;
use League\Flysystem\FilesystemException;
use League\MimeTypeDetection\FinfoMimeTypeDetector;
use RuntimeException;

/**
 * Операция `thumbnail` (контракт §9.14): миниатюра изображения для режимов «плитка»/«значки».
 *
 * Тот же порог доверия, что у `preview`: тип по содержимому, только растровые форматы, лимиты
 * размера файла и числа пикселей (декодирование 100-мегапиксельного PNG съело бы память PHP).
 * Результат — перекодированное изображение из файлового кэша; браузеру разрешено кэшировать
 * надолго, потому что в URL входит версия файла (mtime), см. клиент.
 */
final class Thumbnailer
{
    private readonly FinfoMimeTypeDetector $byContent;

    public function __construct(
        private readonly PathResolver $resolver,
        private readonly FsLimits $limits,
        private readonly ThumbnailConfig $config,
        private readonly ThumbnailCache $cache,
        private readonly ThumbnailGeneratorInterface $generator,
        ?FinfoMimeTypeDetector $byContent = null,
    ) {
        $this->byContent = $byContent ?? new FinfoMimeTypeDetector();
    }

    public function open(VirtualPath $path, int $requestedSize): DownloadStream
    {
        $target = $this->resolver->resolveFile($path);
        $target->mount->assertSupports(FsOperation::Thumbnail);
        $fs = $target->filesystem();
        $location = $target->location();
        $size = $this->config->normalizeSize($requestedSize);

        try {
            $fileSize = $fs->fileSize($location);
            $mtime = $fs->lastModified($location);
        } catch (FilesystemException $e) {
            throw StorageFailureException::wrap($e, $path->toString(), 'thumbnail');
        }
        if ($fileSize > $this->limits->previewMaxBytes) {
            throw new TooLargeException('Файл слишком большой для миниатюры.', $path->toString(), ['limit' => $this->limits->previewMaxBytes]);
        }

        $key = ThumbnailCache::key($target->mount->id, $location, $mtime, $fileSize, $size);
        $cached = $this->cache->find($key);
        if ($cached === null) {
            $cached = $this->cache->put($key, $this->render($fs->read($location), $path, $size));
        }

        $stream = @fopen($cached, 'rb');
        if ($stream === false) {
            throw StorageFailureException::wrap(new RuntimeException('Кэш миниатюр недоступен.'), $path->toString(), 'thumbnail');
        }
        $mime = str_ends_with($cached, '.png') ? 'image/png' : 'image/jpeg';

        return new DownloadStream($stream, $path->name(), $mime, filesize($cached) ?: null, inline: true, cacheMaxAge: 86_400);
    }

    private function render(string $bytes, VirtualPath $path, int $size): ThumbnailImage
    {
        $mime = strtolower((string)$this->byContent->detectMimeTypeFromBuffer($bytes));
        if (!in_array($mime, Previewer::ALLOWED_MIMES, true)) {
            throw UnsupportedException::operation('thumbnail', $path->mount ?? '');
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            throw UnsupportedException::operation('thumbnail', $path->mount ?? '');
        }
        if ((int)$info[0] * (int)$info[1] > $this->config->maxPixels) {
            throw new TooLargeException('Изображение слишком большое для миниатюры.', $path->toString(), ['maxPixels' => $this->config->maxPixels]);
        }
        try {
            return $this->generator->generate($bytes, $mime, $size, $this->config->quality);
        } catch (RuntimeException $e) {
            throw StorageFailureException::wrap($e, $path->toString(), 'thumbnail');
        }
    }

}
