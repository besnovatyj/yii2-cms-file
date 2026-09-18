<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\exception;

/**
 * Операция отклонена правилом политики ({@see \Besnovatyj\File\fs\policy\PolicyRuleInterface}):
 * заблокированное расширение, превышение размера, недопустимое содержимое.
 * `details['rule']` — идентификатор правила.
 */
final class PolicyRejectedException extends FsException
{
    public const string CODE = 'policy_rejected';

    /**
     * @param array<string, mixed> $extra Дополнительные детали правила.
     */
    public static function byRule(string $rule, string $message, ?string $path = null, array $extra = []): self
    {
        return new self($message, $path, ['rule' => $rule] + $extra);
    }
}
