<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\operation;

use Besnovatyj\File\fs\exception\AlreadyExistsException;
use Besnovatyj\File\fs\exception\StorageFailureException;
use Besnovatyj\File\fs\naming\NameSanitizer;
use Besnovatyj\File\fs\node\NodeFactory;
use Besnovatyj\File\fs\node\NodeKind;
use Besnovatyj\File\fs\path\PathResolver;
use Besnovatyj\File\fs\path\VirtualPath;
use League\Flysystem\FilesystemException;

/**
 * Операция `upload` (контракт §9.9).
 *
 * Единственная операция, где имя исправляется, а не отклоняется (ADR-5): имя пришло из ОС
 * пользователя, и требовать «переименуйте файл у себя и загрузите снова» — плохой UX. Клиент
 * получает фактическое имя и флаг `renamed`. Политика (расширения, размер, содержимое) при этом
 * применяется строго — заблокированное расширение отклоняется, а не «чинится».
 *
 * Стратегия `skip` для загрузки эквивалентна `fail`: пропуск решает клиент, не отправляя файл.
 */
final class Uploader
{
    public function __construct(
        private readonly PathResolver $resolver,
        private readonly NameSanitizer $sanitizer,
        private readonly NameGuard $names,
        private readonly ConflictResolver $conflicts,
        private readonly NodeFactory $nodes,
    ) {
    }

    public function upload(
        VirtualPath $directory,
        UploadSource $source,
        ?string $requestedName,
        ConflictStrategy $strategy,
    ): UploadResult {
        $target = $this->resolver->resolveDirectory($directory);
        $target->mount->assertSupports(FsOperation::Upload);

        $requested = $requestedName !== null && $requestedName !== '' ? $requestedName : $source->clientName;
        $sanitized = $this->sanitizer->sanitize($requested);
        $approved = $this->names->approve(
            FsOperation::Upload,
            $target->mount,
            $sanitized,
            $directory->child($sanitized)->toString(),
            $source,
        );

        if ($strategy === ConflictStrategy::Skip) {
            $strategy = ConflictStrategy::Fail;
        }
        $decision = $this->conflicts->resolve($target, $approved, $strategy, NodeKind::File);
        if ($decision->skip) {
            throw AlreadyExistsException::forPath($decision->destination->path->toString());
        }
        $destination = $decision->destination;

        $stream = $source->openStream();
        try {
            if ($decision->overwrite) {
                $destination->filesystem()->delete($destination->location());
            }
            $destination->filesystem()->writeStream($destination->location(), $stream);
        } catch (FilesystemException $e) {
            throw StorageFailureException::wrap($e, $destination->path->toString(), 'upload');
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $node = $this->nodes->fromPath($target->mount, $destination->path, preciseMime: true);

        return new UploadResult($node, $node->name() !== $requested, $requested);
    }
}
