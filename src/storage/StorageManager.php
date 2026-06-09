<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\storage;

use Besnovatyj\File\services\FileManagerService;
use Besnovatyj\File\upload\UploadPolicy;

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
    public function __construct(
        private readonly MountRegistry $registry,
        private readonly UploadPolicy $uploadPolicy,
    ) {
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
        return new FileManagerService($this->registry->get($mountId), $this->uploadPolicy);
    }

    /** Сервис точки монтирования по умолчанию (для mount-независимых операций: sua). */
    public function defaultService(): FileManagerService
    {
        return new FileManagerService($this->registry->getDefault(), $this->uploadPolicy);
    }

    /**
     * Конфигурация/возможности бэкенда — контракт GetConfigResponse (ports.ts фронтенда):
     * contractVersion + global (дефолты) + переопределения по mountId.
     * Это UX-подсказки для фронтенда (задизейблить кнопку, проверить размер до отправки);
     * enforcement ВСЕГДА остаётся на сервере.
     */
    public function configDto(): array
    {
        $mounts = [];
        foreach ($this->registry->all() as $mount) {
            $caps = [];
            // Пустой baseUrl = у файлов mount нет публичных URL (например, ZIP без download-эндпоинта).
            if ($mount->baseUrl === '') {
                $caps['publicUrls'] = false;
            }
            if ($caps !== []) {
                $mounts[$mount->id] = $caps;
            }
        }

        $dto = [
            'contractVersion' => 1,
            'global' => [
                // Ограничения загрузки декларируют сами правила UploadPolicy (capabilities) —
                // сегодня это maxFileSize (min из настроек и ini PHP) и blockedExtensions.
                // Контентные ограничения осознанно не вводим (битые MIME у легитимных
                // изображений) — null = ограничений нет. Появятся как правила UploadPolicy.
                // TODO in js: `<input type="file" id="fileInput" accept="image/*" />`
                // TODO in js: `if (file && file.type.startsWith('image/')) {}`
                'upload' => array_merge(
                    [
                        'maxFileSize' => null,
                        'allowedMimeTypes' => null,
                        'allowedExtensions' => null,
                    ],
                    $this->uploadPolicy->capabilities()
                ),
            ],
        ];
        if ($mounts !== []) {
            $dto['mounts'] = $mounts;
        }

        return $dto;
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
