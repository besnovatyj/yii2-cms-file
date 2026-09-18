<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\mount\adapter;

use InvalidArgumentException;

/**
 * Реестр фабрик адаптеров по ключу типа. Заполняется в DI (config/container.php);
 * добавление нового типа хранилища = ещё одна фабрика в списке.
 */
final class AdapterFactoryRegistry
{
    /** @var array<string, AdapterFactoryInterface> */
    private array $factories = [];

    /**
     * @param iterable<AdapterFactoryInterface> $factories
     */
    public function __construct(iterable $factories = [])
    {
        foreach ($factories as $factory) {
            $this->register($factory);
        }
    }

    public function register(AdapterFactoryInterface $factory): void
    {
        $this->factories[$factory->key()] = $factory;
    }

    /** @throws InvalidArgumentException неизвестный тип адаптера (ошибка конфигурации). */
    public function get(string $key): AdapterFactoryInterface
    {
        return $this->factories[$key]
            ?? throw new InvalidArgumentException(
                "Неизвестный тип адаптера хранилища: '{$key}'. Зарегистрированы: "
                . implode(', ', array_keys($this->factories)) . '.'
            );
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->factories);
    }
}
