<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

use Besnovatyj\File\storage\MountRegistry;
use Besnovatyj\File\storage\StorageManager;
use Besnovatyj\File\storage\StorageMountFactory;
use Besnovatyj\File\upload\rules\ExtensionBlocklistRule;
use Besnovatyj\File\upload\rules\MaxFileSizeRule;
use Besnovatyj\File\upload\UploadPolicy;
use yii\di\Container;

/**
 * DI-конфигурация слоя хранилищ файлового модуля.
 *
 * Точки монтирования собираются через {@see StorageMountFactory} (League\Flysystem-адаптеры).
 * Сегодня регистрируются:
 *   - 'static' — локальный каталог @static (всегда);
 *   - 'zip'    — тестовый ZIP-архив @static/zip/test.zip (если файл существует);
 *   - 'aws'    — AWS S3 / S3-совместимое (если в настройках заданы key + bucket).
 *
 * Параметры AWS берутся из настроек модуля (модуль "yii2-cms-config" → params.s3, см. config/options.php).
 * Добавление нового корня = ещё одно описание ниже; контроллёры, сервис и фронтенд не меняются.
 */
return static function (Container $container): void {

    $container->setSingleton(StorageMountFactory::class, StorageMountFactory::class);

    $container->setSingleton(MountRegistry::class, static function (Container $c): MountRegistry {
        /** @var StorageMountFactory $factory */
        $factory = $c->get(StorageMountFactory::class);

        $module = \Yii::$app->getModule('File');
        $params = $module?->params ?? [];

        $mounts = [];

        // 1) Локальный каталог @static — основная точка монтирования (всегда).
        $mounts[] = $factory->make([
            'id' => 'static',
            'adapter' => 'local',
            'root' => '@static',
            'baseUrl' => (string)\Yii::getAlias('@staticHostName'),
            'label' => 'Локальные файлы (static)',
        ]);

        // 2) ZIP-архив — регистрируем только если файл существует (иначе ломали бы UI пустым mount'ом).
        $zipPath = (string)\Yii::getAlias('@static/zip/test.zip');
        if (is_file($zipPath)) {
            $mounts[] = $factory->make([
                'id' => 'zip',
                'adapter' => 'zip',
                'archive' => $zipPath,
                'baseUrl' => '', // прямая отдача файлов из архива — отдельный download-эндпоинт (TODO)
                'label' => 'Тестовый ZIP-архив',
            ]);
        }

        // 3) AWS S3 — регистрируем только при заданных credentials (key + bucket), иначе mount был бы битым.
        $s3 = (array)($params['s3'] ?? []);
        if (!empty($s3['key']) && !empty($s3['bucket'])) {
            $mounts[] = $factory->make([
                'id' => 'aws',
                'adapter' => 's3',
                's3' => $s3,
                'baseUrl' => (string)($s3['baseUrl'] ?? ''),
                'label' => 'AWS S3',
            ]);
        }

        return new MountRegistry($mounts, 'static');
    });

    // Серверная политика загрузки — конвейер правил. Добавление новой валидации (MIME/контент
    // через finfo, размеры изображений, санитизация SVG, per-mount ограничения...) — ещё один
    // объект-правило в этом списке; сервис, контроллёры и контракт API не меняются.
    $container->setSingleton(UploadPolicy::class, static function (): UploadPolicy {
        $module = \Yii::$app->getModule('File');
        $upload = (array)($module?->params['upload'] ?? []);

        return new UploadPolicy([
            // Блок исполняемых расширений (все dot-сегменты имени: shell.php.jpg тоже отклоняется).
            new ExtensionBlocklistRule(
                (array)($upload['blockedExtensions'] ?? ExtensionBlocklistRule::DEFAULT_BLOCKED)
            ),
            // Лимит размера: min(настроенный в params, ini-лимиты PHP); null = только ini.
            new MaxFileSizeRule(
                isset($upload['maxFileSize']) && (int)$upload['maxFileSize'] > 0
                    ? (int)$upload['maxFileSize']
                    : null
            ),
        ]);
    });

    $container->setSingleton(StorageManager::class, static function (Container $c): StorageManager {
        return new StorageManager($c->get(MountRegistry::class), $c->get(UploadPolicy::class));
    });
};
