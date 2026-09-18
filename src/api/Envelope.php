<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\api;

/**
 * Конверт ответа (контракт §2). Единственное место, где формируется верхний уровень JSON —
 * операции возвращают только `data`.
 */
final class Envelope
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    public static function success(array $data, array $meta = []): array
    {
        return [
            'ok' => true,
            'data' => $data,
            'meta' => ['contract' => Contract::VERSION] + $meta,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function error(ApiException $e): array
    {
        return [
            'ok' => false,
            'error' => $e->toArray(),
            'meta' => ['contract' => Contract::VERSION],
        ];
    }
}
