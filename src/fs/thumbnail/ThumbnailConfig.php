<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\thumbnail;

/**
 * Настройки серверных миниатюр (`params.fs.thumbnails`).
 *
 * Размеры — закрытый список: произвольный `size` из query позволил бы засорить кэш тысячами
 * вариантов одного файла. Клиент выбирает ближайший больший размер к нужному (с учётом DPR).
 */
final class ThumbnailConfig
{
    /**
     * @param bool $enabled Включены ли миниатюры (требует ext-imagick или ext-gd).
     * @param string $dir Каталог кэша (разрешённый алиас).
     * @param list<int> $sizes Допустимые размеры (сторона квадрата, в который вписывается изображение).
     * @param int $quality Качество JPEG/WebP.
     * @param int $maxPixels Максимум пикселей исходника (защита памяти при декодировании).
     * @param int $ttl Срок хранения миниатюры в кэше, секунд (чистка по cron).
     */
    public function __construct(
        public readonly bool $enabled = true,
        public readonly string $dir = '',
        public readonly array $sizes = [64, 128, 256, 512],
        public readonly int $quality = 82,
        public readonly int $maxPixels = 40_000_000,
        public readonly int $ttl = 7 * 86_400,
    ) {
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config, string $dir): self
    {
        $d = new self();
        $sizes = array_values(array_unique(array_filter(array_map(intval(...), (array)($config['sizes'] ?? $d->sizes)), static fn(int $s): bool => $s >= 16 && $s <= 2048)));
        sort($sizes);
        return new self(
            enabled: (bool)($config['enabled'] ?? $d->enabled),
            dir: $dir,
            sizes: $sizes === [] ? $d->sizes : $sizes,
            quality: min(100, max(1, (int)($config['quality'] ?? $d->quality))),
            maxPixels: max(1_000_000, (int)($config['maxPixels'] ?? $d->maxPixels)),
            ttl: max(3600, (int)($config['ttl'] ?? $d->ttl)),
        );
    }

    /** Ближайший допустимый размер не меньше запрошенного (или максимальный). */
    public function normalizeSize(int $requested): int
    {
        foreach ($this->sizes as $size) {
            if ($size >= $requested) {
                return $size;
            }
        }
        return $this->sizes[count($this->sizes) - 1];
    }

    /** Часть для `describe.thumbnails`. */
    public function toArray(): array
    {
        return ['sizes' => $this->sizes];
    }
}
