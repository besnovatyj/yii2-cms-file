<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\operation\report;

use Besnovatyj\File\fs\exception\FsException;
use Besnovatyj\File\fs\node\Node;

/**
 * Результат обработки одного элемента пакета: путь-источник, фактическая цель, статус, узел
 * (для успеха) или доменная ошибка (для неуспеха). Ошибка хранится как исключение — API-слой
 * сам сериализует её в `{code, message, details}` и залогирует внутренние причины.
 */
final class ItemResult
{
    private function __construct(
        public readonly string $source,
        public readonly ItemStatus $status,
        public readonly ?string $target = null,
        public readonly ?Node $node = null,
        public readonly ?FsException $error = null,
    ) {
    }

    public static function ok(string $source, Node $node): self
    {
        return new self($source, ItemStatus::Ok, $node->path->toString(), $node);
    }

    /** Успех без узла (после удаления узла больше нет). */
    public static function done(string $source): self
    {
        return new self($source, ItemStatus::Ok);
    }

    public static function skipped(string $source, ?string $target = null): self
    {
        return new self($source, ItemStatus::Skipped, $target);
    }

    public static function failed(string $source, FsException $error, ?string $target = null): self
    {
        return new self($source, ItemStatus::Failed, $target, null, $error);
    }
}
