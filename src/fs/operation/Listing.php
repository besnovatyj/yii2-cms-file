<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\operation;

use Besnovatyj\File\fs\node\Node;

/**
 * Результат листинга: сама папка, страница её детей, курсор продолжения и общее число
 * (после фильтра). `sorted` — сервер отсортировал по запрошенному ключу.
 */
final class Listing
{
    /**
     * @param list<Node> $items
     */
    public function __construct(
        public readonly Node $node,
        public readonly array $items,
        public readonly ?string $nextCursor,
        public readonly ?int $total,
        public readonly bool $sorted = true,
    ) {
    }
}
