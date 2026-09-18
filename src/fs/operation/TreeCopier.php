<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\operation;

use Besnovatyj\File\fs\exception\TooLargeException;
use Besnovatyj\File\fs\FsLimits;
use Besnovatyj\File\fs\path\Target;
use League\Flysystem\StorageAttributes;

/**
 * Рекурсивное копирование дерева между двумя адресами — в одном хранилище или между разными.
 *
 * Работает поверх Flysystem-примитивов: глубокий листинг источника, `createDirectory` для папок
 * (чтобы сохранить пустые), потоковое копирование файлов. Между хранилищами файл никогда не
 * читается в память целиком — `readStream` → `writeStream`.
 *
 * Лимит записей ({@see FsLimits::$maxTransferEntries}) считается ДО начала копирования: лучше
 * отказать сразу, чем оставить полускопированное дерево по таймауту.
 */
final class TreeCopier
{
    public function __construct(private readonly FsLimits $limits)
    {
    }

    /**
     * Копирует папку $source (существует) в $destination (не существует; родитель существует).
     */
    public function copy(Target $source, Target $destination): void
    {
        $srcFs = $source->filesystem();
        $dstFs = $destination->filesystem();
        $srcLoc = $source->location();
        $dstLoc = $destination->location();
        $sameFilesystem = $srcFs === $dstFs;

        /** @var list<StorageAttributes> $entries */
        $entries = $srcFs->listContents($srcLoc, true)->toArray();
        if (count($entries) > $this->limits->maxTransferEntries) {
            throw new TooLargeException(
                sprintf('Папка содержит %d записей — больше лимита %d на одну операцию.', count($entries), $this->limits->maxTransferEntries),
                $source->path->toString(),
                ['entries' => count($entries), 'limit' => $this->limits->maxTransferEntries],
            );
        }

        $dstFs->createDirectory($dstLoc);

        // Сначала папки (в порядке обхода — родители раньше детей), затем файлы: так у S3 не
        // возникает «файл без папки», а у ZIP родительские записи существуют до вложенных.
        usort($entries, static fn(StorageAttributes $a, StorageAttributes $b): int => ($a->isDir() ? 0 : 1) <=> ($b->isDir() ? 0 : 1) ?: strcmp($a->path(), $b->path()));

        $prefixLength = strlen($srcLoc) + ($srcLoc === '' ? 0 : 1);
        foreach ($entries as $entry) {
            $relative = substr($entry->path(), $prefixLength);
            $entryDst = $dstLoc === '' ? $relative : $dstLoc . '/' . $relative;

            if ($entry->isDir()) {
                $dstFs->createDirectory($entryDst);
                continue;
            }

            if ($sameFilesystem) {
                $srcFs->copy($entry->path(), $entryDst);
                continue;
            }

            $stream = $srcFs->readStream($entry->path());
            try {
                $dstFs->writeStream($entryDst, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }
    }

    /** Копирует один файл (потоком) между адресами; для одного хранилища — нативным copy(). */
    public function copyFile(Target $source, Target $destination): void
    {
        $srcFs = $source->filesystem();
        $dstFs = $destination->filesystem();

        if ($srcFs === $dstFs) {
            $srcFs->copy($source->location(), $destination->location());
            return;
        }

        $stream = $srcFs->readStream($source->location());
        try {
            $dstFs->writeStream($destination->location(), $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
