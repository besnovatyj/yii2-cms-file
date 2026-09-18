<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\mount\adapter;

use Besnovatyj\File\fs\mount\MountCapabilities;
use League\Flysystem\FilesystemAdapter;

/**
 * Результат работы фабрики адаптера: сам адаптер + профиль возможностей, который он гарантирует.
 */
final class AdapterBuild
{
    public function __construct(
        public readonly FilesystemAdapter $adapter,
        public readonly MountCapabilities $capabilities,
    ) {
    }
}
