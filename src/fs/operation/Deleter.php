<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\operation;

use Besnovatyj\File\fs\exception\FsException;
use Besnovatyj\File\fs\exception\InvalidOperationException;
use Besnovatyj\File\fs\exception\StorageFailureException;
use Besnovatyj\File\fs\exception\TooLargeException;
use Besnovatyj\File\fs\FsLimits;
use Besnovatyj\File\fs\operation\report\ItemResult;
use Besnovatyj\File\fs\operation\report\OperationReport;
use Besnovatyj\File\fs\path\PathResolver;
use Besnovatyj\File\fs\path\VirtualPath;
use League\Flysystem\FilesystemException;

/**
 * Операция `delete` (контракт §9.8) — пакетное удаление с per-item отчётом.
 * Папки удаляются рекурсивно (семантика проводника: подтверждение — на клиенте).
 */
final class Deleter
{
    public function __construct(
        private readonly PathResolver $resolver,
        private readonly FsLimits $limits,
    ) {
    }

    /**
     * @param list<VirtualPath> $paths
     */
    public function delete(array $paths): OperationReport
    {
        if (count($paths) > $this->limits->maxBatchItems) {
            throw new TooLargeException(
                sprintf('Слишком много элементов в пакете: %d (лимит %d).', count($paths), $this->limits->maxBatchItems),
                null,
                ['count' => count($paths), 'limit' => $this->limits->maxBatchItems],
            );
        }

        $report = new OperationReport(FsOperation::Delete);
        foreach ($paths as $path) {
            $report->add($this->deleteOne($path));
        }
        return $report;
    }

    private function deleteOne(VirtualPath $path): ItemResult
    {
        $pathString = $path->toString();
        try {
            if ($path->isRoot() || $path->isMountRoot()) {
                throw new InvalidOperationException('Точку монтирования удалить нельзя.', $pathString);
            }
            $target = $this->resolver->resolveExisting($path);
            $target->mount->assertSupports(FsOperation::Delete);

            try {
                if ($target->isFile()) {
                    $target->filesystem()->delete($target->location());
                } else {
                    $target->filesystem()->deleteDirectory($target->location());
                }
            } catch (FilesystemException $e) {
                throw StorageFailureException::wrap($e, $pathString, 'delete');
            }

            return ItemResult::done($pathString);
        } catch (FsException $e) {
            return ItemResult::failed($pathString, $e);
        }
    }
}
