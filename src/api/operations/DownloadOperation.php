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
use Besnovatyj\File\fs\operation\Downloader;

/**
 * `download` (контракт §9.10): GET `?path=…`, отдаёт файл потоком. Единственная операция с методом
 * GET — её открывают навигацией браузера (`<a href>`), а не XHR. Заголовки безопасности выставляет
 * адаптер.
 */
final class DownloadOperation implements OperationInterface
{
    public function __construct(private readonly Downloader $downloader)
    {
    }

    public function name(): string
    {
        return 'download';
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
        return new StreamResult($this->downloader->open($input->path('path')));
    }
}
