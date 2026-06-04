<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\storage;

use Besnovatyj\File\storage\exceptions\PathTraversalException;
use Besnovatyj\File\storage\exceptions\StorageException;

/**
 * Точка монтирования хранилища — неизменяемый value object.
 *
 * Идея: для фронтенда это «диск» с именем {@see $id}; реальный корень {@see $realRoot} скрыт.
 * Фронтенд оперирует виртуальными путями `/{id}/...`, сервер маппит их в абсолютные пути ФС.
 * {@see $baseUrl} — публичная база для URL файлов (статик-домен / CDN / S3), не путать с
 * API-коннектором: навигация ходит на API, а готовые файлы отдаются с baseUrl.
 *
 * ЕДИНСТВЕННЫЙ примитив безопасности файловых операций — {@see path()}: отображает относительный
 * путь в абсолютный и гарантирует, что результат лежит ВНУТРИ realRoot (защита от Directory Traversal).
 * Все обращения сервиса к ФС должны идти только через него.
 *
 * NB: сейчас реализация локальная (realpath/ФС). При будущем переходе на адаптеры (S3/FTP) ровно
 * этот класс станет точкой, за которой прячется конкретный StorageAdapter, — публичный контракт
 * (path/virtual/url) останется прежним.
 */
final class StorageMount
{
    /** Канонизированный (через realpath) абсолютный корень — реальная директория на диске. */
    public readonly string $realRoot;

    /**
     * @param string $id Идентификатор-имя точки монтирования (виден фронтенду как сегмент пути). Напр. 'static'.
     * @param string $realRoot Реальный корень на диске (скрыт от клиента). Канонизируется в конструкторе.
     * @param string $baseUrl Публичная база URL файлов этой точки монтирования.
     * @param string $label Человекочитаемая подпись (для UI/логов).
     */
    public function __construct(
        public readonly string $id,
        string $realRoot,
        public readonly string $baseUrl,
        public readonly string $label = '',
    ) {
        if ($id === '' || str_contains($id, '/')) {
            throw new StorageException("Некорректный id точки монтирования: '{$id}'");
        }

        // Канонизируем корень один раз: снимает симлинки и '..', чтобы сравнения границ в path() были корректны.
        $canonical = realpath($realRoot);
        if ($canonical === false) {
            throw new StorageException("Точка монтирования '{$id}': realRoot не существует: {$realRoot}");
        }
        $this->realRoot = $canonical;
    }

    /**
     * Абсолютный путь СУЩЕСТВУЮЩЕЙ записи внутри точки монтирования по относительному пути
     * ('' — корень). Гарантирует, что результат не выходит за realRoot (Directory Traversal).
     *
     * @throws PathTraversalException выход за корень (в т.ч. через симлинк наружу).
     * @throws StorageException путь не существует.
     */
    public function path(string $relative): string
    {
        if (str_contains($relative, "\0")) {
            throw new PathTraversalException('Недопустимый путь: NUL-байт.');
        }

        $candidate = $relative === ''
            ? $this->realRoot
            : $this->realRoot . DIRECTORY_SEPARATOR . ltrim($relative, '/\\');

        $real = realpath($candidate);
        if ($real === false) {
            throw new StorageException('Путь не существует или недоступен: ' . $relative);
        }

        // Граница каталога с разделителем: исключает обход через папку-сосед
        // (например realRoot=/var/www/static не должен пропускать /var/www/static-secret).
        if ($real !== $this->realRoot && !str_starts_with($real, $this->realRoot . DIRECTORY_SEPARATOR)) {
            throw new PathTraversalException('Выход за пределы корня хранилища: ' . $relative);
        }

        return $real;
    }

    /**
     * Виртуальный путь (с префиксом точки монтирования) для отдачи фронтенду.
     * '' → '/{id}', '/sub' → '/{id}/sub'.
     */
    public function virtual(string $relative): string
    {
        $rel = $relative === '' ? '' : '/' . ltrim($relative, '/');
        return '/' . $this->id . $rel;
    }

    /**
     * Виртуальный путь РОДИТЕЛЯ для данного относительного пути.
     * '' (корень точки монтирования) → '' (родитель — виртуальный корень);
     * '/sub' → '/{id}'; '/a/b' → '/{id}/a'.
     */
    public function virtualParent(string $relative): string
    {
        $normalized = rtrim('/' . ltrim($relative, '/'), '/');
        if ($normalized === '') {
            return '';
        }
        $parentRelative = substr($normalized, 0, (int)strrpos($normalized, '/'));
        return $this->virtual($parentRelative);
    }

    /**
     * Публичный URL файла: baseUrl + относительный (mount-internal) путь файла.
     * Внимание: URL строится по ОТНОСИТЕЛЬНОМУ пути (baseUrl отображает realRoot),
     * а НЕ по виртуальному — префикс точки монтирования сюда не входит.
     */
    public function url(string $relativeFile): string
    {
        return rtrim($this->baseUrl, '/') . '/' . ltrim($relativeFile, '/');
    }
}
