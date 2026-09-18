<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\archive;

use Besnovatyj\File\fs\exception\TooLargeException;

/** Бюджет сборки архива: число записей и суммарный несжатый размер. Превышение — `too_large`. */
final class ArchiveBudget
{
    private int $entries = 0;
    private int $bytes = 0;

    public function __construct(
        private readonly int $maxEntries,
        private readonly int $maxBytes,
    ) {
    }

    public function entry(): void
    {
        if (++$this->entries > $this->maxEntries) {
            throw new TooLargeException('Слишком много записей для одного архива.', null, ['limit' => $this->maxEntries]);
        }
    }

    public function bytes(int $size): void
    {
        $this->bytes += max(0, $size);
        if ($this->bytes > $this->maxBytes) {
            throw new TooLargeException('Суммарный размер архива превышает лимит.', null, ['limit' => $this->maxBytes]);
        }
    }
}
