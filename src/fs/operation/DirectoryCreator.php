<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\operation;

use Besnovatyj\File\fs\exception\AlreadyExistsException;
use Besnovatyj\File\fs\exception\StorageFailureException;
use Besnovatyj\File\fs\node\Node;
use Besnovatyj\File\fs\node\NodeFactory;
use Besnovatyj\File\fs\path\PathResolver;
use Besnovatyj\File\fs\path\VirtualPath;
use League\Flysystem\FilesystemException;

/** Операция `mkdir` (контракт §9.5). */
final class DirectoryCreator
{
    public function __construct(
        private readonly PathResolver $resolver,
        private readonly NameGuard $names,
        private readonly NodeFactory $nodes,
    ) {
    }

    public function create(VirtualPath $parent, string $name): Node
    {
        $directory = $this->resolver->resolveDirectory($parent);
        $directory->mount->assertSupports(FsOperation::Mkdir);

        $approved = $this->names->approve(FsOperation::Mkdir, $directory->mount, $name, $parent->toString());
        $destination = $directory->child($approved);

        if ($destination->exists()) {
            throw AlreadyExistsException::forPath($destination->path->toString());
        }

        try {
            $destination->filesystem()->createDirectory($destination->location());
        } catch (FilesystemException $e) {
            throw StorageFailureException::wrap($e, $destination->path->toString(), 'mkdir');
        }

        return $this->nodes->fromPath($directory->mount, $destination->path);
    }
}
