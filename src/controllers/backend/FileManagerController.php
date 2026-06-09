<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

namespace Besnovatyj\File\controllers\backend;

use Besnovatyj\File\services\FileManagerService;
use Besnovatyj\File\storage\exceptions\PathTraversalException;
use Besnovatyj\File\storage\exceptions\UnknownMountException;
use Besnovatyj\File\storage\StorageManager;
use Besnovatyj\File\storage\VirtualPath;
use DomainException;
use Throwable;
use Yii;
use yii\web\Controller;
use yii\web\Response;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\ServerErrorHttpException;
use yii\web\UnprocessableEntityHttpException;

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
     * Разбирает виртуальный путь из параметров запроса.
     * Некорректный путь (traversal `..`, NUL-байт, обратный слеш) — ошибка КЛИЕНТА: 400, а не 500.
     * @throws BadRequestHttpException
     */
    private function parsePath(string $raw): VirtualPath
    {
        try {
            return VirtualPath::parse($raw);
        } catch (PathTraversalException $e) {
            throw new BadRequestHttpException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Сервис файловых операций точки монтирования.
     * Неизвестный mountId — клиент сослался на несуществующий «диск»: 404, а не 500.
     * @throws NotFoundHttpException
     */
    private function serviceFor(?string $mountId): FileManagerService
    {
        try {
            return $this->storage->serviceFor((string)$mountId);
        } catch (UnknownMountException $e) {
            throw new NotFoundHttpException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Выполняет файловую операцию, разграничивая ошибки по источнику:
     *  - {@see DomainException} — нарушение бизнес-правила («уже существует», «не найдено»,
     *    некорректное имя): сообщение адресовано пользователю и безопасно (содержит только
     *    виртуальные пути) → 422 с этим сообщением;
     *  - прочие {@see Throwable} — внутренняя ошибка (инфраструктура, адаптер хранилища):
     *    детали ТОЛЬКО в лог, наружу — нейтральный текст без подробностей → 500.
     * @throws UnprocessableEntityHttpException|ServerErrorHttpException
     */
    private function execute(callable $operation): array
    {
        try {
            return $operation();
        } catch (DomainException $e) {
            Yii::warning($e->getMessage(), __METHOD__);
            throw new UnprocessableEntityHttpException($e->getMessage(), 0, $e);
        } catch (Throwable $e) {
            Yii::$app->errorHandler->logException($e);
            throw new ServerErrorHttpException('Внутренняя ошибка файловой операции.', 0, $e);
        }
    }

    /**
     * POST /file/file-manager/list
     * body: { "path": "/{mountId}/..." }   ('' или '/' — виртуальный корень: перечень точек монтирования)
     * @throws BadRequestHttpException|NotFoundHttpException|UnprocessableEntityHttpException|ServerErrorHttpException
     */
    public function actionList(): array
    {
        $path = Yii::$app->request->post('path');
        if ($path === null) {
            throw new BadRequestHttpException('Path is required');
        }

        // Валидация запроса — ДО execute(): её ошибки клиентские (400/404), а не серверные.
        $vp = $this->parsePath((string)$path);
        $service = $vp->isRoot() ? null : $this->serviceFor($vp->mountId);

        return $this->execute(fn(): array => [
            'path' => $path,
            // Виртуальный корень — синтетический перечень точек монтирования как папок.
            // Иначе — содержимое директории внутри конкретной точки монтирования.
            'item' => $service === null
                ? $this->storage->virtualRootDto()
                : $service->getFolderDto($vp->relative),
        ]);
    }

    /**
     * POST /file/file-manager/upload
     * multipart/form-data: path, files[]
     * @throws BadRequestHttpException|NotFoundHttpException|UnprocessableEntityHttpException|ServerErrorHttpException
     */
    public function actionUpload(): array
    {
        $path = Yii::$app->request->post('path');
        if ($path === null) {
            throw new BadRequestHttpException('Path is required');
        }

        $vp = $this->parsePath((string)$path);
        if ($vp->isRoot()) {
            throw new BadRequestHttpException('Нельзя загружать в виртуальный корень — выберите точку монтирования.');
        }
        $service = $this->serviceFor($vp->mountId);

        // FileManagerService сам берёт UploadedFile::getInstanceByName('file')
        return $this->execute(fn(): array => $service->uploadFile($vp->relative));
    }

    /**
     * POST /file/file-manager/delete
     * body: { "paths": ["/{mountId}/a.png", "/{mountId}/b.png"] }
     * @throws BadRequestHttpException|NotFoundHttpException|UnprocessableEntityHttpException|ServerErrorHttpException
     */
    public function actionDelete(): array
    {
        $paths = Yii::$app->request->post('paths', []);
        if (!is_array($paths) || !$paths) {
            throw new BadRequestHttpException('paths[] is required');
        }

        // Группируем по точке монтирования: каждый сервис работает строго в пределах своего mount.
        $byMount = [];
        foreach ($paths as $raw) {
            $vp = $this->parsePath((string)$raw);
            if ($vp->isRoot()) {
                throw new BadRequestHttpException('Нельзя удалять виртуальный корень.');
            }
            $byMount[$vp->mountId][] = $vp->relative;
        }

        // Сервисы резолвим заранее: неизвестный mount отклоняет ВЕСЬ батч (404) до первого удаления.
        $services = [];
        foreach (array_keys($byMount) as $mountId) {
            $services[$mountId] = $this->serviceFor((string)$mountId);
        }

        return $this->execute(static function () use ($byMount, $services): array {
            $results = [];
            foreach ($byMount as $mountId => $relatives) {
                $res = $services[$mountId]->deletePaths($relatives);
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
        });
    }

    /**
     * POST /file/file-manager/mkdir
     * body: { "parentPath": "/{mountId}/blog", "name": "new-folder" }
     * @throws BadRequestHttpException|NotFoundHttpException|UnprocessableEntityHttpException|ServerErrorHttpException
     */
    public function actionMkdir(): array
    {
        $parentPath = Yii::$app->request->post('parentPath');
        $name = Yii::$app->request->post('name');

        if (!$parentPath || !$name) {
            throw new BadRequestHttpException('parentPath and name are required');
        }

        $vp = $this->parsePath((string)$parentPath);
        if ($vp->isRoot()) {
            throw new BadRequestHttpException('Нельзя создавать папку в виртуальном корне — выберите точку монтирования.');
        }
        $service = $this->serviceFor($vp->mountId);

        // dto: FileDto для новой папки
        return $this->execute(fn(): array => $service->createDir($vp->relative, $name));
    }

    /**
     * POST /file/file-manager/rename
     * body: { "parentPath": "/{mountId}/blog", "oldName": "old.png", "newName": "new.png" }
     * @throws BadRequestHttpException
     * @throws NotFoundHttpException
     * @throws UnprocessableEntityHttpException
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

        $vp = $this->parsePath((string)$path);
        if ($vp->isRoot()) {
            throw new BadRequestHttpException('Нельзя переименовывать в виртуальном корне.');
        }
        $service = $this->serviceFor($vp->mountId);

        return $this->execute(fn(): array => $service->rename($vp->relative, $oldName, $newName)); // FileDto
    }

    /**
     * POST /file/file-manager/move
     * body: { "sourcePath": "/{mountId}/...", "targetPath": "/{mountId}/..." }
     * @throws BadRequestHttpException|NotFoundHttpException|UnprocessableEntityHttpException|ServerErrorHttpException
     */
    public function actionMove(): array
    {
        $sourcePath = Yii::$app->request->post('sourcePath');
        $targetPath = Yii::$app->request->post('targetPath');

        if (!$sourcePath || !$targetPath) {
            throw new BadRequestHttpException('sourcePath and targetPath are required');
        }

        $source = $this->parsePath((string)$sourcePath);
        $target = $this->parsePath((string)$targetPath);
        if ($source->isRoot() || $target->isRoot()) {
            throw new BadRequestHttpException('Виртуальный корень не может быть источником или целью перемещения.');
        }
        // Перемещение между разными точками монтирования (local↔S3↔FTP) — отдельная фича адаптеров,
        // пока не поддерживаем (требует копирования между хранилищами, а не rename внутри одного).
        if ($source->mountId !== $target->mountId) {
            throw new BadRequestHttpException('Перемещение между разными хранилищами пока не поддерживается.');
        }
        $service = $this->serviceFor($source->mountId);

        return $this->execute(static function () use ($service, $source, $target): array {
            $service->move($source->relative, $target->relative);
            return ['status' => 'ok'];
        });
    }

    /**
     * POST /file/file-manager/analyze
     * body: { "path": "/{mountId}/origin/photo.jpg" }
     * @throws BadRequestHttpException|NotFoundHttpException|UnprocessableEntityHttpException|ServerErrorHttpException
     */
    public function actionAnalyze(): array
    {
        $path = Yii::$app->request->post('path');

        if (!$path) {
            throw new BadRequestHttpException('Path is required');
        }

        $vp = $this->parsePath((string)$path);
        if ($vp->isRoot()) {
            throw new BadRequestHttpException('Виртуальный корень не является файлом.');
        }
        $service = $this->serviceFor($vp->mountId);

        // Возвращает массив: ['mime' => '...', 'hexDump' => '...', ...]
        return $this->execute(fn(): array => $service->analyzeFile($vp->relative));
    }

    /**
     * GET/POST /file/file-manager/config
     * @throws UnprocessableEntityHttpException|ServerErrorHttpException
     */
    public function actionConfig(): array
    {
        // Конфиг не зависит от точки монтирования — берём сервис по умолчанию.
        return $this->execute(fn(): array => $this->storage->defaultService()->getConfigDto());
        // { fileMaxSize: string|null, allowedMimeTypes: string[] }
    }
}
