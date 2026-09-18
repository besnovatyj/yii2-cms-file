<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\operation;

use Besnovatyj\File\fs\exception\StorageFailureException;
use Besnovatyj\File\fs\path\PathResolver;
use Besnovatyj\File\fs\path\VirtualPath;
use League\Flysystem\FilesystemException;

/**
 * Операция `download` (контракт §9.10): открывает поток файла для отдачи через бэкенд.
 * Способ отдачи (attachment + nosniff) — ответственность API-слоя, здесь только данные.
 */
final class Downloader
{
    public function __construct(private readonly PathResolver $resolver)
    {
    }

    public function open(VirtualPath $path): DownloadStream
    {
        $target = $this->resolver->resolveFile($path);
        $target->mount->assertSupports(FsOperation::Download);
        $fs = $target->filesystem();
        $location = $target->location();

        try {
            $stream = $fs->readStream($location);
        } catch (FilesystemException $e) {
            throw StorageFailureException::wrap($e, $path->toString(), 'download');
        }

        $mime = 'application/octet-stream';
        $size = null;
        try {
            $mime = $fs->mimeType($location) ?: $mime;
        } catch (FilesystemException) {
            // тип не определился — octet-stream безопаснее любого угадывания
        }
        try {
            $size = $fs->fileSize($location);
        } catch (FilesystemException) {
            // без Content-Length скачивание всё равно работает
        }

        return new DownloadStream($stream, $path->name(), $mime, $size);
    }
}
