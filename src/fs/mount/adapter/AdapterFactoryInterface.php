<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\mount\adapter;

use Besnovatyj\File\fs\mount\MountDefinition;

/**
 * Фабрика Flysystem-адаптера для одного типа хранилища.
 *
 * Точка расширения «новый тип корня»: класс, реализующий этот интерфейс, регистрируется в
 * {@see AdapterFactoryRegistry} под своим ключом (`'local'`, `'zip'`, `'s3'`, `'ftp'`, …) — больше
 * ничего в системе менять не нужно. Фабрика единственная знает специфику адаптера, поэтому именно
 * она объявляет его физические возможности ({@see AdapterBuild::$capabilities}).
 */
interface AdapterFactoryInterface
{
    /** Ключ типа адаптера, под которым он указывается в {@see MountDefinition::$adapter}. */
    public function key(): string;

    /**
     * Собирает адаптер и его профиль возможностей по описанию точки монтирования.
     *
     * @throws \InvalidArgumentException описание неполное/некорректное (ошибка конфигурации).
     */
    public function build(MountDefinition $definition): AdapterBuild;
}
