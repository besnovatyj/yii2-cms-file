<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\operation;

use Besnovatyj\File\fs\mount\Mount;
use Besnovatyj\File\fs\naming\NameValidator;
use Besnovatyj\File\fs\policy\OperationPolicy;
use Besnovatyj\File\fs\policy\PolicyContext;

/**
 * Единая проверка имени назначения для любой операции, задающей имя: синтаксис
 * ({@see NameValidator}) + политика ({@see OperationPolicy}). Все сервисы операций проходят
 * через этот класс, поэтому невозможно «забыть» проверку в одной из веток.
 */
final class NameGuard
{
    public function __construct(
        private readonly NameValidator $validator,
        private readonly OperationPolicy $policy,
    ) {
    }

    /**
     * @param string $name Желаемое имя (для upload — уже санитизированное).
     * @param string|null $targetPath Виртуальный путь назначения для сообщений.
     * @return string Нормализованное имя, разрешённое к использованию.
     */
    public function approve(
        FsOperation $operation,
        Mount $mount,
        string $name,
        ?string $targetPath = null,
        ?UploadSource $upload = null,
    ): string {
        $approved = $this->validator->validate($name);
        $this->policy->check(new PolicyContext($operation, $mount, $approved, $targetPath, $upload));
        return $approved;
    }

    /**
     * Проверка имени УЖЕ существующего узла при переносе/копировании: синтаксис не проверяется
     * (узел с таким именем уже лежит в хранилище — возможно, создан не менеджером и не по нашим
     * правилам; отказывать в переносе было бы бессмысленно), но политика (блок-лист расширений)
     * применяется в полном объёме — иначе `x.php` можно было бы «протащить» копированием.
     */
    public function approveExisting(FsOperation $operation, Mount $mount, string $name, ?string $targetPath = null): string
    {
        $this->policy->check(new PolicyContext($operation, $mount, $name, $targetPath));
        return $name;
    }

    public function validator(): NameValidator
    {
        return $this->validator;
    }
}
