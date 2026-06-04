<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

namespace Besnovatyj\File\controllers\backend;

use Exception;
use Besnovatyj\File\storage\StorageManager;
use Yii;
use yii\web\Controller;
use yii\web\Response;

class FileController extends Controller
{
    private StorageManager $storage;

    public function __construct($id, $module, StorageManager $storage, $config = [])
    {
        parent::__construct($id, $module, $config);
        $this->storage = $storage;
    }

    /**
     * @return string
     */
    public function actionIndex(): string
    {
        return $this->render('index');
    }

    /**
     * @see https://ckeditor.com/docs/ckeditor5/latest/features/images/image-upload/simple-upload-adapter.html
     */
    public function actionSuaConnector(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        if (Yii::$app->request->isAjax) {
            try {
                $post = Yii::$app->request->post();
                // SUA не зависит от точки монтирования — берём сервис по умолчанию.
                $data = $this->storage->defaultService()->sua($post);
                return ['url' => $data['url']];
            } catch (Exception $error) {
                Yii::$app->errorHandler->logException($error);
                $appResponse = Yii::$app->response;
                $appResponse->format = Response::FORMAT_JSON;
                $appResponse->content = \yii\helpers\Json::encode(['error' => ['message' => $error->getCode() . ': ' . $error->getMessage()]]);
                $appResponse->send();
                Yii::$app->end();
            }
        }
        return ['error' => ['message' => 'Upload Error. Only Ajax requests are allowed']];
    }

}
