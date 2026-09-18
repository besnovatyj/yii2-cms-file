<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\api;

use LogicException;

/**
 * Реестр операций API: `имя → операция`, в порядке регистрации.
 *
 * Заполняется в DI (config/container.php). Контроллёр строит из него Yii-actions (1 операция =
 * 1 маршрут = 1 RBAC-разрешение), `describe` — перечень для клиента.
 */
final class OperationRegistry
{
    /** @var array<string, OperationInterface> */
    private array $operations = [];

    /**
     * @param iterable<OperationInterface> $operations
     */
    public function __construct(iterable $operations = [])
    {
        foreach ($operations as $operation) {
            $this->register($operation);
        }
    }

    public function register(OperationInterface $operation): void
    {
        $name = $operation->name();
        if (preg_match('/^[a-z][a-z0-9-]*$/', $name) !== 1) {
            throw new LogicException("Недопустимое имя операции: '{$name}'.");
        }
        if (isset($this->operations[$name])) {
            throw new LogicException("Операция '{$name}' зарегистрирована дважды.");
        }
        $this->operations[$name] = $operation;
    }

    public function has(string $name): bool
    {
        return isset($this->operations[$name]);
    }

    public function get(string $name): OperationInterface
    {
        return $this->operations[$name] ?? throw new LogicException("Операция '{$name}' не зарегистрирована.");
    }

    /** @return array<string, OperationInterface> */
    public function all(): array
    {
        return $this->operations;
    }
}
