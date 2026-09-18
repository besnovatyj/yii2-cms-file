<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\exception;

/**
 * Клиент сослался на незарегистрированную точку монтирования (первый сегмент виртуального пути).
 */
final class MountUnknownException extends FsException
{
    public const string CODE = 'mount_unknown';

    public static function forId(string $mountId): self
    {
        return new self("Неизвестная точка монтирования: '{$mountId}'.", '/' . $mountId, ['mount' => $mountId]);
    }
}
