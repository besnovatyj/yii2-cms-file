<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\operation\report;

/** Исход одного элемента пакетной операции (контракт §7). */
enum ItemStatus: string
{
    case Ok = 'ok';
    case Skipped = 'skipped';
    case Failed = 'failed';
}
