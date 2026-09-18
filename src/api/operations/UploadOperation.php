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
use Besnovatyj\File\fs\operation\ConflictStrategy;
use Besnovatyj\File\fs\operation\Uploader;

/**
 * `upload` (контракт §9.9): multipart с полями `path`, `file`, `name?`, `onConflict?`.
 * Ответ: `{ node, renamed, requestedName }`.
 */
final class UploadOperation implements OperationInterface
{
    public function __construct(
        private readonly Uploader $uploader,
        private readonly NodeSerializer $serializer,
    ) {
    }

    public function name(): string
    {
        return 'upload';
    }

    public function method(): string
    {
        return 'POST';
    }

    public function info(): array
    {
        return ['multipart' => true];
    }

    public function execute(Payload $input, ApiContext $context): array|StreamResult
    {
        $result = $this->uploader->upload(
            $input->path('path'),
            $input->file('file'),
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
