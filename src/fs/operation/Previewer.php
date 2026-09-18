<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\operation;

use Besnovatyj\File\fs\exception\StorageFailureException;
use Besnovatyj\File\fs\exception\TooLargeException;
use Besnovatyj\File\fs\exception\UnsupportedException;
use Besnovatyj\File\fs\FsLimits;
use Besnovatyj\File\fs\path\PathResolver;
use Besnovatyj\File\fs\path\VirtualPath;
use League\Flysystem\FilesystemException;
use League\MimeTypeDetection\FinfoMimeTypeDetector;

/**
 * Операция `preview` (контракт §9.13): inline-отдача изображения для панели предпросмотра там,
 * где у файла нет публичного URL (ZIP, приватный S3).
 *
 * Inline-отдача пользовательского контента с домена админки — это риск хранимого XSS, поэтому
 * правила жёсткие: тип определяется по СОДЕРЖИМОМУ (magic bytes), а не по расширению;
 * разрешены только растровые форматы (никаких SVG/HTML/PDF); размер ограничен; адаптер отдаёт
 * заголовки `nosniff` и CSP `sandbox`. Всё остальное — через `download` (attachment).
 */
final class Previewer
{
    /** Растровые форматы, которые браузер отобразит и в которых нет активного содержимого. */
    public const array ALLOWED_MIMES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/bmp', 'image/x-ms-bmp',
    ];

    private readonly FinfoMimeTypeDetector $byContent;

    public function __construct(
        private readonly PathResolver $resolver,
        private readonly FsLimits $limits,
        ?FinfoMimeTypeDetector $byContent = null,
    ) {
        $this->byContent = $byContent ?? new FinfoMimeTypeDetector();
    }

    public function open(VirtualPath $path): DownloadStream
    {
        $target = $this->resolver->resolveFile($path);
        $target->mount->assertSupports(FsOperation::Preview);
        $fs = $target->filesystem();
        $location = $target->location();

        try {
            $size = $fs->fileSize($location);
        } catch (FilesystemException $e) {
            throw StorageFailureException::wrap($e, $path->toString(), 'preview');
        }
        if ($size > $this->limits->previewMaxBytes) {
            throw new TooLargeException('Файл слишком большой для предпросмотра.', $path->toString(), ['limit' => $this->limits->previewMaxBytes]);
        }

        try {
            $stream = $fs->readStream($location);
        } catch (FilesystemException $e) {
            throw StorageFailureException::wrap($e, $path->toString(), 'preview');
        }

        // Тип — по первым байтам, не по расширению и не по метаданным хранилища.
        $head = stream_get_contents($stream, 8192);
        $mime = strtolower((string)$this->byContent->detectMimeTypeFromBuffer($head === false ? '' : $head));
        if (!in_array($mime, self::ALLOWED_MIMES, true)) {
            fclose($stream);
            throw UnsupportedException::operation('preview', $target->mount->id);
        }

        // Поток уже прочитан на 8 КБ: перематываем, если можно, иначе открываем заново.
        if (stream_get_meta_data($stream)['seekable']) {
            rewind($stream);
        } else {
            fclose($stream);
            $stream = $fs->readStream($location);
        }

        return new DownloadStream($stream, $path->name(), $mime, $size, inline: true, cacheMaxAge: 300);
    }
}
