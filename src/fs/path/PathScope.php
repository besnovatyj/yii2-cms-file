<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\path;

use Besnovatyj\File\fs\exception\ForbiddenException;

/**
 * Область видимости («тюрьма») — поддерево виртуальной ФС, за пределы которого клиенту выходить нельзя.
 *
 * Нужна интеграциям, где менеджер открыт для конкретной сущности (папка поста в редакторе):
 * администратор не должен гулять по всему хранилищу. Область — свойство ЗАПРОСА, а не пользователя:
 * тот же пользователь из standalone-менеджера видит всё, из редактора поста — только папку поста.
 * Доставляется подписанным токеном ({@see \Besnovatyj\File\api\scope\ScopeToken}) и применяется
 * ко ВСЕМ путям запроса ({@see \Besnovatyj\File\api\Payload}), включая источники переноса.
 *
 * Права RBAC этим не подменяются: область только сужает, никогда не расширяет.
 */
final class PathScope
{
    public function __construct(public readonly VirtualPath $root)
    {
    }

    /** Область без ограничений (весь виртуальный корень). */
    public static function unrestricted(): self
    {
        return new self(VirtualPath::root());
    }

    public function isUnrestricted(): bool
    {
        return $this->root->isRoot();
    }

    /** Путь внутри области (сам корень области — тоже внутри). */
    public function contains(VirtualPath $path): bool
    {
        return $this->root->equals($path) || $this->root->isAncestorOf($path);
    }

    /**
     * @throws ForbiddenException путь вне области.
     */
    public function assertContains(VirtualPath $path): void
    {
        if (!$this->contains($path)) {
            throw new ForbiddenException(
                'Путь вне разрешённой области.',
                $path->toString(),
                ['reason' => 'out_of_scope', 'scope' => $this->root->toString()],
            );
        }
    }
}
