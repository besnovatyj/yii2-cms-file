<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\api;

use Besnovatyj\File\fs\path\PathScope;
use Closure;

/**
 * Контекст вызова операции: кто вызывает и что ему разрешено.
 *
 * Права проверяет хост (в Yii — route-based RBAC ядра) ДО вызова операции; сюда передаётся
 * предикат, чтобы `describe` мог перечислить только разрешённые операции тем же механизмом.
 * Операции про Yii не знают.
 */
final class ApiContext
{
    /**
     * @param int|string|null $userId Идентификатор пользователя (для логов/аудита); null — гость.
     * @param Closure(string): bool $isOperationAllowed Разрешена ли операция с данным именем.
     * @param PathScope|null $scope Область видимости запроса; null — без ограничений.
     */
    public function __construct(
        public readonly int|string|null $userId,
        private readonly Closure $isOperationAllowed,
        ?PathScope $scope = null,
    ) {
        $this->scope = $scope ?? PathScope::unrestricted();
    }

    /** Область видимости запроса (всегда задана; без токена — неограниченная). */
    public readonly PathScope $scope;

    public function isOperationAllowed(string $operation): bool
    {
        return ($this->isOperationAllowed)($operation);
    }
}
