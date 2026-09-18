<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\tus;

/**
 * Настройки докачиваемой загрузки (протокол tus 1.0.0) — `params.fs.tus` модуля.
 *
 * Размер куска и порог отдаются клиенту в `describe.upload.tus`; клиент сам решает, что грузить
 * кусками (файлы ≥ threshold), а что одним multipart-запросом. Сервер принимает любые куски
 * не больше `chunkSize` (защита от гигантских PATCH).
 */
final class TusConfig
{
    /**
     * @param bool $enabled Включена ли поддержка tus (иначе endpoints отвечают 404, describe не объявляет chunked).
     * @param string $dir Каталог временных файлов загрузок (уже разрешённый алиас).
     * @param int $chunkSize Размер куска, байт (клиенту) и максимум тела PATCH (серверу).
     * @param int $threshold Порог размера файла, с которого клиент использует tus.
     * @param int $ttl Срок жизни незавершённой загрузки, секунд.
     * @param string $endpoint URL создания загрузки (POST) для клиента.
     */
    public function __construct(
        public readonly bool $enabled = true,
        public readonly string $dir = '',
        public readonly int $chunkSize = 5 * 1024 * 1024,
        public readonly int $threshold = 8 * 1024 * 1024,
        public readonly int $ttl = 86_400,
        public readonly string $endpoint = '',
    ) {
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config, string $dir, string $endpoint): self
    {
        $d = new self();
        return new self(
            enabled: (bool)($config['enabled'] ?? $d->enabled),
            dir: $dir,
            chunkSize: max(64 * 1024, (int)($config['chunkSize'] ?? $d->chunkSize)),
            threshold: max(0, (int)($config['threshold'] ?? $d->threshold)),
            ttl: max(60, (int)($config['ttl'] ?? $d->ttl)),
            endpoint: $endpoint,
        );
    }

    /** Часть для `describe.upload.tus` (контракт §13). */
    public function toArray(): array
    {
        return [
            'endpoint' => $this->endpoint,
            'chunkSize' => $this->chunkSize,
            'threshold' => $this->threshold,
        ];
    }
}
