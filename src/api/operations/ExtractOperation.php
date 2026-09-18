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
use Besnovatyj\File\fs\operation\ConflictStrategy;

/**
 * `extract` (контракт §9.17): распаковать ZIP в папку.
 *
 * Вход: `{ path: string, target?: string, onConflict?: ConflictStrategy }` — без `target` рядом с
 * архивом создаётся папка с его именем. Выход: `{ node, total, extracted, skipped: [{name, code, message}] }`.
 */
final class ExtractOperation implements OperationInterface
{
    public function __construct(
        private readonly Archiver $archiver,
        private readonly NodeSerializer $serializer,
    ) {
    }

    public function name(): string
    {
        return 'extract';
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
        $result = $this->archiver->extract(
            $input->path('path'),
            $input->has('target') ? $input->path('target') : null,
            ConflictStrategy::fromInput($input->optString('onConflict')),
        );

        return [
            'node' => $this->serializer->node($result->node),
            'total' => $result->total,
            'extracted' => $result->extracted,
            'skipped' => $result->skipped,
        ];
    }
}
