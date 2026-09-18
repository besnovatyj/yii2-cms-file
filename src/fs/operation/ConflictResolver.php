<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\operation;

use Besnovatyj\File\fs\exception\AlreadyExistsException;
use Besnovatyj\File\fs\exception\InvalidOperationException;
use Besnovatyj\File\fs\naming\UniqueNameGenerator;
use Besnovatyj\File\fs\node\NodeKind;
use Besnovatyj\File\fs\path\Target;

/**
 * Применяет {@see ConflictStrategy} к паре «папка назначения + желаемое имя» и решает, куда
 * и как писать. Один класс на все операции — семантика конфликтов одинакова у move/copy/upload.
 *
 * Перезапись разрешена только «файл поверх файла». Папка поверх папки (слияние) и смешанные
 * случаи отклоняются как `invalid_operation`: слияние деревьев — отдельная семантика с
 * собственными конфликтами внутри, её нельзя выдавать за перезапись.
 */
final class ConflictResolver
{
    public function __construct(private readonly UniqueNameGenerator $uniqueNames)
    {
    }

    /**
     * @param Target $directory Папка назначения (существует).
     * @param string $name Желаемое имя.
     * @param NodeKind $sourceKind Вид записываемого узла.
     * @throws AlreadyExistsException стратегия fail и цель занята.
     * @throws InvalidOperationException перезапись несовместимых видов.
     */
    public function resolve(
        Target $directory,
        string $name,
        ConflictStrategy $strategy,
        NodeKind $sourceKind,
    ): ConflictDecision {
        $destination = $directory->child($name);

        if (!$destination->exists()) {
            return ConflictDecision::proceed($destination);
        }

        return match ($strategy) {
            ConflictStrategy::Fail => throw AlreadyExistsException::forPath($destination->path->toString()),
            ConflictStrategy::Skip => ConflictDecision::skip($destination),
            ConflictStrategy::Rename => ConflictDecision::proceed(
                $directory->child(
                    $this->uniqueNames->generate($name, static fn(string $candidate): bool => $directory->child($candidate)->exists())
                ),
            ),
            ConflictStrategy::Overwrite => $this->overwrite($destination, $sourceKind),
        };
    }

    private function overwrite(Target $destination, NodeKind $sourceKind): ConflictDecision
    {
        $destinationIsDir = $destination->isDirectory();
        if ($sourceKind === NodeKind::File && !$destinationIsDir) {
            return ConflictDecision::proceed($destination, overwrite: true);
        }
        throw new InvalidOperationException(
            $destinationIsDir
                ? 'Перезапись папки не поддерживается (слияние папок не выполняется).'
                : 'Нельзя заменить файл папкой.',
            $destination->path->toString(),
            ['reason' => 'overwrite_kind_mismatch'],
        );
    }
}
