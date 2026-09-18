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
use Besnovatyj\File\fs\FsLimits;
use Besnovatyj\File\fs\operation\Deleter;

/** `delete` (контракт §9.8). Вход: `{ paths[] }`, ответ — `{ report }`. */
final class DeleteOperation implements OperationInterface
{
    public function __construct(
        private readonly Deleter $deleter,
        private readonly NodeSerializer $serializer,
        private readonly FsLimits $limits,
    ) {
    }

    public function name(): string
    {
        return 'delete';
    }

    public function method(): string
    {
        return 'POST';
    }

    public function info(): array
    {
        return ['batch' => true];
    }

    public function execute(Payload $input, ApiContext $context): array|StreamResult
    {
        $report = $this->deleter->delete($input->pathList('paths', $this->limits->maxBatchItems));

        return ['report' => $this->serializer->report($report)];
    }
}
