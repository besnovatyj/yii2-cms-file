<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\policy;

use Besnovatyj\File\fs\mount\Mount;
use Besnovatyj\File\fs\operation\FsOperation;
use Besnovatyj\File\fs\operation\UploadSource;

/**
 * Что известно правилу политики о проверяемой операции.
 *
 * Одна структура для всех операций: правило само решает, применимо ли оно (например, лимит
 * размера смотрит только на `upload`, блок-лист расширений — на любую операцию, задающую имя).
 */
final class PolicyContext
{
    /**
     * @param FsOperation $operation Выполняемая операция.
     * @param Mount $mount Точка монтирования НАЗНАЧЕНИЯ.
     * @param string|null $targetName Итоговое имя узла (после санитизации), если операция задаёт имя.
     * @param string|null $targetPath Виртуальный путь назначения (для сообщений об ошибках).
     * @param UploadSource|null $upload Загружаемый файл (только для `upload`).
     */
    public function __construct(
        public readonly FsOperation $operation,
        public readonly Mount $mount,
        public readonly ?string $targetName = null,
        public readonly ?string $targetPath = null,
        public readonly ?UploadSource $upload = null,
    ) {
    }
}
