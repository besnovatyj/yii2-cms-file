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
use Besnovatyj\File\fs\operation\DirectoryCreator;

/** `mkdir` (контракт §9.5). Вход: `{ parent, name }`. */
final class MkdirOperation implements OperationInterface
{
    public function __construct(
        private readonly DirectoryCreator $directories,
        private readonly NodeSerializer $serializer,
    ) {
    }

    public function name(): string
    {
        return 'mkdir';
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
        $node = $this->directories->create($input->path('parent'), $input->nonEmptyString('name'));

        return ['node' => $this->serializer->node($node)];
    }
}
