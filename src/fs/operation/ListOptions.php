<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\operation;

use Besnovatyj\File\fs\exception\InvalidOperationException;

/**
 * Параметры листинга (контракт §9.1): пагинация, сортировка, фильтр.
 * Неизвестные значения сортировки/фильтра — ошибка клиента, а не молчаливый дефолт.
 */
final class ListOptions
{
    public const array SORT_KEYS = ['name', 'size', 'mtime', 'ext', 'kind'];

    /**
     * @param string|null $cursor Курсор следующей страницы (из предыдущего ответа) либо null.
     * @param int|null $limit Размер страницы; null — лимит сервера.
     * @param string $sortBy Ключ сортировки.
     * @param bool $sortDesc Направление.
     * @param list<string>|null $kinds Только эти виды ('file'|'dir'); null — все.
     * @param string|null $nameContains Подстрока имени (регистронезависимо).
     * @param list<string>|null $exts Только эти расширения (lowercase); null — все.
     * @param bool $all Без пагинации (внутреннее использование: дерево, служебные обходы).
     */
    public function __construct(
        public readonly ?string $cursor = null,
        public readonly ?int $limit = null,
        public readonly string $sortBy = 'name',
        public readonly bool $sortDesc = false,
        public readonly ?array $kinds = null,
        public readonly ?string $nameContains = null,
        public readonly ?array $exts = null,
        public readonly bool $all = false,
    ) {
        if (!in_array($sortBy, self::SORT_KEYS, true)) {
            throw new InvalidOperationException("Неизвестный ключ сортировки: '{$sortBy}'.", null, ['allowed' => self::SORT_KEYS]);
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
}
