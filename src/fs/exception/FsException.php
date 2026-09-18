<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\exception;

use RuntimeException;
use Throwable;

/**
 * Базовое исключение домена виртуальной файловой системы.
 *
 * Каждое исключение несёт машинный код ошибки контракта `bescms-fs` (см. docs/API-CONTRACT.md §3
 * пакета filemanager-core2), безопасное для пользователя сообщение (только виртуальные пути, никаких
 * реальных путей/стеков) и структурированные детали. HTTP-статус здесь НЕ известен: маппинг кода в
 * статус — задача API-слоя ({@see \Besnovatyj\File\api\ErrorCode}); домен не знает про HTTP.
 *
 * Наследники фиксируют код константой {@see CODE}, чтобы код нельзя было «перепутать» при создании.
 */
abstract class FsException extends RuntimeException
{
    /** Машинный код ошибки контракта. Переопределяется наследниками. */
    public const string CODE = 'internal';

    /**
     * @param string $message Безопасное сообщение для пользователя.
     * @param string|null $path Виртуальный путь, к которому относится ошибка (для UI).
     * @param array<string, mixed> $details Структурированные подробности (например, нарушенное правило).
     * @param Throwable|null $previous Исходная причина — только для логов, наружу не уходит.
     */
    public function __construct(
        string $message,
        private readonly ?string $path = null,
        private readonly array $details = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** Машинный код ошибки контракта (`not_found`, `exists`, ...). */
    final public function errorCode(): string
    {
        return static::CODE;
    }

    /** Виртуальный путь, к которому относится ошибка; null — не привязана к пути. */
    final public function path(): ?string
    {
        return $this->path;
    }

    /** @return array<string, mixed> */
    final public function details(): array
    {
        return $this->details;
    }
}
