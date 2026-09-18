<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\tus;

/**
 * Хранилище незавершённых загрузок: метаданные + байты. Файловая реализация —
 * {@see FileTusStore}; при горизонтальном масштабировании понадобится общее хранилище
 * (Redis для метаданных + общий диск/S3 для байтов) — это ещё одна реализация интерфейса.
 */
interface TusStoreInterface
{
    public function create(TusUpload $upload): void;

    public function find(string $id): ?TusUpload;

    /**
     * Дописать байты из потока с проверкой ожидаемого смещения (под блокировкой: два PATCH
     * одной загрузки не должны переплетаться). Возвращает запись с новым offset.
     *
     * @param resource $stream
     * @param int $maxBytes Больше этого из потока не читается (защита от гигантского тела).
     * @throws TusException 409 при расхождении offset, 413 при превышении длины.
     */
    public function append(TusUpload $upload, $stream, int $expectedOffset, int $maxBytes): TusUpload;

    /** Путь к файлу с данными (для финализации). */
    public function dataPath(TusUpload $upload): string;

    public function delete(string $id): void;

    /** @return iterable<TusUpload> */
    public function all(): iterable;
}
