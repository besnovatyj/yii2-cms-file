<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\mount;

use Besnovatyj\File\fs\mount\adapter\AdapterFactoryRegistry;
use Besnovatyj\File\fs\mount\url\BaseUrlResolver;
use Besnovatyj\File\fs\mount\url\NoPublicUrlResolver;
use League\Flysystem\Filesystem;

/**
 * Собирает {@see Mount} из {@see MountDefinition}: адаптер — через реестр фабрик, резолвер URL —
 * по наличию `baseUrl`. Единственное место, где описание превращается в живой объект.
 */
final class MountFactory
{
    /**
     * @param callable(string): string $aliasResolver Разрешение алиасов в baseUrl ('@staticHostName').
     * @param bool $thumbnails Доступны ли серверные миниатюры (генератор есть и включён) —
     *                         тогда capability `thumbnail` включается у всех mount'ов с `download`.
     */
    public function __construct(
        private readonly AdapterFactoryRegistry $adapters,
        private readonly mixed $aliasResolver,
        private readonly bool $thumbnails = false,
    ) {
    }

    public function make(MountDefinition $definition): Mount
    {
        $build = $this->adapters->get($definition->adapter)->build($definition);

        $baseUrl = $definition->baseUrl === '' ? '' : ($this->aliasResolver)($definition->baseUrl);
        $urlResolver = $baseUrl === '' ? new NoPublicUrlResolver() : new BaseUrlResolver($baseUrl);

        return new Mount(
            id: $definition->id,
            label: $definition->label !== '' ? $definition->label : $definition->id,
            icon: $definition->icon,
            filesystem: new Filesystem($build->adapter),
            capabilities: $build->capabilities->with(
                publicUrl: $baseUrl !== '' && $build->capabilities->publicUrl,
                thumbnail: $this->thumbnails && $build->capabilities->download,
            ),
            readOnly: $definition->readOnly,
            urlResolver: $urlResolver,
        );
    }
}
