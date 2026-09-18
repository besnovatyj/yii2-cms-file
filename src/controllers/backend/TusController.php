<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\controllers\backend;

use Besnovatyj\File\fs\tus\TusConfig;
use Besnovatyj\File\fs\tus\TusException;
use Besnovatyj\File\fs\tus\TusResponse;
use Besnovatyj\File\fs\tus\TusServer;
use Throwable;
use Yii;
use yii\helpers\Url;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * HTTP-адаптер сервера tus (докачиваемая загрузка, контракт §13).
 *
 * Маршруты:
 *  - `POST /File/backend/tus/create` — создать загрузку (Upload-Length, Upload-Metadata) → 201 + Location;
 *  - `HEAD|PATCH|DELETE|OPTIONS /File/backend/tus/upload?id=…` — смещение / дописать кусок / отказаться.
 *
 * Байты попадают во временный каталог и в хранилище НЕ записываются: перенос в папку делает
 * операция `upload-finalize` API (та же санитизация, политика и конфликты, что у обычного upload).
 * CSRF Yii включён: tus-клиент шлёт `X-CSRF-Token` (и `X-Fs-Scope`) в заголовках каждого запроса.
 * Каждое действие — отдельный маршрут для RBAC ядра.
 */
final class TusController extends Controller
{
    public function __construct(
        $id,
        $module,
        private readonly TusServer $tus,
        private readonly TusConfig $tusConfig,
        $config = [],
    ) {
        parent::__construct($id, $module, $config);
    }

    public function beforeAction($action): bool
    {
        if (!$this->tusConfig->enabled) {
            throw new NotFoundHttpException('Докачиваемая загрузка отключена.');
        }
        // Тело PATCH — сырые байты; ни один парсер Yii к нему применяться не должен.
        Yii::$app->request->parsers = [];
        Yii::$app->response->format = Response::FORMAT_RAW;

        return parent::beforeAction($action);
    }

    /** POST — создание загрузки. */
    public function actionCreate(): Response
    {
        return $this->handle(function (): TusResponse {
            $request = Yii::$app->request;
            if ($request->getMethod() === 'OPTIONS') {
                return $this->tus->options();
            }
            if (!$request->getIsPost()) {
                throw new TusException(405, 'Только POST.');
            }
            return $this->tus->create(
                $request->headers->get('Upload-Length'),
                $request->headers->get('Upload-Metadata'),
                $this->ownerId(),
                static fn(string $id): string => Url::to(['/File/backend/tus/upload', 'id' => $id], true),
            );
        });
    }

    /** HEAD / PATCH / DELETE / OPTIONS — работа с конкретной загрузкой. */
    public function actionUpload(string $id = ''): Response
    {
        return $this->handle(function () use ($id): TusResponse {
            $request = Yii::$app->request;
            switch ($request->getMethod()) {
                case 'OPTIONS':
                    return $this->tus->options();
                case 'HEAD':
                    return $this->tus->head($id, $this->ownerId());
                case 'PATCH': {
                    $body = fopen('php://input', 'rb');
                    if ($body === false) {
                        throw new TusException(500, 'Не удалось прочитать тело запроса.');
                    }
                    try {
                        return $this->tus->patch(
                            $id,
                            $this->ownerId(),
                            $request->headers->get('Upload-Offset'),
                            strtolower(trim((string)strtok((string)$request->headers->get('Content-Type'), ';'))),
                            $body,
                        );
                    } finally {
                        fclose($body);
                    }
                }
                case 'DELETE':
                    return $this->tus->terminate($id, $this->ownerId());
                default:
                    throw new TusException(405, 'Метод не поддерживается.');
            }
        });
    }

    /**
     * Единая отправка: заголовки Tus-*, статус; ошибки протокола — статусом с текстом,
     * непредвиденные — 500 с записью в лог (наружу без подробностей).
     *
     * @param callable(): TusResponse $operation
     */
    private function handle(callable $operation): Response
    {
        $response = Yii::$app->response;
        try {
            $result = $operation();
        } catch (TusException $e) {
            $result = new TusResponse($e->status, ['Tus-Resumable' => TusServer::VERSION, 'Content-Type' => 'text/plain; charset=utf-8']);
            $response->content = $e->getMessage();
        } catch (Throwable $e) {
            Yii::$app->errorHandler->logException($e);
            $result = new TusResponse(500, ['Tus-Resumable' => TusServer::VERSION]);
            $response->content = 'Внутренняя ошибка загрузки.';
        }

        $response->setStatusCode($result->status);
        foreach ($result->headers as $name => $value) {
            $response->headers->set($name, $value);
        }
        $response->headers->set('Cache-Control', 'no-store');
        return $response;
    }

    /** Владелец загрузки — текущий пользователь (гость сюда не проходит: RBAC по маршруту). */
    private function ownerId(): string
    {
        return (string)(Yii::$app->getUser()->getId() ?? '');
    }
}
