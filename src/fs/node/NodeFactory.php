<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\node;

use Besnovatyj\File\fs\mount\Mount;
use Besnovatyj\File\fs\mount\MountRegistry;
use Besnovatyj\File\fs\path\VirtualPath;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemException;
use League\Flysystem\StorageAttributes;
use League\MimeTypeDetection\ExtensionMimeTypeDetector;

/**
 * Сборка {@see Node} из данных Flysystem и описаний точек монтирования.
 *
 * Здесь сосредоточено всё знание о том, «как из атрибутов адаптера получается узел контракта»:
 * дешёвый MIME по расширению (без обращения к хранилищу — иначе листинг S3 стал бы N+1 запросами),
 * публичный URL через резолвер mount'а, синтетические узлы для '/' и точек монтирования.
 */
final class NodeFactory
{
    private readonly ExtensionMimeTypeDetector $mimeByExtension;

    public function __construct(?ExtensionMimeTypeDetector $mimeByExtension = null)
    {
        $this->mimeByExtension = $mimeByExtension ?? new ExtensionMimeTypeDetector();
    }

    /** Узел из элемента листинга Flysystem (`listContents`). */
    public function fromAttributes(Mount $mount, StorageAttributes $attributes): Node
    {
        $path = VirtualPath::fromMountAndRelative($mount->id, $attributes->path());

        if ($attributes instanceof FileAttributes) {
            return new Node(
                path: $path,
                kind: NodeKind::File,
                size: $attributes->fileSize(),
                mtime: self::positiveOrNull($attributes->lastModified()),
                mime: $attributes->mimeType() ?? $this->mimeByExtension->detectMimeTypeFromPath($attributes->path()),
                url: $mount->urlFor($attributes->path()),
                visibility: $attributes->visibility(),
            );
        }

        return new Node(
            path: $path,
            kind: NodeKind::Dir,
            mtime: self::positiveOrNull($attributes->lastModified()),
            visibility: $attributes->visibility(),
        );
    }

    /**
     * Узел по пути, опрашивая хранилище поштучно — для отчётов после операций (rename/move/upload),
     * где Flysystem не возвращает атрибуты. Каждый вызов метаданных обёрнут: адаптер вправе не уметь
     * что-то (например, lastModified у ZIP-директорий) — это не должно ронять успешную операцию.
     */
    public function fromPath(Mount $mount, VirtualPath $path, bool $preciseMime = false): Node
    {
        $fs = $mount->filesystem();
        $location = $path->relative();

        if ($location !== '' && $fs->directoryExists($location)) {
            return new Node(
                path: $path,
                kind: NodeKind::Dir,
                mtime: self::quiet(static fn(): ?int => self::positiveOrNull($fs->lastModified($location))),
            );
        }
        if ($location === '') {
            return $this->mountNode($mount);
        }

        $mime = $preciseMime
            ? self::quiet(static fn(): ?string => $fs->mimeType($location))
            : null;

        return new Node(
            path: $path,
            kind: NodeKind::File,
            size: self::quiet(static fn(): ?int => $fs->fileSize($location)),
            mtime: self::quiet(static fn(): ?int => self::positiveOrNull($fs->lastModified($location))),
            mime: $mime ?? $this->mimeByExtension->detectMimeTypeFromPath($location),
            url: $mount->urlFor($location),
            visibility: $mount->capabilities->visibility
                ? self::quiet(static fn(): ?string => $fs->visibility($location))
                : null,
        );
    }

    /** Синтетический узел точки монтирования («диск» в корне). */
    public function mountNode(Mount $mount): Node
    {
        return new Node(
            path: VirtualPath::mountRoot($mount->id),
            kind: NodeKind::Mount,
            meta: [
                'label' => $mount->label,
                'icon' => $mount->icon,
                'readOnly' => $mount->readOnly,
                'hasChildren' => null, // неизвестно без листинга — дерево подгрузит лениво
            ],
        );
    }

    /** Синтетический узел виртуального корня '/'. */
    public function rootNode(MountRegistry $registry): Node
    {
        return new Node(
            path: VirtualPath::root(),
            kind: NodeKind::Dir,
            meta: ['label' => '/', 'hasChildren' => $registry->all() !== []],
        );
    }

    /** Flysystem может вернуть 0/отрицательное время у синтетических записей — считаем неизвестным. */
    private static function positiveOrNull(?int $value): ?int
    {
        return $value !== null && $value > 0 ? $value : null;
    }

    /**
     * Выполняет запрос метаданных, глотая ошибки адаптера: метаданные — вспомогательная информация,
     * их отсутствие не должно превращать успешную операцию в ошибку.
     *
     * @template T
     * @param callable(): T $probe
     * @return T|null
     */
    private static function quiet(callable $probe): mixed
    {
        try {
            return $probe();
        } catch (FilesystemException) {
            return null;
        }
    }
}
