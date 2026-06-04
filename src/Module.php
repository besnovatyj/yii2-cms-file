<?php


/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

namespace Besnovatyj\File;

use common\components\module\BaseModule;

class Module extends BaseModule
{
    public const true EDITABLE = true;

    public static function getAdminMenu(): array
    {
        return require __DIR__ . '/config/adminMenu.php';
    }

    public static function getConfig(): array
    {
        return require __DIR__ . '/config/config.php';
    }

    public static function getOptions(): array
    {
        return require __DIR__ . '/config/options.php';
    }

    public static function getDependencies(): array
    {
        return require __DIR__ . '/config/dependencies.php';
    }

    /**
     * Регистрация DI слоя хранилищ (MountRegistry, StorageManager).
     * Вызывается автоматически из {@see \common\components\module\BaseModule::init()}
     * по наличию этого метода — поэтому контроллёры получают StorageManager через конструктор.
     */
    public static function setContainerConfig(): void
    {
        (require __DIR__ . '/config/container.php')(\Yii::$container);
    }

}
