<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\api;

use Besnovatyj\File\fs\exception\FsException;
use RuntimeException;
use Throwable;

/**
 * Ошибка уровня API — то, что сериализуется в конверт `{ok:false, error:{...}}` (контракт §2).
 *
 * Создаётся тремя путями: напрямую (ошибки входа — `bad_request`), из доменного исключения
 * ({@see fromFs()}) и из любого непредвиденного Throwable ({@see internal()}): в последнем случае
 * наружу уходит нейтральный текст, а причина сохраняется в `previous` для логирования.
 */
final class ApiException extends RuntimeException
{
    /**
     * @param array<string, mixed> $details
     * @param int|null $httpStatus Переопределение статуса (например 405); null — по коду.
     */
    public function __construct(
        public readonly ErrorCode $errorCode,
        string $message,
        public readonly ?string $path = null,
        public readonly array $details = [],
        ?Throwable $previous = null,
        private readonly ?int $httpStatus = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function badRequest(string $message, array $details = []): self
    {
        return new self(ErrorCode::BadRequest, $message, null, $details);
    }

    public static function fromFs(FsException $e): self
    {
        return new self(
            ErrorCode::fromDomain($e->errorCode()),
            $e->getMessage(),
            $e->path(),
            $e->details(),
            $e,
        );
    }

    /** Непредвиденная ошибка: наружу — нейтральный текст, причина — в previous. */
    public static function internal(Throwable $cause): self
    {
        return new self(ErrorCode::Internal, 'Внутренняя ошибка файловой операции.', null, [], $cause);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus ?? $this->errorCode->httpStatus();
    }

    /** Требует ли ошибка записи в лог сервера (внутренние — да, клиентские — нет). */
    public function isInternal(): bool
    {
        return $this->errorCode === ErrorCode::Internal;
    }

    /**
     * Тело `error` конверта.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $body = [
            'code' => $this->errorCode->value,
            'message' => $this->getMessage(),
        ];
        if ($this->path !== null) {
            $body['path'] = $this->path;
        }
        if ($this->details !== []) {
            $body['details'] = $this->details;
        }
        return $body;
    }
}
