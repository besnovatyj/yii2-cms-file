<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\tus;

/**
 * Сервер протокола tus 1.0.0 (core + расширения creation, expiration, termination) — без
 * зависимости от HTTP-фреймворка: принимает разобранные значения заголовков, возвращает
 * {@see TusResponse}. Адаптер к Yii — {@see \Besnovatyj\File\controllers\backend\TusController}.
 *
 * Почему своя реализация, а не библиотека: протокол умещается в несколько методов, а нам важны
 * контроль владельца, лимиты размера из нашей политики и хранение во «временной» зоне до
 * финализации через обычный {@see \Besnovatyj\File\fs\operation\Uploader}. Спецификация:
 * https://tus.io/protocols/resumable-upload (MIT).
 */
final class TusServer
{
    public const string VERSION = '1.0.0';
    public const string EXTENSIONS = 'creation,expiration,termination';

    /**
     * @param int|null $maxLength Максимальная заявленная длина загрузки (из политики), null — без лимита.
     */
    public function __construct(
        private readonly TusStoreInterface $store,
        private readonly TusConfig $config,
        private readonly ?int $maxLength = null,
    ) {
    }

    /** OPTIONS — возможности сервера. */
    public function options(): TusResponse
    {
        return new TusResponse(204, $this->headers([
            'Tus-Version' => self::VERSION,
            'Tus-Extension' => self::EXTENSIONS,
            'Tus-Max-Size' => $this->maxLength === null ? null : (string)$this->maxLength,
        ]));
    }

    /**
     * POST — создание загрузки (расширение creation).
     *
     * @param callable(string): string $locationFor Строит абсолютный URL загрузки по id.
     */
    public function create(?string $uploadLength, ?string $uploadMetadata, string $ownerId, callable $locationFor): TusResponse
    {
        if ($uploadLength === null || preg_match('/^\d{1,15}$/', $uploadLength) !== 1) {
            throw new TusException(400, 'Требуется заголовок Upload-Length.');
        }
        $length = (int)$uploadLength;
        if ($this->maxLength !== null && $length > $this->maxLength) {
            throw new TusException(413, 'Файл превышает допустимый размер.');
        }

        $now = time();
        $upload = new TusUpload(
            id: bin2hex(random_bytes(16)),
            ownerId: $ownerId,
            length: $length,
            offset: 0,
            metadata: self::parseMetadata($uploadMetadata),
            createdAt: $now,
            expiresAt: $now + $this->config->ttl,
        );
        $this->store->create($upload);

        return new TusResponse(201, $this->headers([
            'Location' => $locationFor($upload->id),
            'Upload-Expires' => self::httpDate($upload->expiresAt),
        ]));
    }

    /** HEAD — текущее смещение (для докачки). */
    public function head(string $id, string $ownerId): TusResponse
    {
        $upload = $this->owned($id, $ownerId);
        return new TusResponse(200, $this->headers([
            'Upload-Offset' => (string)$upload->offset,
            'Upload-Length' => (string)$upload->length,
            'Upload-Expires' => self::httpDate($upload->expiresAt),
            'Cache-Control' => 'no-store',
        ]));
    }

    /**
     * PATCH — дописать кусок.
     *
     * @param resource $body
     */
    public function patch(string $id, string $ownerId, ?string $uploadOffset, ?string $contentType, $body): TusResponse
    {
        if ($contentType !== 'application/offset+octet-stream') {
            throw new TusException(415, 'Ожидается Content-Type: application/offset+octet-stream.');
        }
        if ($uploadOffset === null || preg_match('/^\d{1,15}$/', $uploadOffset) !== 1) {
            throw new TusException(400, 'Требуется заголовок Upload-Offset.');
        }
        $upload = $this->owned($id, $ownerId);
        if ($upload->isComplete()) {
            throw new TusException(409, 'Загрузка уже завершена.');
        }
        $updated = $this->store->append($upload, $body, (int)$uploadOffset, $this->config->chunkSize);

        return new TusResponse(204, $this->headers([
            'Upload-Offset' => (string)$updated->offset,
            'Upload-Expires' => self::httpDate($updated->expiresAt),
        ]));
    }

    /** DELETE — отказ от загрузки (расширение termination). */
    public function terminate(string $id, string $ownerId): TusResponse
    {
        $this->owned($id, $ownerId);
        $this->store->delete($id);
        return new TusResponse(204, $this->headers([]));
    }

    /**
     * Завершённая загрузка пользователя для финализации.
     *
     * @throws TusException 404 — нет/чужая/истекла; 409 — ещё не завершена.
     */
    public function completed(string $id, string $ownerId): TusUpload
    {
        $upload = $this->owned($id, $ownerId);
        if (!$upload->isComplete()) {
            throw new TusException(409, 'Загрузка ещё не завершена.');
        }
        return $upload;
    }

    public function dataPath(TusUpload $upload): string
    {
        return $this->store->dataPath($upload);
    }

    public function discard(TusUpload $upload): void
    {
        $this->store->delete($upload->id);
    }

    /** Удалить просроченные загрузки (cron). Возвращает число удалённых. */
    public function purgeExpired(): int
    {
        $now = time();
        $count = 0;
        foreach ($this->store->all() as $upload) {
            if ($upload->isExpired($now)) {
                $this->store->delete($upload->id);
                $count++;
            }
        }
        return $count;
    }

    /** Загрузка существует, не истекла и принадлежит пользователю — иначе 404/460 (чужие не раскрываем). */
    private function owned(string $id, string $ownerId): TusUpload
    {
        $upload = $this->store->find($id);
        if ($upload === null || $upload->ownerId !== $ownerId) {
            throw new TusException(404, 'Загрузка не найдена.');
        }
        if ($upload->isExpired(time())) {
            $this->store->delete($id);
            throw new TusException(460, 'Срок загрузки истёк.');
        }
        return $upload;
    }

    /**
     * Upload-Metadata: `key base64(value),key2 base64(value2)`; значение может отсутствовать.
     *
     * @return array<string, string>
     */
    private static function parseMetadata(?string $header): array
    {
        $result = [];
        foreach (explode(',', (string)$header) as $pair) {
            $pair = trim($pair);
            if ($pair === '') {
                continue;
            }
            [$key, $encoded] = array_pad(explode(' ', $pair, 2), 2, '');
            if (preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $key) !== 1) {
                continue;
            }
            $value = $encoded === '' ? '' : base64_decode($encoded, true);
            if ($value === false || strlen($value) > 1024) {
                continue;
            }
            $result[$key] = $value;
        }
        return $result;
    }

    /** @param array<string, string|null> $extra */
    private function headers(array $extra): array
    {
        return array_filter(['Tus-Resumable' => self::VERSION] + $extra, static fn($v) => $v !== null);
    }

    private static function httpDate(int $timestamp): string
    {
        return gmdate('D, d M Y H:i:s', $timestamp) . ' GMT';
    }
}
