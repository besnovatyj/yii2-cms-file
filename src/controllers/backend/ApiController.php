<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\controllers\backend;

use Besnovatyj\File\api\OperationRegistry;
use Yii;
use yii\web\Controller;
use yii\web\JsonParser;
use yii\web\Response;

/**
 * Точка входа API файлового менеджера v2 (контракт bescms-fs v1): `/File/backend/api/{operation}`.
 *
 * Контроллёр не содержит логики операций — он строит Yii-actions из {@see OperationRegistry}
 * ({@see actions()}), поэтому:
 *  - каждая операция — отдельный маршрут (`…/api/list`, `…/api/delete`) и отдельное разрешение
 *    route-based RBAC ядра (гейт DefaultDenyAccessControl, fail-closed);
 *  - новая операция появляется в API регистрацией в DI, без правок контроллёра.
 *
 * CSRF-валидация Yii включена: все мутации — POST c заголовком `X-CSRF-Token` (его отправляет
 * фронтенд из конфига интеграции); `download` — GET без побочных эффектов.
 *
 * Старый {@see FileManagerController} (v1) живёт параллельно и не затронут.
 */
final class ApiController extends Controller
{
    public function __construct(
        $id,
        $module,
        private readonly OperationRegistry $operations,
        $config = [],
    ) {
        parent::__construct($id, $module, $config);
    }

    /**
     * Карта action'ов из реестра операций: id action'а = имя операции.
     *
     * @return array<string, array<string, mixed>>
     */
    public function actions(): array
    {
        $actions = [];
        foreach ($this->operations->all() as $name => $operation) {
            $actions[$name] = [
                'class' => OperationAction::class,
                'operation' => $operation,
            ];
        }
        return $actions;
    }

    public function beforeAction($action): bool
    {
        // Тело запросов — JSON (контракт §1); multipart для upload разбирается Yii штатно.
        Yii::$app->request->parsers['application/json'] = JsonParser::class;
        Yii::$app->response->format = Response::FORMAT_JSON;

        return parent::beforeAction($action);
    }
}
