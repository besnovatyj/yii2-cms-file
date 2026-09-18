<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\exception;

/** Превышен лимит размера/количества (файл, пакет, дерево для переноса). */
final class TooLargeException extends FsException
{
    public const string CODE = 'too_large';
}
