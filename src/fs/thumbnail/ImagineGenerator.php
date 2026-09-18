<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\thumbnail;

use Imagine\Filter\Basic\Autorotate;
use Imagine\Image\Box;
use Imagine\Image\ImageInterface;
use Imagine\Image\ImagineInterface;
use Imagine\Image\Metadata\ExifMetadataReader;
use RuntimeException;
use Throwable;

/**
 * Миниатюры через imagine/imagine: Imagick, если есть, иначе GD (тот же стек, что у
 * `yii2-cms-upload`, но без привязки к Yii-обёртке `yii\imagine\Image`).
 *
 * Выход всегда перекодирован: PNG для исходников с прозрачностью (png/gif/webp), JPEG для
 * остальных. Перекодирование — часть защиты: в миниатюре не остаётся ни метаданных, ни
 * «хвостов» исходного файла.
 */
final class ImagineGenerator implements ThumbnailGeneratorInterface
{
    private ?ImagineInterface $imagine = null;

    public function isAvailable(): bool
    {
        return class_exists(\Imagick::class) || extension_loaded('gd');
    }

    public function generate(string $bytes, string $mime, int $size, int $quality): ThumbnailImage
    {
        $imagine = $this->imagine();
        try {
            $image = $imagine->load($bytes);
            if (function_exists('exif_read_data')) {
                (new Autorotate())->apply($image); // ориентация из EXIF до удаления метаданных
            }
            $image->strip();
            $thumb = $image->thumbnail(new Box($size, $size), ImageInterface::THUMBNAIL_INSET);
        } catch (Throwable $e) {
            throw new RuntimeException('Не удалось декодировать изображение: ' . $e->getMessage(), 0, $e);
        }

        $alpha = in_array($mime, ['image/png', 'image/gif', 'image/webp'], true);
        return $alpha
            ? new ThumbnailImage($thumb->get('png', ['png_compression_level' => 7]), 'image/png', 'png')
            : new ThumbnailImage($thumb->get('jpeg', ['jpeg_quality' => $quality]), 'image/jpeg', 'jpg');
    }

    private function imagine(): ImagineInterface
    {
        if ($this->imagine !== null) {
            return $this->imagine;
        }
        if (class_exists(\Imagick::class)) {
            $this->imagine = new \Imagine\Imagick\Imagine();
        } elseif (extension_loaded('gd')) {
            $this->imagine = new \Imagine\Gd\Imagine();
        } else {
            throw new RuntimeException('Миниатюры недоступны: нет ни ext-imagick, ни ext-gd.');
        }
        if (function_exists('exif_read_data')) {
            $this->imagine->setMetadataReader(new ExifMetadataReader());
        }
        return $this->imagine;
    }
}
