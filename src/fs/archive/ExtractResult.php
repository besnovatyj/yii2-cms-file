<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\archive;

use Besnovatyj\File\fs\node\Node;

/** Итог распаковки: папка назначения, счётчики и пропущенные записи (с кодом причины). */
final class ExtractResult
{
    public const int MAX_SKIPPED_LISTED = 100;

    /**
     * @param list<array{name: string, code: string, message: string}> $skipped Первые N пропущенных записей.
     */
    public function __construct(
        public readonly Node $node,
        public readonly int $total,
        public readonly int $extracted,
        public readonly array $skipped,
    ) {
    }
}
