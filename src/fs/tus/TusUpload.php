<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\tus;

/**
 * Незавершённая (или завершённая, но ещё не финализированная) tus-загрузка — запись хранилища.
 *
 * Принадлежит пользователю ({@see $ownerId}): чужие загрузки нельзя ни читать, ни дописывать,
 * ни финализировать. Метаданные (`filename`, `filetype`) — недоверенные, пришли от клиента;
 * имя проходит санитизацию при финализации, как при обычном upload.
 */
final class TusUpload
{
    /**
     * @param array<string, string> $metadata Пары из заголовка Upload-Metadata.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $ownerId,
        public readonly int $length,
        public readonly int $offset,
        public readonly array $metadata,
        public readonly int $createdAt,
        public readonly int $expiresAt,
    ) {
    }

    public function isComplete(): bool
    {
        return $this->offset >= $this->length;
    }

    public function isExpired(int $now): bool
    {
        return $this->expiresAt < $now;
    }

    public function withOffset(int $offset): self
    {
        return new self($this->id, $this->ownerId, $this->length, $offset, $this->metadata, $this->createdAt, $this->expiresAt);
    }

    /** Имя файла, заявленное клиентом (или заглушка). */
    public function clientName(): string
    {
        $name = trim($this->metadata['filename'] ?? '');
        return $name === '' ? 'file' : $name;
    }

    public function clientMime(): ?string
    {
        $mime = trim($this->metadata['filetype'] ?? '');
        return $mime === '' ? null : $mime;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'ownerId' => $this->ownerId,
            'length' => $this->length,
            'offset' => $this->offset,
            'metadata' => $this->metadata,
            'createdAt' => $this->createdAt,
            'expiresAt' => $this->expiresAt,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string)$data['id'],
            (string)$data['ownerId'],
            (int)$data['length'],
            (int)$data['offset'],
            array_map(strval(...), (array)($data['metadata'] ?? [])),
            (int)$data['createdAt'],
            (int)$data['expiresAt'],
        );
    }
}
