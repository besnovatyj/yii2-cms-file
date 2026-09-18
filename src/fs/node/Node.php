<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\node;

use Besnovatyj\File\fs\path\VirtualPath;

/**
 * Узел виртуальной ФС — файл, папка или точка монтирования (ADR-3: единая модель).
 *
 * Неизменяемый объект-значение. Поля один в один соответствуют `Node` контракта (§5); сериализацию
 * в JSON выполняет API-слой ({@see \Besnovatyj\File\api\NodeSerializer}) — домен про JSON не знает.
 *
 * `meta` — слот расширения: сюда попадают данные, которых нет у всех узлов (размеры изображения,
 * `hasChildren` для дерева, подпись/иконка mount'а). Ключи документируются там, где заполняются.
 */
final class Node
{
    /**
     * @param VirtualPath $path Адрес узла (идентификатор).
     * @param NodeKind $kind Вид узла.
     * @param int|null $size Размер в байтах; null — неизвестен/неприменим (папки).
     * @param int|null $mtime Unix-время последнего изменения; null — неизвестно.
     * @param string|null $mime MIME-тип; в листинге — «дешёвый» по расширению, точный — в stat.
     * @param string|null $url Публичный URL файла; null — публичной отдачи нет.
     * @param string|null $visibility 'public' | 'private' | null.
     * @param array<string, bool>|null $perms Переопределение прав узла (read/write/delete/rename); null — как у mount.
     * @param array<string, mixed> $meta Расширяемые метаданные.
     */
    public function __construct(
        public readonly VirtualPath $path,
        public readonly NodeKind $kind,
        public readonly ?int $size = null,
        public readonly ?int $mtime = null,
        public readonly ?string $mime = null,
        public readonly ?string $url = null,
        public readonly ?string $visibility = null,
        public readonly ?array $perms = null,
        public readonly array $meta = [],
    ) {
    }

    /** Имя узла (последний сегмент пути). */
    public function name(): string
    {
        return $this->path->name();
    }

    /** Расширение без точки в lowercase; null — нет (у папок всегда null). */
    public function extension(): ?string
    {
        if ($this->kind !== NodeKind::File) {
            return null;
        }
        $name = $this->name();
        $pos = strrpos($name, '.');
        if ($pos === false || $pos === 0 || $pos === strlen($name) - 1) {
            return null;
        }
        return strtolower(substr($name, $pos + 1));
    }

    public function isFile(): bool
    {
        return $this->kind === NodeKind::File;
    }

    public function isContainer(): bool
    {
        return $this->kind->isContainer();
    }

    /** Копия с дополненными метаданными (существующие ключи перекрываются). */
    public function withMeta(array $meta): self
    {
        return new self(
            $this->path,
            $this->kind,
            $this->size,
            $this->mtime,
            $this->mime,
            $this->url,
            $this->visibility,
            $this->perms,
            array_merge($this->meta, $meta),
        );
    }

    /** Копия с уточнённым MIME (после stat/finfo). */
    public function withMime(?string $mime): self
    {
        return new self(
            $this->path,
            $this->kind,
            $this->size,
            $this->mtime,
            $mime,
            $this->url,
            $this->visibility,
            $this->perms,
            $this->meta,
        );
    }
}
