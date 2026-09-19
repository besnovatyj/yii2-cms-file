<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

return [
    'id' => 'File',
    'params' => [
        'iconClass' => 'bi bi-folder2',

        'directories' => false, // Если для работы модуля необходимы директории для статики

        // Серверная политика загрузки (UploadPolicy, см. config/container.php).
        'upload' => [
            // Лимит размера в БАЙТАХ; null/0 — только ini-лимиты PHP (upload_max_filesize/post_max_size).
            'maxFileSize' => null,
            // Блок-лист расширений; null — дефолтный список ExtensionBlocklistRule::DEFAULT_BLOCKED,
            // [] — блокировки отключены (НЕ рекомендуется), свой массив — полная замена списка.
            'blockedExtensions' => null,
        ],

        // ------------------------------------------------------------------------------------
        // Файловый менеджер v2 (контракт bescms-fs, src/fs + src/api, контроллёр ApiController).
        // Все ключи необязательны — отсутствующие берут дефолты классов (NameRules, FsLimits,
        // правила политики). Секции 'upload' и 's3' ниже используются ОБЕИМИ линиями (v1 и v2).
        // ------------------------------------------------------------------------------------
        'fs' => [
            // Точки монтирования. null — автосборка как в v1: 'static' (@static) всегда,
            // 'zip' — если существует @static/zip/test.zip, 'aws' — если заданы s3.key + s3.bucket.
            // Явный список: массив описаний MountDefinition::fromArray(), например:
            //   ['id' => 'static', 'adapter' => 'local', 'label' => 'Файлы сайта', 'icon' => 'drive',
            //    'options' => ['root' => '@static'], 'baseUrl' => '@staticHostName'],
            //   ['id' => 'docs', 'adapter' => 'zip', 'options' => ['archive' => '@static/docs.zip'], 'readOnly' => true],
            'mounts' => null,
            'defaultMount' => 'static',

            // Правила имён (fs\naming\NameRules::fromArray): maxLength, forbiddenChars,
            // forbiddenNames, allowLeadingDot, allowTrailingDotOrSpace, unicodeNormalization,
            // transliterate (кириллица → латиница при ЗАГРУЗКЕ; mkdir/rename не трогает).
            'naming' => [
                'transliterate' => false,
            ],

            // Политика операций (fs\policy\*):
            'policy' => [
                // Блок-лист расширений: null — дефолт ExtensionBlocklistRule::DEFAULT_BLOCKED,
                // [] — правило отключено (НЕ рекомендуется), массив — полная замена списка.
                'blockedExtensions' => null,
                // Белый список расширений для upload/rename: null — без ограничений.
                'allowedExtensions' => null,
                // Лимит размера загрузки в байтах: null — только ini-лимиты PHP.
                'maxFileSize' => null,
                // Проверка содержимого по magic-bytes: false — выключена (ADR-10), true — только
                // отклонение исполняемого содержимого, 'strict' — плюс сверка типа с расширением.
                'contentSniff' => false,
            ],

            // Количественные лимиты (fs\FsLimits::fromArray): listPageSize, maxBatchItems,
            // contentMaxBytes, maxTransferEntries, imageProbeMaxBytes, previewMaxBytes,
            // searchMaxResults (500), searchMaxEntries (50000 — бюджет обхода одного поиска),
            // archiveMaxBytes (2 ГиБ несжатых данных на сборку/распаковку ZIP).
            'limits' => [],

            // Серверные миниатюры для режимов «плитка»/«значки» (fs\thumbnail\ThumbnailConfig).
            // Нужен ext-imagick или ext-gd (imagine/imagine); без них операция не объявляется.
            // Кэш — файловый, чистка: `php yii File/thumbnail/purge` (раз в сутки).
            'thumbnails' => [
                'enabled' => true,
                'dir' => '@runtime/fs-thumbs',
                'sizes' => [64, 128, 256, 512], // допустимые размеры (запрос округляется вверх)
                'quality' => 82,
                'maxPixels' => 40000000,        // защита памяти при декодировании
                'ttl' => 7 * 86400,
            ],

            // Докачиваемая загрузка по протоколу tus (fs\tus\TusConfig). Требования к окружению:
            // nginx `client_max_body_size` и PHP `post_max_size` не меньше chunkSize; cron —
            // `php yii File/tus/purge` для удаления просроченных загрузок.
            'tus' => [
                'enabled' => true,
                'dir' => '@runtime/fs-tus',      // временные файлы кусков (не в @static!)
                'chunkSize' => 5 * 1024 * 1024,  // байт; клиент шлёт куски такого размера
                'threshold' => 8 * 1024 * 1024,  // файлы меньше — обычным multipart
                'ttl' => 86400,                  // секунд жизни незавершённой загрузки
            ],
        ],

        // Дефолты подключения AWS S3 (переопределяются через модуль настроек "yii2-cms-config",
        // см. config/options.php). Пока key/bucket пусты — точка монтирования AWS не регистрируется.
        's3' => [
            'key' => '',
            'secret' => '',
            'region' => '',
            'bucket' => '',
            'prefix' => '',        // префикс-«корень» внутри бакета (скрыт от фронтенда)
            'endpoint' => '',      // для S3-совместимых; для AWS — пусто
            'usePathStyle' => '',  // '' — не задано: адресация по умолчанию (virtual-hosted, как у AWS)
            'baseUrl' => '',       // публичная база для ссылок на файлы
        ],
    ],
];
