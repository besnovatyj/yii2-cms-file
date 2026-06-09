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

    /** Сервис точки монтирования по умолчанию (для mount-независимых операций: sua). */
    public function defaultService(): FileManagerService
    {
        return new FileManagerService($this->registry->getDefault());
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
                'upload' => [
                    // Лимит в БАЙТАХ из ini-настроек PHP; null = лимита нет (контракт BackendCapabilities).
                    'maxFileSize' => $this->uploadMaxBytes(),
                    // Контентные ограничения осознанно не вводим (битые MIME у легитимных
                    // изображений) — null = ограничений нет. К будущей UploadPolicy.
                    // TODO in js: `<input type="file" id="fileInput" accept="image/*" />`
                    // TODO in js: `if (file && file.type.startsWith('image/')) {}`
                    'allowedMimeTypes' => null,
                    'allowedExtensions' => null,
                ],
            ],
        ];
        if ($mounts !== []) {
            $dto['mounts'] = $mounts;
        }

        return $dto;
    }

    /**
     * Фактический лимит размера загрузки: минимум из upload_max_filesize и post_max_size.
     * null — лимита нет (0/пусто в ini).
     */
    private function uploadMaxBytes(): ?int
    {
        $limits = array_filter([
            self::iniToBytes((string)ini_get('upload_max_filesize')),
            self::iniToBytes((string)ini_get('post_max_size')),
        ]);

        return $limits === [] ? null : min($limits);
    }

    /**
     * Перевод ini-нотации размера ('2M', '512K', '1G', '100') в байты.
     * null — значение пустое или 0 (в семантике ini «без лимита»).
     */
    private static function iniToBytes(string $value): ?int
    {
        $value = trim($value);
        if ($value === '' || $value === '0' || $value === '-1') {
            return null;
        }

        $bytes = (float)$value;
        $bytes *= match (strtolower(substr($value, -1))) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        };

        return $bytes > 0 ? (int)$bytes : null;
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
