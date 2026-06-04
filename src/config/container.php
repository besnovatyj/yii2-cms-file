<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

use Besnovatyj\File\storage\MountRegistry;
use Besnovatyj\File\storage\StorageManager;
use Besnovatyj\File\storage\StorageMount;
use yii\di\Container;

/**
 * DI-конфигурация слоя хранилищ файлового модуля.
 *
 * Здесь объявляются точки монтирования (mounts). Сегодня — одна локальная (поверх каталога @static).
 * Добавление нового корня (ещё один локальный каталог, AWS S3, FTP-сервер) сводится к регистрации
 * ещё одного {@see StorageMount} — контроллёры, сервис и фронтенд при этом не меняются.
 *
 * Набор точек монтирования можно переопределить из глобального конфига приложения:
 *   Yii::$app->params['fileManager']['mounts'] = [
 *       ['id' => 'static', 'realRoot' => '@static', 'baseUrl' => '@staticHostName', 'label' => '...'],
 *       // ['id' => 'aws', 'realRoot' => '@s3root', 'baseUrl' => 'https://cdn...', 'label' => 'AWS'],
 *   ];
 *   Yii::$app->params['fileManager']['defaultMount'] = 'static';
 */
return static function (Container $container): void {

    $container->setSingleton(MountRegistry::class, static function (): MountRegistry {
        $configured = \Yii::$app->params['fileManager']['mounts'] ?? null;

        if (is_array($configured) && $configured !== []) {
            $mounts = array_map(
                static fn(array $m): StorageMount => new StorageMount(
                    id: (string)$m['id'],
                    realRoot: (string)\Yii::getAlias($m['realRoot']),
                    baseUrl: (string)\Yii::getAlias($m['baseUrl']),
                    label: (string)($m['label'] ?? ''),
                ),
                $configured,
            );

            return new MountRegistry($mounts, \Yii::$app->params['fileManager']['defaultMount'] ?? null);
        }

        // Дефолт: единственная локальная точка монтирования поверх каталога @static.
        // realRoot скрыт от клиента; baseUrl — публичный статик-домен для URL файлов.
        return new MountRegistry([
            new StorageMount(
                id: 'static',
                realRoot: (string)\Yii::getAlias('@static'),
                baseUrl: (string)\Yii::getAlias('@staticHostName'),
                label: 'Локальные файлы (static)',
            ),
        ], 'static');
    });

    $container->setSingleton(StorageManager::class, static function (Container $c): StorageManager {
        return new StorageManager($c->get(MountRegistry::class));
    });
};
