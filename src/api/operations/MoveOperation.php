<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\api\operations;

/** `move` (контракт §9.7). */
final class MoveOperation extends TransferOperation
{
    public function name(): string
    {
        return 'move';
    }

    protected function isMove(): bool
    {
        return true;
    }
}
