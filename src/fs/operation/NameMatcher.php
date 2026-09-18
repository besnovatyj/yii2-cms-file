<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\operation;

/**
 * Сопоставление имени с запросом поиска: подстрока либо маска `*`/`?` — регистронезависимо,
 * с учётом Unicode. Маска компилируется в регулярное выражение один раз; всё, кроме `*` и `?`,
 * экранируется, поэтому пользовательский ввод в PCRE не «утекает».
 *
 * Зеркало клиентской реализации (`domain/search/nameMatcher.ts`): та же семантика нужна на
 * клиенте, чтобы патчить результаты поиска по фактам операций без повторного запроса.
 */
final class NameMatcher
{
    private readonly ?string $pattern;
    private readonly string $needle;

    public function __construct(string $query)
    {
        $trimmed = trim($query);
        if (str_contains($trimmed, '*') || str_contains($trimmed, '?')) {
            $regex = strtr(preg_quote($trimmed, '/'), ['\*' => '.*', '\?' => '.']);
            $this->pattern = '/^' . $regex . '$/iu';
            $this->needle = '';
        } else {
            $this->pattern = null;
            $this->needle = mb_strtolower($trimmed);
        }
    }

    public function matches(string $name): bool
    {
        if ($this->pattern !== null) {
            return preg_match($this->pattern, $name) === 1;
        }
        return $this->needle === '' || str_contains(mb_strtolower($name), $this->needle);
    }
}
