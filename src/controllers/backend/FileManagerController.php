<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

namespace Besnovatyj\File\controllers\backend;

use Besnovatyj\File\storage\StorageManager;
use Besnovatyj\File\storage\VirtualPath;
use Throwable;
use Yii;
use yii\web\Controller;
use yii\web\Response;
use yii\web\BadRequestHttpException;
use yii\web\ServerErrorHttpException;

/**
 * REST-эндпоинты файлового менеджера.
 *
 * Фронтенд оперирует ВИРТУАЛЬНЫМИ путями `/{mountId}/{rel...}` (единая «как будто локальная» ФС;
 * реальные корни скрыты). Контроллёр разбирает их через {@see VirtualPath} и делегирует
 * {@see StorageManager}, который отдаёт сервис нужной точки монтирования. Сам обход каталога
 * исключён на уровне {@see \Besnovatyj\File\storage\StorageMount::path()}.
 */
class FileManagerController extends Controller
{
    private StorageManager $storage;

    public function __construct($id, $module, StorageManager $storage, $config = [])
    {
        parent::__construct($id, $module, $config);
        $this->storage = $storage;
    }

    /**
     * @throws BadRequestHttpException
     */
    public function beforeAction($action): bool
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        Yii::$app->request->parsers = [
            'application/json' => 'yii\web\JsonParser',
        ];

