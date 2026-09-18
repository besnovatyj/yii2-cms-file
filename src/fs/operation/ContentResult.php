<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\operation;

use Besnovatyj\File\fs\node\Node;

/** Результат операции `content` (контракт §9.4). */
final class ContentResult
{
    public function __construct(
        public readonly Node $node,
        public readonly string $content,
        public readonly bool $truncated,
        public readonly bool $binary,
    ) {
    }
}
