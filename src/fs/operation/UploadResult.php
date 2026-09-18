<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\operation;

use Besnovatyj\File\fs\node\Node;

/** Результат `upload` (контракт §9.9): фактический узел и было ли имя изменено сервером. */
final class UploadResult
{
    public function __construct(
        public readonly Node $node,
        public readonly bool $renamed,
        public readonly string $requestedName,
    ) {
    }
}
