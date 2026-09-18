<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\operation;

use Besnovatyj\File\fs\exception\AlreadyExistsException;
use Besnovatyj\File\fs\exception\InvalidOperationException;
use Besnovatyj\File\fs\exception\StorageFailureException;
use Besnovatyj\File\fs\node\Node;
use Besnovatyj\File\fs\node\NodeFactory;
use Besnovatyj\File\fs\path\PathResolver;
use Besnovatyj\File\fs\path\VirtualPath;
use League\Flysystem\FilesystemException;

/**
 * Операция `rename` (контракт §9.6) — смена имени в той же папке.
 * Переименование папки в хранилищах без нативного перемещения папок делегируется {@see TreeCopier}.
 */
final class Renamer
{
    public function __construct(
        private readonly PathResolver $resolver,
        private readonly NameGuard $names,
        private readonly NodeFactory $nodes,
        private readonly TreeCopier $trees,
    ) {
    }

    public function rename(VirtualPath $path, string $newName): Node
    {
        if ($path->isMountRoot()) {
            throw new InvalidOperationException('Точку монтирования нельзя переименовать.', $path->toString());
        }

        $source = $this->resolver->resolveExisting($path);
        $source->mount->assertSupports(FsOperation::Rename);

        $approved = $this->names->approve(FsOperation::Rename, $source->mount, $newName, $path->toString());
        $parent = $path->parent();
        assert($parent !== null); // не корень — родитель есть
        $destination = $this->resolver->resolve($parent)->child($approved);

        if ($destination->path->equals($path)) {
            return $this->nodes->fromPath($source->mount, $path, preciseMime: true);
        }
        if ($destination->exists()) {
            throw AlreadyExistsException::forPath($destination->path->toString());
        }

        try {
            if ($source->isFile() || $source->mount->capabilities->nativeDirectoryMove) {
                $source->filesystem()->move($source->location(), $destination->location());
            } else {
                $this->trees->copy($source, $destination);
                $source->filesystem()->deleteDirectory($source->location());
            }
        } catch (FilesystemException $e) {
            throw StorageFailureException::wrap($e, $path->toString(), 'rename');
        }

        return $this->nodes->fromPath($source->mount, $destination->path, preciseMime: true);
    }
}
