<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\storage\exceptions;

use DomainException;

/**
 * Базовое исключение слоя хранилищ (storage). Наследует DomainException, чтобы существующая
 * обработка ошибок в контроллёрах (ловит DomainException/Throwable) продолжала работать без правок.
 */
class StorageException extends DomainException
{
}
