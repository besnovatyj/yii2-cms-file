<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\adapters;

use Besnovatyj\Editor\EditorOptions;
use Besnovatyj\Editor\contracts\EditorAdapterInterface;
use Besnovatyj\File\widgets\CkeditorCustomWidget;

/**
 * Адаптер редактора CKEditor 5 для фасада yii2-cms-editor.
 *
 * Целится в «батарейную» обёртку {@see CkeditorCustomWidget} файлового модуля (файловый
 * менеджер + CodeMirror уже подключены плагинами) — именно её использовали формы модулей
 * до появления фасада, поэтому поведение при выборе движка 'ckeditor5' сохраняется.
 * Адаптер живёт рядом с этой обёрткой, а не в базовом пакете yii2-cms-ckeditor5.
 */
final class CkeditorEditorAdapter implements EditorAdapterInterface
{
    public function widgetClass(): string
    {
        return CkeditorCustomWidget::class;
    }

    public function buildConfig(EditorOptions $options): array
    {
        $config = [];

        if ($options->language !== null) {
            $config['language'] = $options->language;
        }
        if ($options->placeholder !== null) {
            $config['placeholder'] = $options->placeholder;
        }
        if ($options->fmDefaultPath !== null) {
            $config['fmDefaultPath'] = $options->fmDefaultPath;
        }

        // Неприменимые к CKEditor 5 нормализованные опция намеренно игнорируются:
        //   - height задаётся через CSS/config (при нужде — EditorWidget::$engineConfig['ckeditor5']);
        //   - файловый менеджер включён набором плагинов обёртки, отдельного флага нет.

        return $config;
    }
}
