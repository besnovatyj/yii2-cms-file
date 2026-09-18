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
use Besnovatyj\File\fs\archive\Archiver;
use Besnovatyj\File\fs\FsLimits;
use Besnovatyj\File\fs\operation\ConflictStrategy;

/**
 * `archive` (контракт §9.16): собрать ZIP из файлов и папок в папке назначения.
 *
 * Вход: `{ paths: string[], target: string, name?: string, onConflict?: ConflictStrategy }`.
 * Выход — как у `upload`: `{ node, renamed, requestedName }`.
 */
final class ArchiveOperation implements OperationInterface
{
    public function __construct(
        private readonly Archiver $archiver,
        private readonly NodeSerializer $serializer,
        private readonly FsLimits $limits,
    ) {
    }

    public function name(): string
    {
        return 'archive';
    }

    public function method(): string
    {
        return 'POST';
    }

    public function info(): array
    {
        return ['formats' => ['zip']];
    }

    public function execute(Payload $input, ApiContext $context): array|StreamResult
    {
        $result = $this->archiver->archive(
            $input->pathList('paths', $this->limits->maxBatchItems),
            $input->path('target'),
            $input->optString('name'),
            ConflictStrategy::fromInput($input->optString('onConflict')),
        );

        return [
            'node' => $this->serializer->node($result->node),
            'renamed' => $result->renamed,
            'requestedName' => $result->requestedName,
        ];
    }
}
