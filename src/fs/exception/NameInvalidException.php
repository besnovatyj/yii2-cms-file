<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\exception;

/**
 * Имя файла/папки нарушает правила именования ({@see \Besnovatyj\File\fs\naming\NameRules}).
 * В `details['rule']` — идентификатор нарушенного правила (тот же, что знает фронтенд), чтобы UI мог
 * подсветить конкретную причину, а не показывать общий текст.
 */
final class NameInvalidException extends FsException
{
    public const string CODE = 'name_invalid';

    public static function rule(string $rule, string $message, string $name): self
    {
        return new self($message, null, ['rule' => $rule, 'name' => $name]);
    }
}
