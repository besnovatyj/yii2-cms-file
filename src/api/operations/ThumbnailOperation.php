<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\api\operations;

use Besnovatyj\File\api\ApiContext;
use Besnovatyj\File\api\OperationInterface;
use Besnovatyj\File\api\Payload;
use Besnovatyj\File\api\StreamResult;
use Besnovatyj\File\fs\thumbnail\Thumbnailer;

/**
 * `thumbnail` (контракт §9.14): GET `?path=…&size=128` — миниатюра изображения (вписана в квадрат
 * `size`; допустимые размеры — `describe.thumbnails.sizes`, иные округляются вверх). `v` в query —
 * версия файла для кэша браузера, сервером игнорируется.
 */
final class ThumbnailOperation implements OperationInterface
{
    public const int DEFAULT_SIZE = 128;

    public function __construct(private readonly Thumbnailer $thumbnailer)
    {
    }

    public function name(): string
    {
        return 'thumbnail';
    }

    public function method(): string
    {
        return 'GET';
    }

    public function info(): array
    {
        return [];
    }

    public function execute(Payload $input, ApiContext $context): array|StreamResult
    {
        $size = $input->optInt('size') ?? self::DEFAULT_SIZE;
        return new StreamResult($this->thumbnailer->open($input->path('path'), max(16, min(2048, $size))));
    }
}
