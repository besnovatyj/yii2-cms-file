<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\api\operations;

use Besnovatyj\File\api\ApiContext;
use Besnovatyj\File\api\ApiException;
use Besnovatyj\File\api\NodeSerializer;
use Besnovatyj\File\api\OperationInterface;
use Besnovatyj\File\api\Payload;
use Besnovatyj\File\api\StreamResult;
use Besnovatyj\File\fs\operation\Inspector;

/** `content` (контракт §9.4): текстовое содержимое файла для предпросмотра. Вход: `{ path, maxBytes? }`. */
final class ContentOperation implements OperationInterface
{
    public function __construct(
        private readonly Inspector $inspector,
        private readonly NodeSerializer $serializer,
    ) {
    }

    public function name(): string
    {
        return 'content';
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
        $maxBytes = $input->optInt('maxBytes');
        if ($maxBytes !== null && $maxBytes < 1) {
            throw ApiException::badRequest('maxBytes должен быть положительным.', ['field' => 'maxBytes']);
        }

        $result = $this->inspector->content($input->path('path'), $maxBytes);

        return [
            'node' => $this->serializer->node($result->node),
            'content' => $result->content,
            'encoding' => 'utf-8',
            'truncated' => $result->truncated,
            'binary' => $result->binary,
        ];
    }
}
