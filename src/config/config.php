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

        // Дефолты подключения AWS S3 (переопределяются через модуль настроек "yii2-cms-config",
        // см. config/options.php). Пока key/bucket пусты — точка монтирования AWS не регистрируется.
        's3' => [
            'key' => '',
            'secret' => '',
            'region' => '',
            'bucket' => '',
            'prefix' => '',        // префикс-«корень» внутри бакета (скрыт от фронтенда)
            'endpoint' => '',      // для S3-совместимых; для AWS — пусто
            'usePathStyle' => false,
            'baseUrl' => '',       // публичная база для ссылок на файлы
        ],
    ],
];
