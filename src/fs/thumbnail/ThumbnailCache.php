<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\thumbnail;

use RuntimeException;

/**
 * Файловый кэш миниатюр: `{dir}/{ab}/{key}.{ext}`, где key = sha1(mount, путь, mtime, размер
 * файла, размер миниатюры). Изменился файл — изменился ключ, старая миниатюра остаётся сиротой
 * и удаляется по TTL ({@see purge()}), поэтому инвалидировать кэш при rename/delete не нужно.
 */
final class ThumbnailCache
{
    public function __construct(private readonly string $dir)
    {
    }

    public static function key(string $mountId, string $relative, ?int $mtime, ?int $fileSize, int $size): string
    {
        return sha1(implode("\0", [$mountId, $relative, (string)$mtime, (string)$fileSize, (string)$size]));
    }

    /** Путь к готовой миниатюре либо null. */
    public function find(string $key): ?string
    {
        foreach (['jpg', 'png'] as $ext) {
            $path = $this->path($key, $ext);
            if (is_file($path)) {
                return $path;
            }
        }
        return null;
    }

    public function put(string $key, ThumbnailImage $image): string
    {
        $path = $this->path($key, $image->extension);
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new RuntimeException("thumbnail: не удалось создать каталог {$dir}.");
        }
        // Атомарно: параллельные запросы одной миниатюры не увидят полузаписанный файл.
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $image->bytes) === false || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('thumbnail: не удалось записать миниатюру в кэш.');
        }
        return $path;
    }

    /** Удалить миниатюры старше $ttl секунд (по времени последнего доступа/изменения). Возвращает число удалённых. */
    public function purge(int $ttl): int
    {
        if (!is_dir($this->dir)) {
            return 0;
        }
        $deadline = time() - $ttl;
        $count = 0;
        foreach (glob($this->dir . '/*/*.{jpg,png}', GLOB_BRACE) ?: [] as $file) {
            $stamp = @filemtime($file);
            if ($stamp !== false && $stamp < $deadline && @unlink($file)) {
                $count++;
            }
        }
        return $count;
    }

    private function path(string $key, string $ext): string
    {
        return $this->dir . '/' . substr($key, 0, 2) . '/' . $key . '.' . $ext;
    }
}
