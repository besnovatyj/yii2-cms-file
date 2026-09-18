<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\exception;

use Throwable;

/**
 * Инфраструктурный сбой хранилища (Flysystem/адаптер): недоступен S3, битый архив, нет прав на
 * запись у процесса PHP и т. п. Сообщение наружу — нейтральное; исходное исключение сохраняется
 * в `previous` для логирования API-слоем.
 */
final class StorageFailureException extends FsException
{
    public const string CODE = 'internal';

    public static function wrap(Throwable $cause, ?string $path = null, string $operation = 'операция'): self
    {
        return new self(
            "Внутренняя ошибка хранилища при выполнении: {$operation}.",
            $path,
            ['operation' => $operation],
            $cause,
        );
    }
}
