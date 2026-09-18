<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\api;

/**
 * Машинные коды ошибок контракта (§3) и их HTTP-статусы.
 *
 * Код — первичен для клиента, статус — для прокси/логов. Домен ({@see \Besnovatyj\File\fs\exception})
 * оперирует строковыми кодами и HTTP не знает; сопоставление живёт здесь.
 */
enum ErrorCode: string
{
    case BadRequest = 'bad_request';
    case PathInvalid = 'path_invalid';
    case NameInvalid = 'name_invalid';
    case MountUnknown = 'mount_unknown';
    case NotFound = 'not_found';
    case Exists = 'exists';
    case Forbidden = 'forbidden';
    case PolicyRejected = 'policy_rejected';
    case Unsupported = 'unsupported';
    case TooLarge = 'too_large';
    case InvalidOperation = 'invalid_operation';
    case Conflict = 'conflict';
    case Internal = 'internal';

    public function httpStatus(): int
    {
        return match ($this) {
            self::BadRequest, self::PathInvalid, self::InvalidOperation => 400,
            self::Forbidden => 403,
            self::MountUnknown, self::NotFound => 404,
            self::Exists, self::Conflict => 409,
            self::TooLarge => 413,
            self::NameInvalid, self::PolicyRejected => 422,
            self::Internal => 500,
            self::Unsupported => 501,
        };
    }

    /** Код домена → код API; неизвестный код считается внутренней ошибкой (fail-safe). */
    public static function fromDomain(string $code): self
    {
        return self::tryFrom($code) ?? self::Internal;
    }
}
