<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\operation;

use Besnovatyj\File\fs\exception\InvalidOperationException;
use Besnovatyj\File\fs\exception\StorageFailureException;
use Besnovatyj\File\fs\FsLimits;
use Besnovatyj\File\fs\node\Node;
use Besnovatyj\File\fs\node\NodeFactory;
use Besnovatyj\File\fs\node\NodeKind;
use Besnovatyj\File\fs\path\PathResolver;
use Besnovatyj\File\fs\path\VirtualPath;
use League\Flysystem\FilesystemException;

/**
 * Листинг папок: `list` (все дети, с фильтром/сортировкой/пагинацией) и `tree` (только папки).
 *
 * Пагинация курсорная поверх полного листинга адаптера: у Flysystem нет нативной постраничной
 * выдачи, а для стабильных страниц нужна детерминированная сортировка — поэтому сортируем на
 * сервере всегда, даже если клиент отсортирует ещё раз. Курсор — непрозрачная строка
 * (внутри — смещение), клиент её не интерпретирует.
 */
final class Lister
{
    public function __construct(
        private readonly PathResolver $resolver,
        private readonly NodeFactory $nodes,
        private readonly FsLimits $limits,
    ) {
    }

    public function list(VirtualPath $directory, ListOptions $options): Listing
    {
        if ($directory->isRoot()) {
            // Порядок «дисков» в корне = порядок регистрации (административный), не алфавитный.
            $node = $this->nodes->rootNode($this->resolver->mounts());
            $items = array_map($this->nodes->mountNode(...), $this->resolver->mounts()->all());
            return $this->paginate($node, $items, $options, sort: false);
        }

        $target = $this->resolver->resolveDirectory($directory);
        $target->mount->assertSupports(FsOperation::List);

        $items = [];
        try {
            foreach ($target->filesystem()->listContents($target->location()) as $attributes) {
                $items[] = $this->nodes->fromAttributes($target->mount, $attributes);
            }
        } catch (FilesystemException $e) {
            throw StorageFailureException::wrap($e, $directory->toString(), 'list');
        }

        $node = $this->nodes->fromPath($target->mount, $directory);

        return $this->paginate($node, $this->filter($items, $options), $options);
    }

    /** Только контейнеры (папки/точки монтирования) — для ленивого дерева навигации. */
    public function tree(VirtualPath $directory): Listing
    {
        $listing = $this->list($directory, new ListOptions(kinds: ['dir'], all: true));
        $items = array_map(
            static fn(Node $n): Node => $n->withMeta(['hasChildren' => $n->meta['hasChildren'] ?? null]),
            $listing->items,
        );
        return new Listing($listing->node, $items, null, count($items));
    }

    /**
     * @param list<Node> $items
     * @return list<Node>
     */
    private function filter(array $items, ListOptions $options): array
    {
        if ($options->kinds === null && $options->nameContains === null && $options->exts === null) {
            return $items;
        }
        $needle = $options->nameContains === null ? null : mb_strtolower($options->nameContains);

        return array_values(array_filter($items, static function (Node $node) use ($options, $needle): bool {
            if ($options->kinds !== null) {
                $kind = $node->kind === NodeKind::File ? 'file' : 'dir';
                if (!in_array($kind, $options->kinds, true)) {
                    return false;
                }
            }
            if ($needle !== null && !str_contains(mb_strtolower($node->name()), $needle)) {
                return false;
            }
            if ($options->exts !== null && $node->kind === NodeKind::File
                && !in_array($node->extension() ?? '', $options->exts, true)) {
                return false;
            }
            return true;
        }));
    }

    /**
     * @param list<Node> $items
     */
    private function paginate(Node $node, array $items, ListOptions $options, bool $sort = true): Listing
    {
        if ($sort) {
            usort($items, self::comparator($options));
        }

        $total = count($items);
        if ($options->all) {
            return new Listing($node, array_values($items), null, $total, $sort);
        }

        $offset = self::decodeCursor($options->cursor);
        // Клиент может попросить страницу меньше серверной, но не больше.
        $limit = $options->limit === null
            ? $this->limits->listPageSize
            : min($options->limit, $this->limits->listPageSize);

        $page = array_slice($items, $offset, $limit);
        $next = $offset + $limit < $total ? self::encodeCursor($offset + $limit) : null;

        return new Listing($node, array_values($page), $next, $total, $sort);
    }

    /**
     * Папки всегда выше файлов (как в проводнике), внутри групп — по ключу с natural-сравнением имён.
     *
     * @return callable(Node, Node): int
     */
    private static function comparator(ListOptions $options): callable
    {
        $dir = $options->sortDesc ? -1 : 1;
        return static function (Node $a, Node $b) use ($options, $dir): int {
            $ka = $a->isContainer() ? 0 : 1;
            $kb = $b->isContainer() ? 0 : 1;
            if ($ka !== $kb) {
                return $ka <=> $kb;
            }
            $cmp = match ($options->sortBy) {
                'size' => ($a->size ?? -1) <=> ($b->size ?? -1),
                'mtime' => ($a->mtime ?? 0) <=> ($b->mtime ?? 0),
                'ext' => strcmp($a->extension() ?? '', $b->extension() ?? ''),
                default => 0,
            };
            if ($cmp === 0) {
                $cmp = strnatcasecmp($a->name(), $b->name());
            }
            return $cmp * $dir;
        };
    }

    private static function encodeCursor(int $offset): string
    {
        return rtrim(strtr(base64_encode('o:' . $offset), '+/', '-_'), '=');
    }

    /** @throws InvalidOperationException курсор повреждён. */
    private static function decodeCursor(?string $cursor): int
    {
        if ($cursor === null || $cursor === '') {
            return 0;
        }
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($decoded === false || preg_match('/^o:(\d{1,9})$/', $decoded, $m) !== 1) {
            throw new InvalidOperationException('Некорректный курсор пагинации.', null, ['cursor' => $cursor]);
        }
        return (int)$m[1];
    }
}
