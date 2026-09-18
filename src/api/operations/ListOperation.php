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
use Besnovatyj\File\fs\operation\Lister;
use Besnovatyj\File\fs\operation\ListOptions;

/**
 * `list` (контракт §9.1): содержимое папки с курсорной пагинацией, сортировкой и фильтром.
 *
 * Вход: `{ path, cursor?, limit?, sort?: {by, dir}, filter?: {kinds?, nameContains?, exts?} }`.
 */
final class ListOperation implements OperationInterface
{
    public function __construct(
        private readonly Lister $lister,
        private readonly NodeSerializer $serializer,
    ) {
    }

    public function name(): string
    {
        return 'list';
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
        $path = $input->path('path');
        $sort = $input->optObject('sort');
        $filter = $input->optObject('filter');

        $direction = $sort?->optString('dir') ?? 'asc';
        if (!in_array($direction, ['asc', 'desc'], true)) {
            throw ApiException::badRequest("sort.dir должен быть 'asc' или 'desc'.", ['field' => 'sort.dir']);
        }

        $options = new ListOptions(
            cursor: $input->optString('cursor'),
            limit: $input->optInt('limit'),
            sortBy: $sort?->optString('by') ?? 'name',
            sortDesc: $direction === 'desc',
            kinds: $filter?->optStringList('kinds', 2),
            nameContains: $filter?->optString('nameContains'),
            exts: $filter === null ? null : self::lowercase($filter->optStringList('exts', 100)),
        );

        $listing = $this->lister->list($path, $options);

        return $this->serializer->listing($listing) + ['sorted' => $listing->sorted];
    }

    /**
     * @param list<string>|null $values
     * @return list<string>|null
     */
    private static function lowercase(?array $values): ?array
    {
        return $values === null ? null : array_map(strtolower(...), $values);
    }
}
