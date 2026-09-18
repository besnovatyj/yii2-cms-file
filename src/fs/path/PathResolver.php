<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\path;

use Besnovatyj\File\fs\exception\InvalidOperationException;
use Besnovatyj\File\fs\exception\MountUnknownException;
use Besnovatyj\File\fs\exception\NotFoundException;
use Besnovatyj\File\fs\mount\MountRegistry;

/**
 * Переводит виртуальный путь в {@see Target} (mount + location) с проверками существования.
 *
 * Общая утилита всех сервисов операций: разбор пути ({@see VirtualPath::parse()}), поиск точки
 * монтирования, типовые проверки «должна быть папка / должен существовать». Ошибки — доменные
 * исключения с кодами контракта.
 */
final class PathResolver
{
    public function __construct(private readonly MountRegistry $mounts)
    {
    }

    public function mounts(): MountRegistry
    {
        return $this->mounts;
    }

    /**
     * Адрес внутри точки монтирования. Виртуальный корень '/' сюда передавать нельзя —
     * у него нет хранилища (операции над корнем обрабатываются отдельно).
     *
     * @throws MountUnknownException
     * @throws InvalidOperationException путь — виртуальный корень.
     */
    public function resolve(VirtualPath $path): Target
    {
        if ($path->mount === null) {
            throw new InvalidOperationException('Операция неприменима к виртуальному корню.', '/');
        }
        return new Target($path, $this->mounts->get($path->mount));
    }

    /**
     * Адрес существующей ПАПКИ (корень mount'а считается существующим всегда).
     *
     * @throws NotFoundException
     */
    public function resolveDirectory(VirtualPath $path): Target
    {
        $target = $this->resolve($path);
        if (!$target->isDirectory()) {
            throw NotFoundException::forPath($path->toString());
        }
        return $target;
    }

    /**
     * Адрес существующего узла любого вида.
     *
     * @throws NotFoundException
     */
    public function resolveExisting(VirtualPath $path): Target
    {
        $target = $this->resolve($path);
        if (!$target->exists()) {
            throw NotFoundException::forPath($path->toString());
        }
        return $target;
    }

    /**
     * Адрес существующего ФАЙЛА.
     *
     * @throws NotFoundException
     */
    public function resolveFile(VirtualPath $path): Target
    {
        $target = $this->resolve($path);
        if (!$target->isFile()) {
            throw NotFoundException::forPath($path->toString());
        }
        return $target;
    }
}
