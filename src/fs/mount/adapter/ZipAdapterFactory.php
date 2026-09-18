<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\mount\adapter;

use Besnovatyj\File\fs\mount\MountCapabilities;
use Besnovatyj\File\fs\mount\MountDefinition;
use InvalidArgumentException;
use League\Flysystem\ZipArchive\FilesystemZipArchiveProvider;
use League\Flysystem\ZipArchive\ZipArchiveAdapter;

/**
 * ZIP-архив как файловая система (чтение и запись через ext-zip).
 *
 * Опции: `archive` (обяз.) — путь/алиас к файлу архива.
 *
 * Особенности адаптера, отражённые в capabilities: перемещение папки одним вызовом невозможно
 * (`renameName` переименовывает одну запись), поэтому папки переносятся рекурсивно; видимость
 * записей не поддерживается; публичной отдачи нет — скачивание через `download`.
 */
final class ZipAdapterFactory implements AdapterFactoryInterface
{
    /**
     * @param callable(string): string $aliasResolver
     */
    public function __construct(private readonly mixed $aliasResolver)
    {
    }

    public function key(): string
    {
        return 'zip';
    }

    public function build(MountDefinition $definition): AdapterBuild
    {
        $archive = (string)($definition->options['archive'] ?? '');
        if ($archive === '') {
            throw new InvalidArgumentException("Точка монтирования '{$definition->id}': не задан options.archive.");
        }

        $adapter = new ZipArchiveAdapter(new FilesystemZipArchiveProvider(($this->aliasResolver)($archive)));

        return new AdapterBuild($adapter, new MountCapabilities(
            publicUrl: false,
            visibility: false,
            directories: 'native',
            search: true, // обход listContents(deep) — поиск по именам без индекса
            nativeDirectoryMove: false,
        ));
    }
}
