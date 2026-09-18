<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\operation;

/**
 * Счётчики бюджета одного поиска: просмотренные записи и найденные результаты.
 * Общий на все хранилища при поиске из корня — поэтому отдельный объект, а не локальные переменные.
 */
final class SearchBudget
{
    public int $scanned = 0;
    public int $found = 0;
    private bool $exhausted = false;

    public function __construct(
        private readonly int $maxEntries,
        private readonly int $maxResults,
    ) {
    }

    /** Учесть просмотренную запись; false — бюджет записей исчерпан, обход надо прекратить. */
    public function consumeEntry(): bool
    {
        if ($this->scanned >= $this->maxEntries) {
            $this->exhausted = true;
            return false;
        }
        $this->scanned++;
        return true;
    }

    /** Учесть найденный результат; false — набрали максимум, обход надо прекратить. */
    public function consumeResult(): bool
    {
        $this->found++;
        if ($this->found >= $this->maxResults) {
            $this->exhausted = true;
            return false;
        }
        return true;
    }

    public function exhausted(): bool
    {
        return $this->exhausted;
    }
}
