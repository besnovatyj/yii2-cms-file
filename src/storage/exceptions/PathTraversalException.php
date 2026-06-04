<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\storage\exceptions;

/**
 * Попытка выйти за пределы корня хранилища (Directory Traversal): `..`, NUL-байт, абсолютный путь,
 * символьная ссылка наружу и т.п. Бросается единым примитивом {@see \Besnovatyj\File\storage\StorageMount::path()}
 * и парсером {@see \Besnovatyj\File\storage\VirtualPath::parse()}.
 */
class PathTraversalException extends StorageException
{
}
