<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\operation;

/** Открытый поток файла для отдачи клиенту. Получатель обязан закрыть {@see $stream}. */
final class DownloadStream
{
    /**
     * @param resource $stream
     */
    public function __construct(
        public readonly mixed $stream,
        public readonly string $name,
        public readonly string $mime,
        public readonly ?int $size,
        /** Отдавать inline (только для проверенных растровых изображений, см. Previewer). */
        public readonly bool $inline = false,
        /** Сколько секунд браузер может кэшировать ответ (0 — no-store). Только для inline. */
        public readonly int $cacheMaxAge = 0,
    ) {
    }
}
