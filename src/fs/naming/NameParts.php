<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\naming;

/**
 * Разбор имени на основу и расширение: 'report.final.pdf' → ('report.final', 'pdf');
 * '.env' → ('.env', null) — ведущая точка не считается разделителем расширения;
 * 'README' → ('README', null).
 *
 * Расширение возвращается в lowercase — так его используют и политики, и сериализация узла.
 */
final class NameParts
{
    private function __construct(
        public readonly string $stem,
        public readonly ?string $extension,
    ) {
    }

    public static function split(string $name): self
    {
        $pos = strrpos($name, '.');
        if ($pos === false || $pos === 0 || $pos === strlen($name) - 1) {
            return new self($name, null);
        }
        return new self(substr($name, 0, $pos), strtolower(substr($name, $pos + 1)));
    }

    /**
     * Все dot-сегменты после основы: 'shell.php.jpg' → ['php', 'jpg']. Нужны блок-листу
     * расширений (Apache mod_mime обрабатывает КАЖДОЕ расширение в имени).
     *
     * @return list<string>
     */
    public static function allExtensions(string $name): array
    {
        $parts = explode('.', ltrim($name, '.'));
        array_shift($parts);
        return array_values(array_filter(array_map(strtolower(...), $parts), static fn(string $p): bool => $p !== ''));
    }

    /** Собирает имя из основы и расширения. */
    public static function join(string $stem, ?string $extension): string
    {
        return $extension === null || $extension === '' ? $stem : $stem . '.' . $extension;
    }
}
