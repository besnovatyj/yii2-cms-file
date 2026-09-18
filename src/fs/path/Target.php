<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\path;

use Besnovatyj\File\fs\mount\Mount;
use League\Flysystem\Filesystem;

/**
 * Разрешённый адрес: виртуальный путь + его точка монтирования + location для Flysystem.
 * Результат {@see PathResolver::resolve()}; сервисы операций работают с ним, а не с сырыми строками.
 */
final class Target
{
    public function __construct(
        public readonly VirtualPath $path,
        public readonly Mount $mount,
    ) {
    }

    public function filesystem(): Filesystem
    {
        return $this->mount->filesystem();
    }

    /** Путь для Flysystem: относительно корня mount'а, '' — корень. */
    public function location(): string
    {
        return $this->path->relative();
    }

    /** Дочерний адрес в той же точке монтирования. */
    public function child(string $name): self
    {
        return new self($this->path->child($name), $this->mount);
    }

    public function exists(): bool
    {
        return $this->location() === '' || $this->filesystem()->has($this->location());
    }

    public function isDirectory(): bool
    {
        return $this->location() === '' || $this->filesystem()->directoryExists($this->location());
    }

    public function isFile(): bool
    {
        return $this->location() !== '' && $this->filesystem()->fileExists($this->location());
    }
}
