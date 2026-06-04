<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\storage;

use Besnovatyj\File\storage\exceptions\PathTraversalException;

/**
 * Разбор входящего ВИРТУАЛЬНОГО пути в пару (mountId, relative).
 *
 * Контракт адресации (фронтенд видит единую ФС, реальные корни скрыты):
 *   ''  или  '/'                  → виртуальный корень (перечень всех точек монтирования);
 *   '/{mountId}'                  → корень точки монтирования;
 *   '/{mountId}/{rel...}'         → вложенный путь внутри точки монтирования.
 *
 * Здесь же — первый рубеж защиты от Directory Traversal: запрещены сегмент `..`, NUL-байт и
 * обратный слеш. Итоговый `relative` — путь ВНУТРИ точки монтирования относительно её корня
 * (с ведущим слешем, либо '' для корня). Второй рубеж — {@see StorageMount::path()} (realpath + confine).
 */
final class VirtualPath
{
    /**
     * @param string|null $mountId Идентификатор точки монтирования; null — виртуальный корень.
     * @param string $relative Путь внутри точки монтирования ('' — её корень, иначе с ведущим '/').
     */
    private function __construct(
        public readonly ?string $mountId,
        public readonly string $relative,
    ) {
    }

    public static function parse(string $raw): self
    {
        if (str_contains($raw, "\0")) {
            throw new PathTraversalException('Недопустимый путь: NUL-байт.');
        }
        if (str_contains($raw, '\\')) {
            throw new PathTraversalException('Недопустимый путь: обратный слеш не разрешён в виртуальном пути.');
        }

        // Нормализуем к виду '/a/b' без хвостового слеша.
        $normalized = rtrim('/' . ltrim($raw, '/'), '/');

        // Запрет обхода каталога по сегментам.
        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '..') {
                throw new PathTraversalException('Обход каталога (..) запрещён: ' . $raw);
            }
        }

        // Пустая нормализованная строка — виртуальный корень.
        if ($normalized === '') {
            return new self(null, '');
        }

        // Первый сегмент — mountId, остальное — путь внутри точки монтирования.
        $parts = explode('/', ltrim($normalized, '/'), 2);
        $mountId = $parts[0];
        $relative = isset($parts[1]) ? '/' . $parts[1] : '';

        return new self($mountId, $relative);
    }

    /** Указывает ли путь на виртуальный корень (перечень точек монтирования). */
    public function isRoot(): bool
    {
        return $this->mountId === null;
    }
}
