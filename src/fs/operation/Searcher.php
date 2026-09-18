<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\operation;

use Besnovatyj\File\fs\exception\StorageFailureException;
use Besnovatyj\File\fs\FsLimits;
use Besnovatyj\File\fs\mount\Mount;
use Besnovatyj\File\fs\node\Node;
use Besnovatyj\File\fs\node\NodeFactory;
use Besnovatyj\File\fs\node\NodeKind;
use Besnovatyj\File\fs\path\PathResolver;
use Besnovatyj\File\fs\path\VirtualPath;
use League\Flysystem\FilesystemException;

/**
 * Поиск по именам (контракт §9.15): обход поддерева через `listContents(deep)` адаптера и
 * сопоставление имён с запросом. Индекса нет — это «поиск как в проводнике»: честный обход с
 * жёсткими бюджетами, чтобы один запрос не мог занять сервер надолго:
 *
 *  - `limits.searchMaxEntries` — сколько записей хранилища можно просмотреть;
 *  - `limits.searchMaxResults` — сколько совпадений вернуть.
 *
 * При исчерпании любого бюджета обход прекращается, а ответ помечается `truncated` — клиент
 * показывает «показаны первые N», а не делает вид, что нашёл всё. Поиск в виртуальном корне `/`
 * идёт по всем хранилищам с capability `search`, бюджеты — общие.
 */
final class Searcher
{
    public function __construct(
        private readonly PathResolver $resolver,
        private readonly NodeFactory $nodes,
        private readonly FsLimits $limits,
    ) {
    }

    public function search(VirtualPath $root, SearchOptions $options): SearchResult
    {
        $matcher = new NameMatcher($options->query);
        $maxResults = $options->limit === null
            ? $this->limits->searchMaxResults
            : min($options->limit, $this->limits->searchMaxResults);
        $budget = new SearchBudget($this->limits->searchMaxEntries, $maxResults);

        if ($root->isRoot()) {
            $rootNode = $this->nodes->rootNode($this->resolver->mounts());
            $items = [];
            foreach ($this->resolver->mounts()->all() as $mount) {
                if (!$mount->capabilities->supports(FsOperation::Search)) {
                    continue;
                }
                array_push($items, ...$this->walk($mount, '', $root, $matcher, $options, $budget));
                if ($budget->exhausted()) {
                    break;
                }
            }
        } else {
            $target = $this->resolver->resolveDirectory($root);
            $target->mount->assertSupports(FsOperation::Search);
            $rootNode = $this->nodes->fromPath($target->mount, $root);
            $items = $this->walk($target->mount, $target->location(), $root, $matcher, $options, $budget);
        }

        usort($items, self::comparator(...));

        return new SearchResult($rootNode, array_values($items), $budget->exhausted(), $budget->scanned);
    }

    /**
     * @return list<Node>
     */
    private function walk(Mount $mount, string $location, VirtualPath $root, NameMatcher $matcher, SearchOptions $options, SearchBudget $budget): array
    {
        $found = [];
        try {
            foreach ($mount->filesystem()->listContents($location, $options->recursive) as $attributes) {
                if (!$budget->consumeEntry()) {
                    break;
                }
                $node = $this->nodes->fromAttributes($mount, $attributes);
                if ($options->kinds !== null) {
                    $kind = $node->kind === NodeKind::File ? 'file' : 'dir';
                    if (!in_array($kind, $options->kinds, true)) {
                        continue;
                    }
                }
                if (!$matcher->matches($node->name())) {
                    continue;
                }
                $found[] = $node;
                if (!$budget->consumeResult()) {
                    break;
                }
            }
        } catch (FilesystemException $e) {
            throw StorageFailureException::wrap($e, $root->toString(), 'search');
        }
        return $found;
    }

    /** Папки выше файлов, внутри — по полному пути (natural, без регистра): соседи из одной папки рядом. */
    private static function comparator(Node $a, Node $b): int
    {
        $ka = $a->isContainer() ? 0 : 1;
        $kb = $b->isContainer() ? 0 : 1;
        return $ka !== $kb ? $ka <=> $kb : strnatcasecmp($a->path->toString(), $b->path->toString());
    }
}
