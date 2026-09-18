<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\mount;

use Besnovatyj\File\fs\exception\MountUnknownException;
use LogicException;

/**
 * Реестр точек монтирования: упорядоченная карта `id → Mount` плюс точка по умолчанию.
 *
 * Порядок регистрации = порядок «дисков» в корне у фронтенда. Пустой реестр допустим (например,
 * пока не настроено ни одно хранилище) — тогда корень пуст, а `defaultMount` = null.
 */
final class MountRegistry
{
    /** @var array<string, Mount> */
    private array $mounts = [];

    private ?string $defaultId;

    /**
     * @param list<Mount> $mounts
     * @param string|null $defaultId Точка по умолчанию; null — первая зарегистрированная.
     */
    public function __construct(array $mounts, ?string $defaultId = null)
    {
        foreach ($mounts as $mount) {
            if (isset($this->mounts[$mount->id])) {
                throw new LogicException("Точка монтирования '{$mount->id}' зарегистрирована дважды.");
            }
            $this->mounts[$mount->id] = $mount;
        }

        $this->defaultId = $defaultId ?? array_key_first($this->mounts);
        if ($this->defaultId !== null && !isset($this->mounts[$this->defaultId])) {
            throw new LogicException("Точка монтирования по умолчанию '{$this->defaultId}' не зарегистрирована.");
        }
    }

    /** @throws MountUnknownException */
    public function get(string $id): Mount
    {
        return $this->mounts[$id] ?? throw MountUnknownException::forId($id);
    }

    public function has(string $id): bool
    {
        return isset($this->mounts[$id]);
    }

    /** @return list<Mount> в порядке регистрации. */
    public function all(): array
    {
        return array_values($this->mounts);
    }

    public function defaultId(): ?string
    {
        return $this->defaultId;
    }
}
