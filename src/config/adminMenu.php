<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

use Besnovatyj\Contracts\adminMenu\AdminMenuLocation;
use Besnovatyj\Contracts\adminMenu\AdminMenuPlacement;

return [[
    'label' => 'Files',
    'iconClass' => 'bi bi-folder2 me-1',
    'url' => ['/File/backend/file/index'],
    'active' => static function () {
        return str_contains(\Yii::$app->request->url, 'File/backend/file');
    },
    '_meta' => [
        'placements' => [
            new AdminMenuPlacement(
                location: AdminMenuLocation::RightSidebar,
                group: 'Service',
                groupIcon: 'bi bi-sliders',
                groupPriority: 100,
                priority: 100,
            ),
        ],
    ],
]];
