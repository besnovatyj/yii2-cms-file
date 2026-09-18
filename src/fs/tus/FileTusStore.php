<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\tus;

use RuntimeException;

/**
 * Файловое хранилище tus-загрузок: `{dir}/{id}.json` (метаданные) + `{dir}/{id}.bin` (байты).
 *
 * Идентификатор — 32 hex-символа, поэтому имена файлов безопасны по построению; любая другая
 * строка отклоняется до обращения к диску. Дописывание — под `flock` на json-файле.
 */
final class FileTusStore implements TusStoreInterface
{
    private const string ID_PATTERN = '/^[a-f0-9]{32}$/';

    public function __construct(private readonly string $dir)
    {
    }

    public function create(TusUpload $upload): void
    {
        $this->ensureDir();
        $this->assertId($upload->id);
        if (file_put_contents($this->binPath($upload->id), '', LOCK_EX) === false) {
            throw new RuntimeException('tus: не удалось создать файл данных.');
        }
        $this->writeMeta($upload);
    }

    public function find(string $id): ?TusUpload
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            return null;
        }
        $json = @file_get_contents($this->metaPath($id));
        if ($json === false) {
            return null;
        }
        $data = json_decode($json, true);
        return is_array($data) ? TusUpload::fromArray($data) : null;
    }

    public function append(TusUpload $upload, $stream, int $expectedOffset, int $maxBytes): TusUpload
    {
        $this->assertId($upload->id);
        $lock = fopen($this->metaPath($upload->id), 'r+b');
        if ($lock === false) {
            throw new TusException(404, 'Загрузка не найдена.');
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new TusException(423, 'Загрузка занята другим запросом.');
            }
            // Перечитываем под блокировкой: offset мог измениться параллельным PATCH.
            $current = $this->find($upload->id) ?? throw new TusException(404, 'Загрузка не найдена.');
            if ($current->offset !== $expectedOffset) {
                throw new TusException(409, "Ожидался offset {$current->offset}.");
            }
            $bin = fopen($this->binPath($upload->id), 'ab');
            if ($bin === false) {
                throw new RuntimeException('tus: не удалось открыть файл данных.');
            }
            try {
                $remaining = min($maxBytes, $current->length - $current->offset);
                $written = 0;
                while ($remaining > 0 && !feof($stream)) {
                    $chunk = fread($stream, min(65_536, $remaining));
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    if (fwrite($bin, $chunk) === false) {
                        throw new RuntimeException('tus: ошибка записи данных.');
                    }
                    $written += strlen($chunk);
                    $remaining -= strlen($chunk);
                }
                // Клиент прислал больше, чем осталось до Upload-Length, — протокол это запрещает.
                if ($remaining === 0 && !feof($stream) && fread($stream, 1) !== '') {
                    throw new TusException(413, 'Данные выходят за заявленную длину загрузки.');
                }
            } finally {
                fclose($bin);
            }
            $updated = $current->withOffset($current->offset + $written);
            $this->writeMeta($updated);
            return $updated;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function dataPath(TusUpload $upload): string
    {
        $this->assertId($upload->id);
        return $this->binPath($upload->id);
    }

    public function delete(string $id): void
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            return;
        }
        @unlink($this->binPath($id));
        @unlink($this->metaPath($id));
    }

    public function all(): iterable
    {
        foreach (glob($this->dir . '/*.json') ?: [] as $file) {
            $upload = $this->find(basename($file, '.json'));
            if ($upload !== null) {
                yield $upload;
            }
        }
    }

    private function writeMeta(TusUpload $upload): void
    {
        $json = json_encode($upload->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        // Атомарно: временный файл + rename, чтобы параллельный find() не прочитал полузаписанный json.
        $tmp = $this->metaPath($upload->id) . '.tmp';
        if (file_put_contents($tmp, $json) === false || !rename($tmp, $this->metaPath($upload->id))) {
            throw new RuntimeException('tus: не удалось записать метаданные загрузки.');
        }
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->dir) && !mkdir($this->dir, 0770, true) && !is_dir($this->dir)) {
            throw new RuntimeException("tus: не удалось создать каталог {$this->dir}.");
        }
    }

    private function assertId(string $id): void
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new TusException(404, 'Загрузка не найдена.');
        }
    }

    private function metaPath(string $id): string
    {
        return $this->dir . '/' . $id . '.json';
    }

    private function binPath(string $id): string
    {
        return $this->dir . '/' . $id . '.bin';
    }
}
