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
use Besnovatyj\File\fs\operation\ConflictStrategy;
use Besnovatyj\File\fs\operation\Transfer;

/**
 * Общая часть `move` и `copy` (контракт §9.7): вход `{ sources[], target, onConflict? }`,
 * ответ — `{ report }`. Конкретная операция задаётся флагом в конструкторе (см. DI).
 */
abstract class TransferOperation implements OperationInterface
{
    public function __construct(
        private readonly Transfer $transfer,
        private readonly NodeSerializer $serializer,
        private readonly FsLimits $limits,
    ) {
    }

    public function method(): string
    {
        return 'POST';
    }

    public function info(): array
    {
        return ['batch' => true];
    }

    abstract protected function isMove(): bool;

    public function execute(Payload $input, ApiContext $context): array|StreamResult
    {
        $sources = $input->pathList('sources', $this->limits->maxBatchItems);
        $target = $input->path('target');
        $strategy = ConflictStrategy::fromInput($input->optString('onConflict'));

        $report = $this->isMove()
            ? $this->transfer->move($sources, $target, $strategy)
            : $this->transfer->copy($sources, $target, $strategy);

        return ['report' => $this->serializer->report($report)];
    }
}
