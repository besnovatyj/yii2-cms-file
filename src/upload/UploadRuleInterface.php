<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\upload;

use Besnovatyj\File\storage\StorageMount;
use yii\web\UploadedFile;

/**
 * Одно правило политики загрузки {@see UploadPolicy}.
 *
 * Точка расширения серверных валидаций: новая проверка (MIME/контент через finfo, размеры
 * изображений, санитизация SVG, антивирус, per-mount ограничения и т.д.) — это новый класс,
 * реализующий этот интерфейс и зарегистрированный в config/container.php. Сервис, контроллёры
 * и контракт API при этом не меняются.
 */
interface UploadRuleInterface
{
    /**
     * Проверяет загружаемый файл; нарушение политики — исключение (наружу уйдёт как 422).
     *
     * @param UploadedFile $file Загруженный файл (temp-файл доступен через $file->tempName —
     *                           контентные правила читают реальные байты, не доверяя клиенту).
     * @param string $targetName ФАКТИЧЕСКОЕ имя записи (после санитизации) — правила проверяют
     *                           то, что реально попадёт в хранилище.
     * @param StorageMount $mount Точка монтирования назначения (для будущих per-mount правил).
     * @throws UploadRejectedException
     */
    public function validate(UploadedFile $file, string $targetName, StorageMount $mount): void;

    /**
     * Вклад правила в `BackendCapabilities.upload` (контракт GetConfigResponse фронтенда) —
     * UX-подсказка клиенту о действующем ограничении. [] — правило ничего не сообщает.
     * Enforcement ВСЕГДА остаётся на сервере (в validate()).
     */
    public function capabilities(): array;
}
