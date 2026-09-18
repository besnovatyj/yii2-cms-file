<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\mount;

use Besnovatyj\File\fs\path\VirtualPath;
use InvalidArgumentException;

/**
 * Декларативное описание точки монтирования из конфигурации (`params.fs.mounts[]`).
 *
 * Чистые данные без побочных эффектов: адаптер по нему собирает {@see MountFactory} через
 * {@see adapter\AdapterFactoryInterface} с ключом {@see $adapter}. Пример:
 *
 * ```php
 * ['id' => 'static', 'adapter' => 'local', 'label' => 'Файлы сайта', 'icon' => 'drive',
 *  'options' => ['root' => '@static'], 'baseUrl' => '@staticHostName']
 * ['id' => 'zip', 'adapter' => 'zip', 'options' => ['archive' => '@static/zip/test.zip']]
 * ['id' => 'aws', 'adapter' => 's3', 'options' => ['key' => …, 'bucket' => …], 'baseUrl' => 'https://cdn…']
 * ```
 */
final class MountDefinition
{
    /**
     * @param string $id Идентификатор (сегмент виртуального пути), см. {@see VirtualPath::MOUNT_ID_PATTERN}.
     * @param string $adapter Ключ фабрики адаптера ('local', 'zip', 's3', …).
     * @param string $label Подпись для UI.
     * @param string|null $icon Подсказка иконки для UI ('drive', 'archive', 'cloud', …).
     * @param array<string, mixed> $options Параметры адаптера (специфичны для фабрики).
     * @param string $baseUrl Публичная база URL файлов; '' — публичной отдачи нет.
     * @param bool $readOnly Административный запрет мутаций (независимо от capabilities).
     */
    public function __construct(
        public readonly string $id,
        public readonly string $adapter,
        public readonly string $label = '',
        public readonly ?string $icon = null,
        public readonly array $options = [],
        public readonly string $baseUrl = '',
        public readonly bool $readOnly = false,
    ) {
        if (preg_match(VirtualPath::MOUNT_ID_PATTERN, $id) !== 1) {
            throw new InvalidArgumentException("Некорректный id точки монтирования: '{$id}'.");
        }
        if ($adapter === '') {
            throw new InvalidArgumentException("Не указан адаптер точки монтирования '{$id}'.");
        }
    }

    /**
     * @param array<string, mixed> $def
     */
    public static function fromArray(array $def): self
    {
        return new self(
            id: (string)($def['id'] ?? ''),
            adapter: (string)($def['adapter'] ?? ''),
            label: (string)($def['label'] ?? ($def['id'] ?? '')),
            icon: isset($def['icon']) ? (string)$def['icon'] : null,
            options: (array)($def['options'] ?? []),
            baseUrl: (string)($def['baseUrl'] ?? ''),
            readOnly: (bool)($def['readOnly'] ?? false),
        );
    }
}
