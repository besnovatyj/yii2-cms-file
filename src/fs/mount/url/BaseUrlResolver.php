<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\mount\url;

/**
 * URL = базовый адрес + относительный путь, каждый сегмент которого URL-кодируется отдельно
 * (пробелы, кириллица, `#`, `?` в именах файлов иначе ломают ссылку в редакторе).
 * Базовый URL соответствует КОРНЮ точки монтирования — её id в URL не входит.
 */
final class BaseUrlResolver implements PublicUrlResolverInterface
{
    private readonly string $baseUrl;

    public function __construct(string $baseUrl)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function urlFor(string $relative): ?string
    {
        $segments = array_map(rawurlencode(...), explode('/', ltrim($relative, '/')));
        return $this->baseUrl . '/' . implode('/', $segments);
    }
}
