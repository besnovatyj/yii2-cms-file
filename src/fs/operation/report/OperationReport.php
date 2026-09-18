<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\operation\report;

use Besnovatyj\File\fs\operation\FsOperation;

/**
 * Отчёт пакетной операции (контракт §7, ADR-7): частичный успех — нормальный исход,
 * каждый элемент несёт свой статус. Счётчики вычисляются, а не хранятся, — их нельзя рассинхронизировать.
 */
final class OperationReport
{
    /** @var list<ItemResult> */
    private array $items = [];

    public function __construct(public readonly FsOperation $operation)
    {
    }

    public function add(ItemResult $item): void
    {
        $this->items[] = $item;
    }

    /** @return list<ItemResult> */
    public function items(): array
    {
        return $this->items;
    }

    public function total(): int
    {
        return count($this->items);
    }

    public function count(ItemStatus $status): int
    {
        return count(array_filter($this->items, static fn(ItemResult $i): bool => $i->status === $status));
    }
}
