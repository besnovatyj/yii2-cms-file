<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

namespace Besnovatyj\File;

use common\components\module\CmsModule;
use modules\modman\contract\DeclaresModule;
use modules\modman\contract\ProvidesAdminMenu;
use modules\modman\contract\ProvidesOptions;

class Module extends CmsModule implements
    DeclaresModule, ProvidesAdminMenu,
    ProvidesOptions
{
    public const bool EDITABLE = true;
    public const string VERSION = '1.0.0';
    public const string MODULE_ID = 'File';

    public static function moduleId(): string { return self::MODULE_ID; }
    public static function moduleVersion(): string { return self::VERSION; }
    public static function isEditable(): bool { return self::EDITABLE; }
    public static function adminMenu(): array { return require __DIR__.'/config/adminMenu.php'; }
    public static function moduleConfig(): array { return require __DIR__.'/config/config.php'; }
    public static function options(): array { return require __DIR__.'/config/options.php'; }

}
