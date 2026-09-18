<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\operation;

use Besnovatyj\File\fs\path\Target;

/**
 * Решение {@see ConflictResolver}: куда писать, нужно ли предварительно удалить существующий файл,
 * либо пропустить элемент.
 */
final class ConflictDecision
{
    private function __construct(
        public readonly Target $destination,
        public readonly bool $skip,
        public readonly bool $overwrite,
    ) {
    }

    public static function proceed(Target $destination, bool $overwrite = false): self
    {
        return new self($destination, false, $overwrite);
    }

    public static function skip(Target $destination): self
    {
        return new self($destination, true, false);
    }
}
