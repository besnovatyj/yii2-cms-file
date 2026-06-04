<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

return [
    'id' => 'File',
    'params' => [
        'iconClass' => 'bi bi-folder2',

        'directories' => false, // Если для работы модуля необходимы директории для статики

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
