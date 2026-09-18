<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\naming;

use Closure;

/**
 * Подбор незанятого имени в стиле проводника: 'report.pdf' → 'report (2).pdf' → 'report (3).pdf'.
 * Используется стратегией конфликтов `rename` (контракт §8).
 *
 * Если у имени уже есть суффикс '(N)', счётчик продолжается с N+1 — так 'report (2).pdf' при
 * повторном конфликте станет 'report (3).pdf', а не 'report (2) (2).pdf'.
 */
final class UniqueNameGenerator
{
    /** После стольких попыток переходим на случайный суффикс — защита от патологических каталогов. */
    public const int MAX_SEQUENTIAL_ATTEMPTS = 1000;

    /**
     * @param string $name Желаемое имя.
     * @param Closure(string): bool $exists Предикат «имя занято» в целевой директории.
     */
    public function generate(string $name, Closure $exists): string
    {
        if (!$exists($name)) {
            return $name;
        }

        $parts = NameParts::split($name);
        $stem = $parts->stem;
        $start = 2;

        if (preg_match('/^(.*) \((\d+)\)$/', $stem, $m) === 1) {
            $stem = $m[1];
            $start = (int)$m[2] + 1;
        }

        for ($i = $start; $i < $start + self::MAX_SEQUENTIAL_ATTEMPTS; $i++) {
            $candidate = NameParts::join($stem . ' (' . $i . ')', $parts->extension);
            if (!$exists($candidate)) {
                return $candidate;
            }
        }

        do {
            $candidate = NameParts::join($stem . ' (' . bin2hex(random_bytes(4)) . ')', $parts->extension);
        } while ($exists($candidate));

        return $candidate;
    }
}
