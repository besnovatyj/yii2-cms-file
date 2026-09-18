<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\exception;

/** Узел по указанному виртуальному пути не существует. */
final class NotFoundException extends FsException
{
    public const string CODE = 'not_found';

    public static function forPath(string $path): self
    {
        return new self('Объект не найден: ' . $path, $path);
    }
}
