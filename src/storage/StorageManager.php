<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\storage;

use Besnovatyj\File\services\FileManagerService;

/**
 * Фасад слоя хранилищ — единственная точка входа для контроллёров файлового менеджера.
 *
 * Ответственность:
 *  - отдать сервис {@see FileManagerService}, привязанный к конкретной точке монтирования;
 *  - синтезировать «виртуальный корень» — перечень всех точек монтирования как папок верхнего
 *    уровня, чтобы фронтенд видел единую файловую систему («как будто локальную»).
 *
 * Контроллёр разбирает входящий путь через {@see VirtualPath}, а сюда обращается за сервисом
 * нужной точки монтирования или за виртуальным корнем.
 */
final class StorageManager
{
    public function __construct(private readonly MountRegistry $registry)
    {
    }

    public function registry(): MountRegistry
    {
        return $this->registry;
    }

    /**
     * Сервис файловых операций, привязанный к конкретной точке монтирования.
     * @throws exceptions\UnknownMountException неизвестный mountId.
     */
    public function serviceFor(string $mountId): FileManagerService
    {
        return new FileManagerService($this->registry->get($mountId));
    }

    /** Сервис точки монтирования по умолчанию (для mount-независимых операций: config, sua). */
    public function defaultService(): FileManagerService
    {
        return new FileManagerService($this->registry->getDefault());
    }

    /**
     * Синтетический «виртуальный корень»: каждая точка монтирования — папка верхнего уровня.
     *
     * Имя такой папки = id точки монтирования, поэтому её ключ на фронтенде получается '/{id}',
     * и навигация по ней ведёт прямиком в корень соответствующей точки монтирования.
     * Дочерние элементы не считаем (countChild* = 0): они подгрузятся лениво при заходе внутрь.
     *
     * @return array Структура DirDto (как у {@see FileManagerService::getFolderDto()}).
     */
    public function virtualRootDto(): array
    {
        // Метаданные синтетические: у виртуального корня и адаптеров (S3/ZIP) нет POSIX-прав/времени.
        // Значения положительные — этого достаточно фронтенду (FolderMeta).
        $now = time();
        $meta = ['permissions' => 0755, 'mTime' => $now, 'aTime' => $now, 'size' => 0];

        $folders = [];
        foreach ($this->registry->all() as $mount) {
            $folders[] = [
                'name' => $mount->id,   // имя = id ⇒ ключ '/{id}' на фронтенде
                'path' => '',           // родитель — виртуальный корень
                'type' => 'dir',
                'countChildDirs' => 0,
                'countChildFiles' => 0,
                'meta' => $meta,
                'folders' => [],
                'files' => [],
            ];
        }

        return [
            'name' => '/',  // отображаемое имя виртуального корня
            'path' => '',
            'type' => 'dir',
            'countChildDirs' => count($folders),
            'countChildFiles' => 0,
            'meta' => $meta,
            'folders' => $folders,
            'files' => [],
        ];
    }
}
