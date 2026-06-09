<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\upload;

use Besnovatyj\File\storage\StorageMount;

/**
 * Дополнительная грань правила политики: проверка ИМЕНИ вне контекста загрузки.
 *
 * Нужна правилам, чьё ограничение обходится без upload'а — например, блок-лист исполняемых
 * расширений обязан срабатывать и на rename («залил safe.txt → переименовал в shell.php»).
 * Правило реализует этот интерфейс В ДОПОЛНЕНИЕ к {@see UploadRuleInterface}; политика
 * применяет такие правила в {@see UploadPolicy::validateName()} (вызывается из rename).
 */
interface FileNameRuleInterface
{
    /**
     * @param string $name Фактическое имя (после санитизации).
     * @throws UploadRejectedException нарушение политики (наружу уйдёт как 422).
     */
    public function validateName(string $name, StorageMount $mount): void;
}
