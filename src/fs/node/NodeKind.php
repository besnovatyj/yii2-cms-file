<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\node;

/**
 * Вид узла виртуальной ФС (контракт §5). Один тип {@see Node} для всех видов — ADR-3.
 *
 *  - File  — файл;
 *  - Dir   — папка (в том числе виртуальный корень '/');
 *  - Mount — точка монтирования, показывается как «диск» в корне.
 */
enum NodeKind: string
{
    case File = 'file';
    case Dir = 'dir';
    case Mount = 'mount';

    /** Папка в широком смысле: в неё можно «зайти» и в неё можно класть узлы. */
    public function isContainer(): bool
    {
        return $this !== self::File;
    }
}
