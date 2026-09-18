<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\mount\url;

/**
 * У точки монтирования нет публичной отдачи (ZIP-архив, приватный бакет): `url` узлов — null,
 * скачивание идёт через операцию `download` бэкенда.
 */
final class NoPublicUrlResolver implements PublicUrlResolverInterface
{
    public function urlFor(string $relative): ?string
    {
        return null;
    }
}
