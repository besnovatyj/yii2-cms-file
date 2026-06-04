<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\storage\exceptions;

/**
 * Запрошена точка монтирования, которой нет в {@see \Besnovatyj\File\storage\MountRegistry}.
 * Возникает, если фронтенд прислал виртуальный путь с неизвестным `mountId`.
 */
class UnknownMountException extends StorageException
{
}
