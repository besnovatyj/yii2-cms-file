<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\exception;

/**
 * Логически недопустимая операция при корректном входе: перенос папки в саму себя или в потомка,
 * удаление корня точки монтирования, перезапись папки файлом и т. п.
 */
final class InvalidOperationException extends FsException
{
    public const string CODE = 'invalid_operation';
}
