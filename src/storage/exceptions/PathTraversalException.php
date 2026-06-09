<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\storage\exceptions;

/**
 * Попытка выйти за пределы корня хранилища (Directory Traversal): `..`, NUL-байт, обратный слеш.
 * Бросается парсером {@see \Besnovatyj\File\storage\VirtualPath::parse()} (первый рубеж);
 * второй рубеж — сам Flysystem (PathNormalizer бросает свой `PathTraversalDetected`).
 */
class PathTraversalException extends StorageException
{
}
