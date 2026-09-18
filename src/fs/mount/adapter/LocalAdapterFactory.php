<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\mount\adapter;

use Besnovatyj\File\fs\mount\MountCapabilities;
use Besnovatyj\File\fs\mount\MountDefinition;
use InvalidArgumentException;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;
use League\Flysystem\Visibility;

/**
 * Локальный каталог на диске сервера.
 *
 * Опции (`options`):
 *  - `root` (обяз.) — абсолютный путь либо Yii-алиас ('@static'); резолвится через {@see $aliasResolver};
 *  - `permissions` — массив прав для PortableVisibilityConverter::fromArray() (необязательно).
 *
 * Безопасность: символические ссылки ПРОПУСКАЮТСЯ (`SKIP_LINKS`) — ссылка, ведущая наружу корня,
 * иначе стала бы обходом (ADR-12). Отдельного realpath-контроля не требуется: корень задан адаптеру,
 * а пути внутри проверены {@see \Besnovatyj\File\fs\path\VirtualPath} и PathNormalizer Flysystem.
 */
final class LocalAdapterFactory implements AdapterFactoryInterface
{
    /**
     * @param callable(string): string $aliasResolver Разрешение алиасов в пути (в Yii — Yii::getAlias).
     */
    public function __construct(private readonly mixed $aliasResolver)
    {
    }

    public function key(): string
    {
        return 'local';
    }

    public function build(MountDefinition $definition): AdapterBuild
    {
        $root = (string)($definition->options['root'] ?? '');
        if ($root === '') {
            throw new InvalidArgumentException("Точка монтирования '{$definition->id}': не задан options.root.");
        }
        $root = ($this->aliasResolver)($root);

        $visibility = isset($definition->options['permissions'])
            ? PortableVisibilityConverter::fromArray((array)$definition->options['permissions'])
            : PortableVisibilityConverter::fromArray([], Visibility::PUBLIC);

        $adapter = new LocalFilesystemAdapter(
            $root,
            $visibility,
            LOCK_EX,
            LocalFilesystemAdapter::SKIP_LINKS,
        );

        return new AdapterBuild($adapter, new MountCapabilities(
            publicUrl: $definition->baseUrl !== '',
            visibility: true,
            directories: 'native',
            search: true, // обход listContents(deep) — поиск по именам без индекса
            nativeDirectoryMove: true,
        ));
    }
}
