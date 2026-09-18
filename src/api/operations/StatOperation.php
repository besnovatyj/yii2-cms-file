<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\api\operations;

use Besnovatyj\File\api\ApiContext;
use Besnovatyj\File\api\NodeSerializer;
use Besnovatyj\File\api\OperationInterface;
use Besnovatyj\File\api\Payload;
use Besnovatyj\File\api\StreamResult;
use Besnovatyj\File\fs\operation\Inspector;

/** `stat` (контракт §9.3): точные метаданные узла. Вход: `{ path }`. */
final class StatOperation implements OperationInterface
{
    public function __construct(
        private readonly Inspector $inspector,
        private readonly NodeSerializer $serializer,
    ) {
    }

    public function name(): string
    {
        return 'stat';
    }

    public function method(): string
    {
        return 'POST';
    }

    public function info(): array
    {
        return [];
    }

    public function execute(Payload $input, ApiContext $context): array|StreamResult
    {
        return ['node' => $this->serializer->node($this->inspector->stat($input->path('path')))];
    }
}
