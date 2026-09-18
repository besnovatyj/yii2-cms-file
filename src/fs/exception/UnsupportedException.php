<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\exception;

/**
 * Хранилище (или бэкенд) физически не поддерживает операцию — см. capabilities точки монтирования.
 * Нормально работающий фронтенд сюда не попадает: он гасит недоступные действия по `describe`.
 */
final class UnsupportedException extends FsException
{
    public const string CODE = 'unsupported';

    public static function operation(string $operation, string $mountId): self
    {
        return new self(
            "Операция '{$operation}' не поддерживается хранилищем '{$mountId}'.",
            '/' . $mountId,
            ['operation' => $operation, 'mount' => $mountId],
        );
    }
}
