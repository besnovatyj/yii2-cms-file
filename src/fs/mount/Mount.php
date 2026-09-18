<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\mount;

use Besnovatyj\File\fs\exception\ForbiddenException;
use Besnovatyj\File\fs\exception\UnsupportedException;
use Besnovatyj\File\fs\mount\url\PublicUrlResolverInterface;
use Besnovatyj\File\fs\operation\FsOperation;
use League\Flysystem\Filesystem;

/**
 * Точка монтирования — «диск» виртуальной ФС.
 *
 * Связывает идентификатор (сегмент пути), Flysystem-файловую систему, физические возможности
 * ({@see MountCapabilities}), административный флаг {@see $readOnly} и стратегию публичных URL.
 * Реальное расположение (каталог, архив, бакет) скрыто внутри адаптера и наружу не отдаётся.
 *
 * Метод {@see assertSupports()} — единственная точка, где домен решает «можно ли вообще выполнить
 * операцию в этом хранилище»; сервисы операций зовут его первым делом.
 */
final class Mount
{
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly ?string $icon,
        private readonly Filesystem $filesystem,
        public readonly MountCapabilities $capabilities,
        public readonly bool $readOnly,
        private readonly PublicUrlResolverInterface $urlResolver,
    ) {
    }

    public function filesystem(): Filesystem
    {
        return $this->filesystem;
    }

    /** Публичный URL файла по относительному пути; null — публичной отдачи нет. */
    public function urlFor(string $relative): ?string
    {
        return $this->capabilities->publicUrl ? $this->urlResolver->urlFor($relative) : null;
    }

    /** Поддерживается ли операция (capabilities + readOnly). */
    public function supports(FsOperation $operation): bool
    {
        if ($operation->isMutating() && $this->readOnly) {
            return false;
        }
        return $this->capabilities->supports($operation);
    }

    /**
     * @throws UnsupportedException хранилище физически не умеет операцию;
     * @throws ForbiddenException хранилище только для чтения.
     */
    public function assertSupports(FsOperation $operation): void
    {
        if (!$this->capabilities->supports($operation)) {
            throw UnsupportedException::operation($operation->value, $this->id);
        }
        if ($operation->isMutating() && $this->readOnly) {
            throw ForbiddenException::readOnlyMount($this->id);
        }
    }

    /** Представление для `describe.mounts[]` (контракт §6). */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'icon' => $this->icon,
            'root' => '/' . $this->id,
            'readOnly' => $this->readOnly,
            'capabilities' => $this->capabilities->toArray(),
        ];
    }
}
