<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\operation;

use Besnovatyj\File\fs\exception\InvalidOperationException;

/**
 * Что делать, если целевой узел уже существует (контракт §8, ADR-6).
 *
 * По умолчанию — {@see Fail}: сервер никогда не перезаписывает молча; фронтенд получает
 * `exists`, показывает диалог и повторяет запрос с выбранной стратегией.
 */
enum ConflictStrategy: string
{
    case Fail = 'fail';
    case Overwrite = 'overwrite';
    case Rename = 'rename';
    case Skip = 'skip';

    /**
     * @throws InvalidOperationException неизвестное значение от клиента.
     */
    public static function fromInput(?string $value): self
    {
        if ($value === null || $value === '') {
            return self::Fail;
        }
        return self::tryFrom($value)
            ?? throw new InvalidOperationException(
                "Неизвестная стратегия конфликта: '{$value}'.",
                null,
                ['allowed' => array_map(static fn(self $s): string => $s->value, self::cases())],
            );
    }
}
