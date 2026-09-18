<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\operation;

use Besnovatyj\File\fs\exception\StorageFailureException;
use RuntimeException;

/**
 * Загружаемый файл в терминах домена — без зависимости от `yii\web\UploadedFile`.
 *
 * Yii-контроллёр строит его из `UploadedFile`, тесты — из любого временного файла. Имя и MIME от
 * клиента — недоверенные данные: имя проходит {@see \Besnovatyj\File\fs\naming\NameSanitizer},
 * MIME используется только как подсказка (контентная проверка — по байтам, см. ContentSniffRule).
 */
final class UploadSource
{
    /**
     * @param string $tmpPath Путь к временному файлу на диске сервера.
     * @param int $size Размер в байтах.
     * @param string $clientName Имя файла, как его прислал клиент.
     * @param string|null $clientMime MIME, заявленный клиентом (недостоверен).
     */
    public function __construct(
        public readonly string $tmpPath,
        public readonly int $size,
        public readonly string $clientName,
        public readonly ?string $clientMime = null,
    ) {
    }

    /**
     * Открывает поток чтения временного файла. Вызывающий обязан закрыть его.
     *
     * @return resource
     */
    public function openStream()
    {
        $stream = @fopen($this->tmpPath, 'rb');
        if ($stream === false) {
            throw StorageFailureException::wrap(
                new RuntimeException('Не удалось открыть временный файл загрузки.'),
                null,
                'upload',
            );
        }
        return $stream;
    }
}
