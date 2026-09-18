<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\operation;

use Besnovatyj\File\fs\exception\StorageFailureException;
use Besnovatyj\File\fs\FsLimits;
use Besnovatyj\File\fs\node\Node;
use Besnovatyj\File\fs\node\NodeFactory;
use Besnovatyj\File\fs\path\PathResolver;
use Besnovatyj\File\fs\path\VirtualPath;
use League\Flysystem\FilesystemException;

/**
 * Точные метаданные узла (`stat`) и текстовое содержимое файла (`content`).
 *
 * `stat` дороже листинга: MIME определяется по содержимому, у изображений вычисляются размеры.
 * Поэтому он вызывается точечно (панель свойств, предпросмотр), а не для каждого элемента списка.
 */
final class Inspector
{
    public function __construct(
        private readonly PathResolver $resolver,
        private readonly NodeFactory $nodes,
        private readonly FsLimits $limits,
    ) {
    }

    public function stat(VirtualPath $path): Node
    {
        if ($path->isRoot()) {
            return $this->nodes->rootNode($this->resolver->mounts());
        }
        $target = $this->resolver->resolveExisting($path);
        $target->mount->assertSupports(FsOperation::Stat);

        $node = $this->nodes->fromPath($target->mount, $path, preciseMime: true);

        if ($node->isFile() && str_starts_with((string)$node->mime, 'image/')) {
            $dimensions = $this->imageDimensions($target->filesystem()->readStream($target->location()), $node->size);
            if ($dimensions !== null) {
                $node = $node->withMeta(['image' => $dimensions]);
            }
        }

        return $node;
    }

    /**
     * Первые N байт файла как текст. Бинарные файлы (NUL-байт, невалидный UTF-8) помечаются
     * `binary: true` с пустым содержимым — клиент решает, что показать.
     */
    public function content(VirtualPath $path, ?int $maxBytes = null): ContentResult
    {
        $target = $this->resolver->resolveFile($path);
        $target->mount->assertSupports(FsOperation::Content);

        $cap = min($maxBytes ?? $this->limits->contentMaxBytes, $this->limits->contentMaxBytes);
        $node = $this->nodes->fromPath($target->mount, $path, preciseMime: true);

        try {
            $stream = $target->filesystem()->readStream($target->location());
        } catch (FilesystemException $e) {
            throw StorageFailureException::wrap($e, $path->toString(), 'content');
        }

        try {
            $chunk = stream_get_contents($stream, $cap + 1);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        $chunk = $chunk === false ? '' : $chunk;
        $truncated = strlen($chunk) > $cap;
        $chunk = substr($chunk, 0, $cap);

        $binary = str_contains($chunk, "\0");
        if (!$binary && $chunk !== '' && !mb_check_encoding($chunk, 'UTF-8')) {
            // Обрезка могла разрезать многобайтовый символ на границе — проверяем без хвоста.
            $binary = !mb_check_encoding(substr($chunk, 0, -3), 'UTF-8');
            if (!$binary) {
                $chunk = mb_strcut($chunk, 0, strlen($chunk), 'UTF-8');
            }
        }

        return new ContentResult($node, $binary ? '' : $chunk, $truncated, $binary);
    }

    /**
     * Размеры изображения. Файл читается в память целиком, поэтому есть потолок по размеру —
     * у гигантских изображений размеры просто не сообщаются.
     *
     * @param resource $stream
     * @return array{width: int, height: int}|null
     */
    private function imageDimensions($stream, ?int $size): ?array
    {
        try {
            if ($size !== null && $size > $this->limits->imageProbeMaxBytes) {
                return null;
            }
            $bytes = stream_get_contents($stream, $this->limits->imageProbeMaxBytes + 1);
            if ($bytes === false || strlen($bytes) > $this->limits->imageProbeMaxBytes) {
                return null;
            }
            $info = @getimagesizefromstring($bytes);
            return $info === false ? null : ['width' => (int)$info[0], 'height' => (int)$info[1]];
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
