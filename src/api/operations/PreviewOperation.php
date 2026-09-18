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
use Besnovatyj\File\fs\operation\Previewer;

/**
 * `preview` (контракт §9.13): GET `?path=…` — inline-отдача растрового изображения для панели
 * предпросмотра. Только для файлов, распознанных по содержимому как изображения; иначе `unsupported`.
 */
final class PreviewOperation implements OperationInterface
{
    public function __construct(private readonly Previewer $previewer)
    {
    }

    public function name(): string
    {
        return 'preview';
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
        return new StreamResult($this->previewer->open($input->path('path')));
    }
}
