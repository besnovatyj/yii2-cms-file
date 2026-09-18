<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\tus;

use RuntimeException;

/**
 * Ошибка протокола tus. Несёт HTTP-статус, который ожидает tus-клиент
 * (404 — нет загрузки, 409 — расхождение offset, 412 — версия, 413 — размер, 460 — истекла).
 */
final class TusException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
