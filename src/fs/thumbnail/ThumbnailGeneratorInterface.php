<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\thumbnail;

/**
 * Генератор миниатюры из байтов изображения. Реализация по умолчанию — {@see ImagineGenerator}
 * (Imagick/GD через imagine/imagine); другая библиотека или внешний сервис (imgproxy, Lambda) —
 * ещё одна реализация интерфейса.
 */
interface ThumbnailGeneratorInterface
{
    /** Доступен ли генератор в этом окружении (есть расширение/сервис). */
    public function isAvailable(): bool;

    /**
     * Вписать изображение в квадрат $size×$size, сохранив пропорции; метаданные (EXIF) удалить,
     * ориентацию применить.
     *
     * @param string $bytes Исходное изображение (уже проверенного растрового типа).
     * @param string $mime MIME исходника по содержимому.
     * @throws \RuntimeException изображение не декодируется.
     */
    public function generate(string $bytes, string $mime, int $size, int $quality): ThumbnailImage;
}
