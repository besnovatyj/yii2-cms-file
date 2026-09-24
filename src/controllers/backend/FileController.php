<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

namespace Besnovatyj\File\controllers\backend;

use yii\web\Controller;

class FileController extends Controller
{
    /**
     * @return string
     */
    public function actionIndex(): string
    {
        return $this->render('index');
    }

}
