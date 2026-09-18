<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

/* @var $this yii\web\View */

use Besnovatyj\FileManager\ExplorerWidget;

$this->title = 'Files';
$this->params['breadcrumbs'][] = $this->title;

// Страница «Файлы» на менеджере v2 (проводник), встроенном в страницу.
// Старый виджет v1: \Besnovatyj\FileManager\FileManagerWidget::widget(['triggerLabel' => 'Файлы']).
echo ExplorerWidget::widget([
    'embedded' => true,
    'height' => 'calc(100vh - 220px)',
    'startPath' => '/static',
    'storageKey' => 'fm2:files-page',
]);