        return parent::beforeAction($action);
    }

    /**
     * POST /file/file-manager/list
     * body: { "path": "/{mountId}/..." }   ('' или '/' — виртуальный корень: перечень точек монтирования)
     * @throws BadRequestHttpException|ServerErrorHttpException
     */
    public function actionList(): array
    {
        $path = Yii::$app->request->post('path');
        if ($path === null) {
            throw new BadRequestHttpException('Path is required');
        }

        try {
            $vp = VirtualPath::parse((string)$path);

            // Виртуальный корень — синтетический перечень точек монтирования как папок.
            // Иначе — содержимое директории внутри конкретной точки монтирования.
            $item = $vp->isRoot()
                ? $this->storage->virtualRootDto()
                : $this->storage->serviceFor($vp->mountId)->getFolderDto($vp->relative);

            return [
                'path' => $path,
                'item' => $item,
            ];
        } catch (Throwable $e) {
            Yii::$app->errorHandler->logException($e);

            // можно вернуть 500 и JSON с message
            throw new ServerErrorHttpException($e->getMessage(), 0, $e);
        }
    }

    /**
     * POST /file/file-manager/upload
     * multipart/form-data: path, files[]
     * @throws BadRequestHttpException|ServerErrorHttpException
     */
    public function actionUpload(): array
    {
        $path = Yii::$app->request->post('path');
        if ($path === null) {
            throw new BadRequestHttpException('Path is required');
        }

        try {
            $vp = VirtualPath::parse((string)$path);
            if ($vp->isRoot()) {
                throw new BadRequestHttpException('Нельзя загружать в виртуальный корень — выберите точку монтирования.');
            }

            // FileManagerService сам берёт UploadedFile::getInstanceByName('file')
            return $this->storage->serviceFor($vp->mountId)->uploadFile($vp->relative);
        } catch (Throwable $e) {
            Yii::$app->errorHandler->logException($e);
            throw new ServerErrorHttpException($e->getMessage(), 0, $e);
        }
    }

    /**
     * POST /file/file-manager/delete
     * body: { "paths": ["/{mountId}/a.png", "/{mountId}/b.png"] }
     * @throws BadRequestHttpException|ServerErrorHttpException
     */
    public function actionDelete(): array
    {
        $paths = Yii::$app->request->post('paths', []);
        if (!is_array($paths) || !$paths) {
            throw new BadRequestHttpException('paths[] is required');
        }
        try {
            // Группируем по точке монтирования: каждый сервис работает строго в пределах своего mount.
            $byMount = [];
            foreach ($paths as $raw) {
                $vp = VirtualPath::parse((string)$raw);
                if ($vp->isRoot()) {
                    throw new BadRequestHttpException('Нельзя удалять виртуальный корень.');
                }
                $byMount[$vp->mountId][] = $vp->relative;
            }

            $results = [];
            foreach ($byMount as $mountId => $relatives) {
                $res = $this->storage->serviceFor($mountId)->deletePaths($relatives);
                foreach ($res['results'] as $r) {
                    $results[] = $r;
                }
            }

            // Сводка по объединённому результату всех точек монтирования.
            return [
                'results' => $results,
                'summary' => [
                    'total' => count($results),
                    'deleted' => count(array_filter($results, static fn($r) => !empty($r['ok']))),
                    'failed' => count(array_filter($results, static fn($r) => empty($r['ok']))),
                ],
            ];
        } catch (Throwable $e) {
            Yii::$app->errorHandler->logException($e);
            throw new ServerErrorHttpException($e->getMessage(), 0, $e);
        }
    }

    /**
     * POST /file/file-manager/mkdir
     * body: { "parentPath": "/{mountId}/blog", "name": "new-folder" }
     * @throws BadRequestHttpException|ServerErrorHttpException
     */
    public function actionMkdir(): array
    {
        $parentPath = Yii::$app->request->post('parentPath');
        $name = Yii::$app->request->post('name');

        if (!$parentPath || !$name) {
            throw new BadRequestHttpException('parentPath and name are required');
        }

        try {
            $vp = VirtualPath::parse((string)$parentPath);
            if ($vp->isRoot()) {
                throw new BadRequestHttpException('Нельзя создавать папку в виртуальном корне — выберите точку монтирования.');
            }

            // dto: FileDto для новой папки
            return $this->storage->serviceFor($vp->mountId)->createDir($vp->relative, $name);
        } catch (Throwable $e) {
            Yii::$app->errorHandler->logException($e);
            throw new ServerErrorHttpException($e->getMessage(), 0, $e);
        }
    }

    /**
     * POST /file/file-manager/rename
     * body: { "parentPath": "/{mountId}/blog", "oldName": "old.png", "newName": "new.png" }
     * @throws BadRequestHttpException
     * @throws ServerErrorHttpException
     */
    public function actionRename(): array
    {
        $path = Yii::$app->request->post('parentPath');
        $oldName = Yii::$app->request->post('oldName');
        $newName = Yii::$app->request->post('newName');
        if (!$path || !$oldName || !$newName) {
            throw new BadRequestHttpException('path and newName are required');
        }
        try {
            $vp = VirtualPath::parse((string)$path);
            if ($vp->isRoot()) {
                throw new BadRequestHttpException('Нельзя переименовывать в виртуальном корне.');
            }

            return $this->storage->serviceFor($vp->mountId)->rename($vp->relative, $oldName, $newName); // FileDto
        } catch (Throwable $e) {
            Yii::$app->errorHandler->logException($e);
            throw new ServerErrorHttpException($e->getMessage(), 0, $e);
        }
    }

    /**
     * POST /file/file-manager/move
     * body: { "sourcePath": "/{mountId}/...", "targetPath": "/{mountId}/..." }
     * @throws BadRequestHttpException|ServerErrorHttpException
     */
    public function actionMove(): array
    {
        $sourcePath = Yii::$app->request->post('sourcePath');
        $targetPath = Yii::$app->request->post('targetPath');

        if (!$sourcePath || !$targetPath) {
            throw new BadRequestHttpException('sourcePath and targetPath are required');
        }

        try {
            $source = VirtualPath::parse((string)$sourcePath);
            $target = VirtualPath::parse((string)$targetPath);
            if ($source->isRoot() || $target->isRoot()) {
                throw new BadRequestHttpException('Виртуальный корень не может быть источником или целью перемещения.');
            }
            // Перемещение между разными точками монтирования (local↔S3↔FTP) — отдельная фича адаптеров,
            // пока не поддерживаем (требует копирования между хранилищами, а не rename внутри одного).
            if ($source->mountId !== $target->mountId) {
                throw new BadRequestHttpException('Перемещение между разными хранилищами пока не поддерживается.');
            }

            $this->storage->serviceFor($source->mountId)->move($source->relative, $target->relative);
            return ['status' => 'ok'];
        } catch (Throwable $e) {
            Yii::$app->errorHandler->logException($e);
            throw new ServerErrorHttpException($e->getMessage(), 0, $e);
        }
    }

    /**
     * POST /file/file-manager/analyze
     * body: { "path": "/{mountId}/origin/photo.jpg" }
     * @throws BadRequestHttpException|ServerErrorHttpException
     */
    public function actionAnalyze(): array
    {
        $path = Yii::$app->request->post('path');

        if (!$path) {
            throw new BadRequestHttpException('Path is required');
        }

        try {
            $vp = VirtualPath::parse((string)$path);
            if ($vp->isRoot()) {
                throw new BadRequestHttpException('Виртуальный корень не является файлом.');
            }

            // Возвращает массив: ['mime' => '...', 'hexDump' => '...', ...]
            return $this->storage->serviceFor($vp->mountId)->analyzeFile($vp->relative);
        } catch (Throwable $e) {
            Yii::$app->errorHandler->logException($e);
            throw new ServerErrorHttpException($e->getMessage(), 0, $e);
        }
    }

    /**
     * GET/POST /file/file-manager/config
     * @throws ServerErrorHttpException
     */
    public function actionConfig(): array
    {
        try {
            // Конфиг не зависит от точки монтирования — берём сервис по умолчанию.
            return $this->storage->defaultService()->getConfigDto();
            // { fileMaxSize: string|null, allowedMimeTypes: string[] }
        } catch (Throwable $e) {
            Yii::$app->errorHandler->logException($e);
            throw new ServerErrorHttpException($e->getMessage(), 0, $e);
        }
    }
}
