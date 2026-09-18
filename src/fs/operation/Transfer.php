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
use Besnovatyj\File\fs\node\NodeFactory;
use Besnovatyj\File\fs\node\NodeKind;
use Besnovatyj\File\fs\operation\report\ItemResult;
use Besnovatyj\File\fs\operation\report\OperationReport;
use Besnovatyj\File\fs\path\PathResolver;
use Besnovatyj\File\fs\path\Target;
use Besnovatyj\File\fs\path\VirtualPath;
use League\Flysystem\FilesystemException;

/**
 * Операции `move` и `copy` (контракт §9.7) — пакетные, с per-item отчётом, между любыми точками
 * монтирования (ADR-8).
 *
 * Алгоритм для каждого источника:
 *  1. проверки: существует, не корень mount'а, не предок/не равен папке назначения;
 *  2. имя назначения проходит {@see NameGuard} (блок-лист расширений действует и здесь);
 *  3. {@see ConflictResolver} решает судьбу при занятой цели;
 *  4. выполнение: в одном хранилище — нативные move/copy (папки — нативно только если адаптер
 *     умеет, иначе {@see TreeCopier}); между хранилищами — потоковое копирование + удаление источника.
 *
 * Ошибки одного элемента не прерывают пакет: элемент помечается `failed`, остальные обрабатываются.
 */
final class Transfer
{
    public function __construct(
        private readonly PathResolver $resolver,
        private readonly NameGuard $names,
        private readonly ConflictResolver $conflicts,
        private readonly TreeCopier $trees,
        private readonly NodeFactory $nodes,
        private readonly FsLimits $limits,
    ) {
    }

    /**
     * @param list<VirtualPath> $sources
     */
    public function move(array $sources, VirtualPath $targetDirectory, ConflictStrategy $strategy): OperationReport
    {
        return $this->run(FsOperation::Move, $sources, $targetDirectory, $strategy);
    }

    /**
     * @param list<VirtualPath> $sources
     */
    public function copy(array $sources, VirtualPath $targetDirectory, ConflictStrategy $strategy): OperationReport
    {
        return $this->run(FsOperation::Copy, $sources, $targetDirectory, $strategy);
    }

    /**
     * @param list<VirtualPath> $sources
     */
    private function run(FsOperation $operation, array $sources, VirtualPath $targetDirectory, ConflictStrategy $strategy): OperationReport
    {
        if (count($sources) > $this->limits->maxBatchItems) {
            throw new TooLargeException(
                sprintf('Слишком много элементов в пакете: %d (лимит %d).', count($sources), $this->limits->maxBatchItems),
                null,
                ['count' => count($sources), 'limit' => $this->limits->maxBatchItems],
            );
        }

        // Папка назначения и её поддержка операции проверяются один раз — это ошибка ВСЕГО запроса.
        $destinationDir = $this->resolver->resolveDirectory($targetDirectory);
        $destinationDir->mount->assertSupports($operation);

        $report = new OperationReport($operation);
        foreach ($sources as $sourcePath) {
            $report->add($this->transferOne($operation, $sourcePath, $destinationDir, $strategy));
        }
        return $report;
    }

    private function transferOne(FsOperation $operation, VirtualPath $sourcePath, Target $destinationDir, ConflictStrategy $strategy): ItemResult
    {
        $sourceString = $sourcePath->toString();
        try {
            if ($sourcePath->isRoot() || $sourcePath->isMountRoot()) {
                throw new InvalidOperationException('Точку монтирования нельзя переместить или скопировать.', $sourceString);
            }
            if ($sourcePath->equals($destinationDir->path) || $sourcePath->isAncestorOf($destinationDir->path)) {
                throw new InvalidOperationException('Нельзя переместить или скопировать папку в саму себя или в её подпапку.', $sourceString);
            }

            $source = $this->resolver->resolveExisting($sourcePath);
            $isMove = $operation === FsOperation::Move;
            if ($isMove) {
                // При переносе источник тоже меняется — его хранилище должно разрешать удаление.
                $source->mount->assertSupports(FsOperation::Delete);
            } else {
                $source->mount->assertSupports(FsOperation::Stat);
            }

            $sourceKind = $source->isFile() ? NodeKind::File : NodeKind::Dir;
            $name = $this->names->approveExisting(
                $operation,
                $destinationDir->mount,
                $sourcePath->name(),
                $destinationDir->path->child($sourcePath->name())->toString(),
            );

            // Перенос в собственную папку без смены имени — ничего не делаем.
            $parent = $sourcePath->parent();
            if ($isMove && $parent !== null && $parent->equals($destinationDir->path) && $name === $sourcePath->name()) {
                return ItemResult::skipped($sourceString, $sourceString);
            }

            $decision = $this->conflicts->resolve($destinationDir, $name, $strategy, $sourceKind);
            if ($decision->skip) {
                return ItemResult::skipped($sourceString, $decision->destination->path->toString());
            }
            $destination = $decision->destination;

            try {
                if ($decision->overwrite) {
                    $destination->filesystem()->delete($destination->location());
                }
                $this->execute($isMove, $source, $destination, $sourceKind);
            } catch (FilesystemException $e) {
                throw StorageFailureException::wrap($e, $sourceString, $operation->value);
            }

            $node = $this->nodes->fromPath($destination->mount, $destination->path, preciseMime: $sourceKind === NodeKind::File);
            return ItemResult::ok($sourceString, $node);
        } catch (FsException $e) {
            return ItemResult::failed($sourceString, $e);
        }
    }

    /**
     * @throws FilesystemException
     */
    private function execute(bool $isMove, Target $source, Target $destination, NodeKind $kind): void
    {
        $sameMount = $source->mount === $destination->mount;

        if ($kind === NodeKind::File) {
            if ($sameMount) {
                $isMove
                    ? $source->filesystem()->move($source->location(), $destination->location())
                    : $source->filesystem()->copy($source->location(), $destination->location());
                return;
            }
            $this->trees->copyFile($source, $destination);
            if ($isMove) {
                $source->filesystem()->delete($source->location());
            }
            return;
        }

        if ($sameMount && $isMove && $source->mount->capabilities->nativeDirectoryMove) {
            $source->filesystem()->move($source->location(), $destination->location());
            return;
        }

        $this->trees->copy($source, $destination);
        if ($isMove) {
            $source->filesystem()->deleteDirectory($source->location());
        }
    }
}
