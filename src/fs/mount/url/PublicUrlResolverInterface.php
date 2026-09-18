<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\mount\url;

/**
 * Стратегия построения публичного URL файла точки монтирования.
 *
 * Отделена от {@see \Besnovatyj\File\fs\mount\Mount}, потому что способов «отдать файл наружу»
 * больше одного: статик-домен/CDN по базовому URL, подписанные временные ссылки S3, отсутствие
 * публичной отдачи вовсе (ZIP, приватный бакет — тогда фронтенд использует `download`).
 */
interface PublicUrlResolverInterface
{
    /**
     * @param string $relative Путь файла относительно корня точки монтирования (без ведущего '/').
     * @return string|null URL либо null, если у файла нет публичного адреса.
     */
    public function urlFor(string $relative): ?string;
}
