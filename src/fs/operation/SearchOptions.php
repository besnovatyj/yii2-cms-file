<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\operation;

use Besnovatyj\File\fs\exception\InvalidOperationException;

/**
 * Параметры поиска (контракт §9.15).
 *
 * Запрос — подстрока имени (регистронезависимо) либо маска, если содержит `*`/`?`
 * (`*.jpg`, `report-202?-*`). Полнотекстового поиска по содержимому нет намеренно: это другая
 * задача (индекс), а обход содержимого файлов по запросу — вектор для DoS.
 */
final class SearchOptions
{
    public const int MAX_QUERY_LENGTH = 200;

    /**
     * @param string $query Подстрока или маска имени; не пустая.
     * @param bool $recursive Искать во вложенных папках (по умолчанию да).
     * @param list<string>|null $kinds Только эти виды ('file'|'dir'); null — все.
     * @param int|null $limit Максимум результатов; null — лимит сервера (и не больше него).
     */
    public function __construct(
        public readonly string $query,
        public readonly bool $recursive = true,
        public readonly ?array $kinds = null,
        public readonly ?int $limit = null,
    ) {
        if (trim($query) === '') {
            throw new InvalidOperationException('Пустой поисковый запрос.', null, ['field' => 'query']);
        }
        if (mb_strlen($query) > self::MAX_QUERY_LENGTH) {
            throw new InvalidOperationException('Слишком длинный поисковый запрос.', null, ['field' => 'query', 'max' => self::MAX_QUERY_LENGTH]);
        }
        if ($kinds !== null) {
            foreach ($kinds as $kind) {
                if (!in_array($kind, ['file', 'dir'], true)) {
                    throw new InvalidOperationException("Неизвестный вид узла в фильтре: '{$kind}'.");
                }
            }
        }
        if ($limit !== null && $limit < 1) {
            throw new InvalidOperationException('limit должен быть положительным.');
        }
    }

    /** Маска (есть метасимволы) или подстрока. */
    public function isGlob(): bool
    {
        return str_contains($this->query, '*') || str_contains($this->query, '?');
    }
}
