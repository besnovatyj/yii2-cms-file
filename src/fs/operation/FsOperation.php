<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\operation;

/**
 * Операции виртуальной ФС — ровно те, что описаны в контракте bescms-fs v1 (§9).
 *
 * Перечисление используется в трёх местах с одинаковой семантикой: проверка capabilities точки
 * монтирования, контекст политики ({@see \Besnovatyj\File\fs\policy\PolicyContext}) и имена
 * операций API. Зарезервированные операции (thumbnail, write, search, archive…) появятся здесь,
 * когда будут реализованы — раньше добавлять нельзя: `describe` перечисляет только реализованное.
 */
enum FsOperation: string
{
    case Describe = 'describe';
    case List = 'list';
    case Tree = 'tree';
    case Stat = 'stat';
    case Content = 'content';
    case Download = 'download';
    case Preview = 'preview';
    case Thumbnail = 'thumbnail';
    case Search = 'search';
    case Archive = 'archive';
    case Extract = 'extract';
    case Mkdir = 'mkdir';
    case Rename = 'rename';
    case Move = 'move';
    case Copy = 'copy';
    case Delete = 'delete';
    case Upload = 'upload';

    /** Операция меняет состояние хранилища (требует записываемого mount'а). */
    public function isMutating(): bool
    {
        return match ($this) {
            self::Mkdir, self::Rename, self::Move, self::Copy, self::Delete, self::Upload => true,
            default => false,
        };
    }

    /** Операция задаёт/меняет имя узла — к ней применяются правила имён и блок-лист расширений. */
    public function assignsName(): bool
    {
        return match ($this) {
            self::Mkdir, self::Rename, self::Move, self::Copy, self::Upload => true,
            default => false,
        };
    }
}
