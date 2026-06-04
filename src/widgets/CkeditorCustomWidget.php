<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\widgets;

use Besnovatyj\Ckeditor5\CkeditorCustomWidget as BaseCkeditorCustomWidget;
use Besnovatyj\CkeditorCodeMirror\CodeMirrorCkeditorAsset;
use Besnovatyj\CkeditorFileManager\FileManagerCkeditorAsset;

/**
 * Редактор CKEditor 5 файлового модуля — «батарейки в комплекте».
 *
 * Сам редактор и виджет переехали в пакет besnovatyj/yii2-cms-ckeditor5; плагины — в отдельные
 * пакеты. Этот класс сохраняет прежний namespace для обратной совместимости (его используют формы
 * множества модулей) и просто преднастраивает набор плагинов: файловый менеджер + CodeMirror.
 *
 * @see BaseCkeditorCustomWidget — вся логика виджета.
 */
class CkeditorCustomWidget extends BaseCkeditorCustomWidget
{
    /** Плагины, включённые в редактор файлового модуля по умолчанию. */
    public array $plugins = [
        FileManagerCkeditorAsset::class,
        CodeMirrorCkeditorAsset::class,
    ];
}
