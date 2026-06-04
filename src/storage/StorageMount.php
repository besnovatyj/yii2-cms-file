<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\storage;

use Besnovatyj\File\storage\exceptions\StorageException;
use League\Flysystem\Filesystem;

/**
 * Точка монтирования хранилища — неизменяемый value object поверх League\Flysystem.
 *
 * Для фронтенда это «диск» с именем {@see $id}; реальное расположение (локальный каталог, ZIP-архив,
 * бакет S3 с префиксом) скрыто внутри Flysystem-адаптера. Фронтенд оперирует виртуальными путями
 * `/{id}/...`, сервис — относительными путями внутри {@see Filesystem} (адаптеро-независимо).
 *
 * {@see $baseUrl} — публичная база для URL файлов (статик-домен / CDN / S3), соответствующая корню
 * этого хранилища; не путать с API-коннектором (навигация ходит на API, готовые файлы — с baseUrl).
 *
 * Безопасность путей (обход каталога `..`) обеспечивает сам Flysystem (PathNormalizer бросает
 * `PathTraversalDetected`); первый рубеж — {@see VirtualPath::parse()}. Отдельный realpath-примитив
 * больше не нужен и убран — это и есть смысл перехода на адаптеры.
 */
final class StorageMount
{
    /**
     * @param string $id Идентификатор-имя точки монтирования (виден фронтенду как сегмент пути). Напр. 'static'.
     * @param Filesystem $filesystem Flysystem-файловая система (Local / ZipArchive / AwsS3V3 / …).
     * @param string $baseUrl Публичная база URL файлов этой точки монтирования.
     * @param string $label Человекочитаемая подпись (для UI/логов).
     */
    public function __construct(
        public readonly string $id,
        private readonly Filesystem $filesystem,
        public readonly string $baseUrl,
        public readonly string $label = '',
    ) {
        if ($id === '' || str_contains($id, '/')) {
            throw new StorageException("Некорректный id точки монтирования: '{$id}'");
        }
    }

    /** Файловая система Flysystem этой точки монтирования (все операции идут через неё). */
    public function filesystem(): Filesystem
    {
        return $this->filesystem;
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
     * Публичный URL файла: baseUrl + относительный (внутримаунтовый) путь файла.
     * Префикс точки монтирования сюда НЕ входит — baseUrl уже отображает корень хранилища.
     */
    public function url(string $relativeFile): string
    {
        return rtrim($this->baseUrl, '/') . '/' . ltrim($relativeFile, '/');
    }
}
