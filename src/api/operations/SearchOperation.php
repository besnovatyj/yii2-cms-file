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
use Besnovatyj\File\fs\node\Node;
use Besnovatyj\File\fs\operation\Searcher;
use Besnovatyj\File\fs\operation\SearchOptions;

/**
 * `search` (контракт §9.15): поиск по именам в поддереве.
 *
 * Вход: `{ path, query, recursive?: true, kinds?: ('file'|'dir')[], limit? }`.
 * Выход: `{ node, items: Node[], truncated: boolean, scanned: number }`.
 * Область (`X-Fs-Scope`) проверяется на `path`; всё найденное лежит под ним, значит внутри области.
 */
final class SearchOperation implements OperationInterface
{
    public function __construct(
        private readonly Searcher $searcher,
        private readonly NodeSerializer $serializer,
    ) {
    }

    public function name(): string
    {
        return 'search';
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
        $result = $this->searcher->search($input->path('path'), new SearchOptions(
            query: $input->nonEmptyString('query'),
            recursive: $input->optBool('recursive') ?? true,
            kinds: $input->optStringList('kinds', 2),
            limit: $input->optInt('limit'),
        ));

        return [
            'node' => $this->serializer->node($result->node),
            'items' => array_map(fn(Node $n): array => $this->serializer->node($n), $result->items),
            'truncated' => $result->truncated,
            'scanned' => $result->scanned,
        ];
    }
}
