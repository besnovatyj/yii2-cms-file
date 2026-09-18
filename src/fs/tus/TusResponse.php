<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\tus;

/** Ответ tus-сервера: статус и заголовки (тела у tus-ответов нет). */
final class TusResponse
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(public readonly int $status, public readonly array $headers)
    {
    }
}
