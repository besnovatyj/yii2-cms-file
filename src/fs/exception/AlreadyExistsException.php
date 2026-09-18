<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\exception;

/**
 * Целевой узел уже существует, а стратегия конфликта — `fail` (по умолчанию).
 * Фронтенд по этому коду показывает диалог «Заменить / Пропустить / Переименовать».
 */
final class AlreadyExistsException extends FsException
{
    public const string CODE = 'exists';

    public static function forPath(string $path): self
    {
        return new self('Объект с таким именем уже существует: ' . $path, $path);
    }
}
