<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\exception;

/**
 * Виртуальный путь не прошёл валидацию: обход каталога (`..`), NUL-байт, обратный слеш, управляющие
 * символы, неканонический вид, превышение длины. Это ошибка КЛИЕНТА (HTTP 400 в API), путь при этом
 * не «чинится» — см. docs/SECURITY.md.
 */
final class PathInvalidException extends FsException
{
    public const string CODE = 'path_invalid';
}
