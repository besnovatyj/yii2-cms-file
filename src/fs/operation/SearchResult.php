<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\operation;

use Besnovatyj\File\fs\node\Node;

/** Результат поиска: узел-корень, найденные узлы, признак усечения и число просмотренных записей. */
final class SearchResult
{
    /**
     * @param list<Node> $items
     * @param bool $truncated Достигнут лимит результатов или просмотренных записей — список неполный.
     * @param int $scanned Сколько записей хранилища просмотрено (для диагностики и UI).
     */
    public function __construct(
        public readonly Node $node,
        public readonly array $items,
        public readonly bool $truncated,
        public readonly int $scanned,
    ) {
    }
}
