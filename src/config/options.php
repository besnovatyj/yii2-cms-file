<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

// Опции файлового модуля для модуля настроек "yii2-cms-config".
// Значения применяются в Yii::$app->getModule('File')->params['s3'][...] (см. ConfigApplier),
// откуда их читает src/config/container.php при сборке точки монтирования AWS S3.
// Дефолты заданы в src/config/config.php (params.s3) — иначе опции «не за что зацепиться».
return [
    's3_key' => [
        'path' => 'modules.File.params.s3.key',
        'label' => 'AWS S3: Access Key ID',
        'description' => 'Ключ доступа AWS (или S3-совместимого хранилища). Пусто — точка монтирования AWS не подключается.',
        'group' => 'AWS S3',
        'category' => 'File',
        'rules' => [['string']],
        'inputOptions' => ['type' => 'input'],
    ],
    's3_secret' => [
        'path' => 'modules.File.params.s3.secret',
        'label' => 'AWS S3: Secret Access Key',
        'description' => 'Секретный ключ AWS. Хранится в конфиге настроек — не коммитить в репозиторий.',
        'group' => 'AWS S3',
        'category' => 'File',
        'rules' => [['string']],
        'inputOptions' => ['type' => 'input'],
    ],
    's3_region' => [
        'path' => 'modules.File.params.s3.region',
        'label' => 'AWS S3: Region',
        'description' => 'Регион, напр. eu-central-1 (для S3-совместимых — любое непустое значение).',
        'group' => 'AWS S3',
        'category' => 'File',
        'rules' => [['string']],
        'inputOptions' => ['type' => 'input'],
    ],
    's3_bucket' => [
        'path' => 'modules.File.params.s3.bucket',
        'label' => 'AWS S3: Bucket',
        'description' => 'Имя бакета. Пусто — точка монтирования AWS не подключается.',
        'group' => 'AWS S3',
        'category' => 'File',
        'rules' => [['string']],
        'inputOptions' => ['type' => 'input'],
    ],
    's3_prefix' => [
        'path' => 'modules.File.params.s3.prefix',
        'label' => 'AWS S3: Prefix (корень)',
        'description' => 'Префикс-«корень» внутри бакета (напр. uploads/). Скрыт от фронтенда так же, как realRoot.',
        'group' => 'AWS S3',
        'category' => 'File',
        'rules' => [['string']],
        'inputOptions' => ['type' => 'input'],
    ],
    's3_endpoint' => [
        'path' => 'modules.File.params.s3.endpoint',
        'label' => 'AWS S3: Endpoint (опционально)',
        'description' => 'Кастомный endpoint для S3-совместимых хранилищ (MinIO, Yandex Object Storage). Для настоящего AWS оставить пустым.',
        'group' => 'AWS S3',
        'category' => 'File',
        'rules' => [['string']],
        'inputOptions' => ['type' => 'input'],
    ],
    's3_path_style' => [
        'path' => 'modules.File.params.s3.usePathStyle',
        'label' => 'AWS S3: Path-style endpoint',
        'description' => 'Path-style адресация (нужна для MinIO и части S3-совместимых). 1 — включить, 0 — выключить.',
        'group' => 'AWS S3',
        'category' => 'File',
        'rules' => [['boolean']],
        'inputOptions' => ['type' => 'input'],
    ],
    's3_base_url' => [
        'path' => 'modules.File.params.s3.baseUrl',
        'label' => 'AWS S3: Public Base URL',
        'description' => 'Публичная база для ссылок на файлы (CDN/бакет), соответствующая корню+префиксу. Напр. https://cdn.example.com/uploads',
        'group' => 'AWS S3',
        'category' => 'File',
        'rules' => [['string']],
        'inputOptions' => ['type' => 'input'],
    ],
];
