<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\api\operations;

/** `copy` (контракт §9.7). */
final class CopyOperation extends TransferOperation
{
    public function name(): string
    {
        return 'copy';
    }

    protected function isMove(): bool
    {
        return false;
    }
}
