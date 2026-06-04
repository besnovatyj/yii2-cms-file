<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

return [
    'label' => 'Files',
    'iconClass' => 'bi bi-folder2 me-1',
    'url' => ['/File/backend/file/index'],
    'active' => static function () {
        return str_contains(\Yii::$app->request->url, 'File/backend/file');
    },
    '_meta' => [
        'placements' => [
            [
                'location' => 'right-sidebar',
                'group' => 'Service',
                'groupIcon' => 'bi bi-sliders',
                'priority' => 100,
                'groupPriority' => 100,
            ],
        ],
    ],
];
