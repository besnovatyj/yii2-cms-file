<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\storage;

use Besnovatyj\File\storage\exceptions\UnknownMountException;
use LogicException;

/**
 * Реестр точек монтирования — чистые данные: `id → StorageMount` плюс точка монтирования по умолчанию.
 *
 * Конфигурируется декларативно в `config/container.php`. Добавление нового корня (локальный каталог,
 * AWS S3, FTP-сервер и т.д.) сводится к регистрации ещё одного {@see StorageMount} здесь — остальной
 * код (контроллёры, сервис, фронтенд) не меняется.
 */
final class MountRegistry
{
    /** @var array<string, StorageMount> */
    private array $mounts = [];

    private string $defaultId;

    /**
     * @param StorageMount[] $mounts Список точек монтирования (минимум одна).
     * @param string|null $defaultId Точка монтирования по умолчанию; по умолчанию — первая в списке.
     */
    public function __construct(array $mounts, ?string $defaultId = null)
    {
        foreach ($mounts as $mount) {
            $this->mounts[$mount->id] = $mount;
        }
        if ($this->mounts === []) {
            throw new LogicException('MountRegistry: не задано ни одной точки монтирования.');
        }

        $this->defaultId = $defaultId ?? array_key_first($this->mounts);
        if (!isset($this->mounts[$this->defaultId])) {
            throw new LogicException("MountRegistry: точка монтирования по умолчанию '{$this->defaultId}' не зарегистрирована.");
        }
    }

    /** @throws UnknownMountException */
    public function get(string $id): StorageMount
    {
        return $this->mounts[$id]
            ?? throw new UnknownMountException("Неизвестная точка монтирования: '{$id}'");
    }

    public function has(string $id): bool
    {
        return isset($this->mounts[$id]);
    }

    /** @return StorageMount[] */
    public function all(): array
    {
        return array_values($this->mounts);
    }

    public function getDefault(): StorageMount
    {
        return $this->mounts[$this->defaultId];
    }

    public function getDefaultId(): string
    {
        return $this->defaultId;
    }
}
