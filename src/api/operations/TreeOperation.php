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
use Besnovatyj\File\fs\operation\Lister;

/** `tree` (контракт §9.2): дочерние папки для ленивого дерева навигации. Вход: `{ path }`. */
final class TreeOperation implements OperationInterface
{
    public function __construct(
        private readonly Lister $lister,
        private readonly NodeSerializer $serializer,
    ) {
    }

    public function name(): string
    {
        return 'tree';
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
        $listing = $this->lister->tree($input->path('path'));

        return [
            'node' => $this->serializer->node($listing->node),
            'items' => array_map($this->serializer->node(...), $listing->items),
        ];
    }
}
