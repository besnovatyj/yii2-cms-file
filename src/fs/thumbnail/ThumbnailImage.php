<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\thumbnail;

/** Готовая миниатюра: байты + MIME + расширение файла кэша. */
final class ThumbnailImage
{
    public function __construct(
        public readonly string $bytes,
        public readonly string $mime,
        public readonly string $extension,
    ) {
    }
}
