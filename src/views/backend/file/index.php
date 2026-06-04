<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

/* @var $this yii\web\View */

$this->title = 'Files';
$this->params['breadcrumbs'][] = $this->title;

echo \Besnovatyj\FileManager\FileManagerWidget::widget(['triggerLabel' => 'Файлы']);

