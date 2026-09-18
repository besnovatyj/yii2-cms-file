<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs;

/**
 * Количественные лимиты домена — защита от DoS и «случайных» тяжёлых операций.
 * Часть отдаётся фронтенду в `describe.limits`, чтобы он резал пакеты заранее.
 */
final class FsLimits
{
    /**
     * @param int $listPageSize Элементов на страницу листинга (курсорная пагинация).
     * @param int $maxBatchItems Максимум путей в одном пакетном запросе (move/copy/delete).
     * @param int $contentMaxBytes Максимум байт, отдаваемых операцией `content`.
     * @param int $maxTransferEntries Максимум записей (файлов+папок) в переносимом/копируемом дереве за один запрос.
     * @param int $imageProbeMaxBytes Максимальный размер изображения, у которого stat вычисляет размеры (читается в память).
     * @param int $previewMaxBytes Максимальный размер файла для inline-предпросмотра (`preview`).
     * @param int $searchMaxResults Максимум результатов одного поиска (`search`).
     * @param int $searchMaxEntries Максимум записей хранилища, просматриваемых одним поиском (бюджет обхода).
     * @param int $archiveMaxBytes Максимум несжатых байт при сборке/распаковке архива (защита от zip-бомб и долгих запросов).
     */
    public function __construct(
        public readonly int $listPageSize = 2000,
        public readonly int $maxBatchItems = 500,
        public readonly int $contentMaxBytes = 1_048_576,
        public readonly int $maxTransferEntries = 5000,
        public readonly int $imageProbeMaxBytes = 8_388_608,
        public readonly int $previewMaxBytes = 20_971_520,
        public readonly int $searchMaxResults = 500,
        public readonly int $searchMaxEntries = 50_000,
        public readonly int $archiveMaxBytes = 2_147_483_648,
    ) {
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $d = new self();
        return new self(
            listPageSize: max(1, (int)($config['listPageSize'] ?? $d->listPageSize)),
            maxBatchItems: max(1, (int)($config['maxBatchItems'] ?? $d->maxBatchItems)),
            contentMaxBytes: max(1, (int)($config['contentMaxBytes'] ?? $d->contentMaxBytes)),
            maxTransferEntries: max(1, (int)($config['maxTransferEntries'] ?? $d->maxTransferEntries)),
            imageProbeMaxBytes: max(0, (int)($config['imageProbeMaxBytes'] ?? $d->imageProbeMaxBytes)),
            previewMaxBytes: max(0, (int)($config['previewMaxBytes'] ?? $d->previewMaxBytes)),
            searchMaxResults: max(1, (int)($config['searchMaxResults'] ?? $d->searchMaxResults)),
            searchMaxEntries: max(1, (int)($config['searchMaxEntries'] ?? $d->searchMaxEntries)),
            archiveMaxBytes: max(1, (int)($config['archiveMaxBytes'] ?? $d->archiveMaxBytes)),
        );
    }

    /** Публичная часть для `describe.limits`. */
    public function toArray(): array
    {
        return [
            'listPageSize' => $this->listPageSize,
            'maxBatchItems' => $this->maxBatchItems,
            'contentMaxBytes' => $this->contentMaxBytes,
            'previewMaxBytes' => $this->previewMaxBytes,
            'searchMaxResults' => $this->searchMaxResults,
            'archiveMaxBytes' => $this->archiveMaxBytes,
        ];
    }
}
