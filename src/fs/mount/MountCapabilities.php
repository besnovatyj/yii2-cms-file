<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\mount;

use Besnovatyj\File\fs\operation\FsOperation;

/**
 * Что хранилище умеет ФИЗИЧЕСКИ (контракт §6, `MountCapabilities`).
 *
 * Не путать с правами пользователя (их проверяет RBAC по маршрутам) и с флагом `readOnly` точки
 * монтирования (административное решение). Capabilities задаёт фабрика адаптера — только она знает,
 * что S3 эмулирует папки, а ZIP не умеет нативно перемещать директории. Фронтенд получает их из
 * `describe` и гасит недоступные действия, бэкенд перепроверяет в {@see Mount::supports()}.
 *
 * Поле {@see $nativeDirectoryMove} в контракт не попадает — это внутренняя подсказка для
 * {@see \Besnovatyj\File\fs\operation\Transfer}: переносить папку одним `move()` или рекурсивно.
 */
final class MountCapabilities
{
    public function __construct(
        public readonly bool $list = true,
        public readonly bool $stat = true,
        public readonly bool $content = true,
        public readonly bool $download = true,
        public readonly bool $mkdir = true,
        public readonly bool $rename = true,
        public readonly bool $move = true,
        public readonly bool $copy = true,
        public readonly bool $delete = true,
        public readonly bool $upload = true,
        public readonly bool $publicUrl = false,
        public readonly bool $visibility = false,
        public readonly bool $thumbnail = false,
        public readonly bool $search = false,
        /** 'native' — настоящие папки; 'emulated' — префиксы (S3): пустая папка может «исчезнуть». */
        public readonly string $directories = 'native',
        /** Внутреннее: адаптер умеет переместить папку одним вызовом move(). */
        public readonly bool $nativeDirectoryMove = false,
    ) {
    }

    /** Все операции выключены — базовый профиль «только чтение списка», от которого удобно отталкиваться. */
    public static function readOnlyProfile(): self
    {
        return new self(
            mkdir: false, rename: false, move: false, copy: false, delete: false, upload: false,
        );
    }

    /**
     * Копия с изменёнными полями (именованные аргументы: `->with(publicUrl: true)`).
     */
    public function with(
        ?bool $list = null,
        ?bool $stat = null,
        ?bool $content = null,
        ?bool $download = null,
        ?bool $mkdir = null,
        ?bool $rename = null,
        ?bool $move = null,
        ?bool $copy = null,
        ?bool $delete = null,
        ?bool $upload = null,
        ?bool $publicUrl = null,
        ?bool $visibility = null,
        ?bool $thumbnail = null,
        ?bool $search = null,
        ?string $directories = null,
        ?bool $nativeDirectoryMove = null,
    ): self {
        return new self(
            $list ?? $this->list,
            $stat ?? $this->stat,
            $content ?? $this->content,
            $download ?? $this->download,
            $mkdir ?? $this->mkdir,
            $rename ?? $this->rename,
            $move ?? $this->move,
            $copy ?? $this->copy,
            $delete ?? $this->delete,
            $upload ?? $this->upload,
            $publicUrl ?? $this->publicUrl,
            $visibility ?? $this->visibility,
            $thumbnail ?? $this->thumbnail,
            $search ?? $this->search,
            $directories ?? $this->directories,
            $nativeDirectoryMove ?? $this->nativeDirectoryMove,
        );
    }

    /** Поддерживается ли операция контракта этим профилем. */
    public function supports(FsOperation $operation): bool
    {
        return match ($operation) {
            FsOperation::List, FsOperation::Tree => $this->list,
            FsOperation::Stat => $this->stat,
            FsOperation::Content => $this->content,
            FsOperation::Download, FsOperation::Preview => $this->download,
            FsOperation::Thumbnail => $this->thumbnail && $this->download,
            FsOperation::Search => $this->search && $this->list,
            FsOperation::Mkdir => $this->mkdir,
            FsOperation::Rename => $this->rename,
            FsOperation::Move => $this->move,
            FsOperation::Copy => $this->copy,
            FsOperation::Delete => $this->delete,
            FsOperation::Upload => $this->upload,
            FsOperation::Describe => true,
        };
    }

    /** Представление для `describe.mounts[].capabilities` (контракт §6). */
    public function toArray(): array
    {
        return [
            'list' => $this->list,
            'stat' => $this->stat,
            'content' => $this->content,
            'download' => $this->download,
            'mkdir' => $this->mkdir,
            'rename' => $this->rename,
            'move' => $this->move,
            'copy' => $this->copy,
            'delete' => $this->delete,
            'upload' => $this->upload,
            'publicUrl' => $this->publicUrl,
            'visibility' => $this->visibility,
            'thumbnail' => $this->thumbnail,
            'search' => $this->search,
            'directories' => $this->directories,
        ];
    }
}
