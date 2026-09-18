<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\exception;

/**
 * Операция запрещена политикой доступа: точка монтирования только для чтения, права узла и т. п.
 * (Права на уровне маршрутов проверяет ядро ДО вызова операции; сюда попадают доменные запреты.)
 */
final class ForbiddenException extends FsException
{
    public const string CODE = 'forbidden';

    public static function readOnlyMount(string $mountId): self
    {
        return new self(
            "Хранилище '{$mountId}' доступно только для чтения.",
            '/' . $mountId,
            ['mount' => $mountId, 'reason' => 'read_only'],
        );
    }
}
