<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\api;

use Besnovatyj\File\fs\node\Node;
use Besnovatyj\File\fs\operation\Listing;
use Besnovatyj\File\fs\operation\report\ItemResult;
use Besnovatyj\File\fs\operation\report\ItemStatus;
use Besnovatyj\File\fs\operation\report\OperationReport;

/**
 * Домен → JSON-структуры контракта. Единственное место, где известна форма `Node`, `Listing` и
 * `OperationReport` на проводе (§5, §7, §9.1). Меняется только вместе с контрактом.
 */
final class NodeSerializer
{
    /**
     * @return array<string, mixed>
     */
    public function node(Node $node): array
    {
        $data = [
            'path' => $node->path->toString(),
            'name' => $node->name(),
            'kind' => $node->kind->value,
            'mount' => $node->path->mount,
            'size' => $node->size,
            'mtime' => $node->mtime,
            'mime' => $node->mime,
            'ext' => $node->extension(),
            'url' => $node->url,
            'visibility' => $node->visibility,
        ];
        if ($node->perms !== null) {
            $data['perms'] = $node->perms;
        }
        if ($node->meta !== []) {
            $data['meta'] = $node->meta;
        }
        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function listing(Listing $listing): array
    {
        return [
            'node' => $this->node($listing->node),
            'items' => array_map($this->node(...), $listing->items),
            'nextCursor' => $listing->nextCursor,
            'total' => $listing->total,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function report(OperationReport $report): array
    {
        return [
            'operation' => $report->operation->value,
            'total' => $report->total(),
            'succeeded' => $report->count(ItemStatus::Ok),
            'failed' => $report->count(ItemStatus::Failed),
            'skipped' => $report->count(ItemStatus::Skipped),
            'items' => array_map($this->item(...), $report->items()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function item(ItemResult $item): array
    {
        $data = [
            'source' => $item->source,
            'status' => $item->status->value,
        ];
        if ($item->target !== null) {
            $data['target'] = $item->target;
        }
        if ($item->node !== null) {
            $data['node'] = $this->node($item->node);
        }
        if ($item->error !== null) {
            $data['error'] = ApiException::fromFs($item->error)->toArray();
        }
        return $data;
    }
}
