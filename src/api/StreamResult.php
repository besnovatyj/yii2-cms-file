<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\api;

use Besnovatyj\File\fs\operation\DownloadStream;

/**
 * Результат потоковой операции (`download`): вместо JSON-конверта адаптер отдаёт байты файла.
 * Заголовки безопасности (attachment, nosniff) выставляет адаптер — см. SECURITY.md.
 */
final class StreamResult
{
    public function __construct(public readonly DownloadStream $stream)
    {
    }
}
