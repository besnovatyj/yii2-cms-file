<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\archive;

use Besnovatyj\File\fs\exception\StorageFailureException;
use RuntimeException;

/**
 * Временный файл в системном tmp, удаляемый при уничтожении объекта. Архивы собираются и
 * распаковываются только через локальные временные файлы: `ZipArchive` работает с путями, а
 * хранилища (S3, ZIP-mount) — с потоками.
 */
final class TempFile
{
    public readonly string $path;

    public function __construct(string $prefix = 'fs-arc-')
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        if ($path === false) {
            throw StorageFailureException::wrap(new RuntimeException('Не удалось создать временный файл.'), null, 'archive');
        }
        $this->path = $path;
    }

    /**
     * Наполнить из потока хранилища; возвращает число записанных байт.
     *
     * @param resource $stream
     */
    public function fillFrom($stream): int
    {
        $out = @fopen($this->path, 'wb');
        if ($out === false) {
            throw StorageFailureException::wrap(new RuntimeException('Не удалось открыть временный файл.'), null, 'archive');
        }
        try {
            $bytes = stream_copy_to_stream($stream, $out);
            return $bytes === false ? 0 : $bytes;
        } finally {
            fclose($out);
        }
    }

    public function __destruct()
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }
}
