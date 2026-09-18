<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\policy;

use Besnovatyj\File\fs\exception\PolicyRejectedException;
use Besnovatyj\File\fs\naming\NameParts;
use Besnovatyj\File\fs\operation\FsOperation;

/**
 * Белый список расширений для загрузки (например, только изображения/документы для редакторов).
 * Регистрируется только при наличии настройки — по умолчанию ограничений нет (`null` в describe).
 * Применяется к `upload` и `rename` (иначе список обходится переименованием).
 */
final class AllowedExtensionsRule implements PolicyRuleInterface
{
    public const string ID = 'allowed_extensions';

    /** @var list<string> lowercase */
    private readonly array $allowed;

    /**
     * @param list<string> $allowed Расширения без точки.
     */
    public function __construct(array $allowed)
    {
        $this->allowed = array_values(array_unique(array_map(strtolower(...), $allowed)));
    }

    public function id(): string
    {
        return self::ID;
    }

    public function check(PolicyContext $context): void
    {
        if ($context->targetName === null
            || !in_array($context->operation, [FsOperation::Upload, FsOperation::Rename], true)
        ) {
            return;
        }
        // Папки (rename папки) расширения не имеют — правило про файлы. Понять «папка или файл»
        // по имени нельзя, поэтому имена без расширения пропускаем: файл без расширения
        // исполняемым не станет, а белый список защищает от «не тех» типов, а не от RCE.
        $extension = NameParts::split($context->targetName)->extension;
        if ($extension === null) {
            return;
        }
        if (!in_array($extension, $this->allowed, true)) {
            throw PolicyRejectedException::byRule(
                self::ID,
                sprintf('Расширение ".%s" не входит в список разрешённых.', $extension),
                $context->targetPath,
                ['extension' => $extension, 'allowed' => $this->allowed],
            );
        }
    }

    public function describe(): array
    {
        return ['upload' => ['allowedExtensions' => $this->allowed]];
    }
}
