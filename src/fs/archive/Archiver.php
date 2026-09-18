<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\archive;

use Besnovatyj\File\fs\exception\AlreadyExistsException;
use Besnovatyj\File\fs\exception\FsException;
use Besnovatyj\File\fs\exception\InvalidOperationException;
use Besnovatyj\File\fs\exception\StorageFailureException;
use Besnovatyj\File\fs\exception\TooLargeException;
use Besnovatyj\File\fs\FsLimits;
use Besnovatyj\File\fs\node\NodeFactory;
use Besnovatyj\File\fs\node\NodeKind;
use Besnovatyj\File\fs\operation\ConflictResolver;
use Besnovatyj\File\fs\operation\ConflictStrategy;
use Besnovatyj\File\fs\operation\DirectoryCreator;
use Besnovatyj\File\fs\operation\FsOperation;
use Besnovatyj\File\fs\operation\Uploader;
use Besnovatyj\File\fs\operation\UploadResult;
use Besnovatyj\File\fs\operation\UploadSource;
use Besnovatyj\File\fs\path\PathResolver;
use Besnovatyj\File\fs\path\Target;
use Besnovatyj\File\fs\path\VirtualPath;
use League\Flysystem\FilesystemException;
use League\Flysystem\FileAttributes;
use League\Flysystem\Filesystem;
use RuntimeException;
use ZipArchive;

/**
 * Архивы ZIP (контракт §9.16 `archive`, §9.17 `extract`). Синхронно, без фоновых задач: объём
 * ограничен бюджетами `limits.maxTransferEntries` (записей) и `limits.archiveMaxBytes`
 * (несжатых байт), поэтому время работы предсказуемо.
 *
 * Безопасность распаковки — главное здесь:
 *  - имена записей нормализуются, `..`, абсолютные пути и пустые сегменты отвергаются (zip-slip);
 *  - суммарный несжатый размер и число записей проверяются ДО записи чего-либо (zip-бомба);
 *  - каждый файл проходит тот же путь, что и обычная загрузка ({@see Uploader}: санитизация имени,
 *    правила политики — блок-лист расширений, лимит размера, sniff), каждая папка — через
 *    {@see DirectoryCreator}; отвергнутые политикой записи пропускаются и перечисляются в ответе.
 *
 * Сборка архива читает исходники потоками из любых хранилищ, поэтому источники могут лежать
 * в разных mount'ах; результат кладётся как обычная загрузка (те же правила и конфликты).
 */
final class Archiver
{
    public function __construct(
        private readonly PathResolver $resolver,
        private readonly NodeFactory $nodes,
        private readonly FsLimits $limits,
        private readonly Uploader $uploader,
        private readonly DirectoryCreator $directories,
        private readonly ConflictResolver $conflicts,
    ) {
    }

    /**
     * @param list<VirtualPath> $sources Файлы и папки (папки — рекурсивно).
     */
    public function archive(array $sources, VirtualPath $targetDir, ?string $name, ConflictStrategy $strategy): UploadResult
    {
        if ($sources === []) {
            throw new InvalidOperationException('Нечего архивировать: список путей пуст.');
        }
        $target = $this->resolver->resolveDirectory($targetDir);
        $target->mount->assertSupports(FsOperation::Archive);

        $zipFile = new TempFile();
        $zip = new ZipArchive();
        if ($zip->open($zipFile->path, ZipArchive::OVERWRITE) !== true) {
            throw StorageFailureException::wrap(new RuntimeException('Не удалось создать ZIP.'), $targetDir->toString(), 'archive');
        }

        $budget = new ArchiveBudget($this->limits->maxTransferEntries, $this->limits->archiveMaxBytes);
        /** @var list<TempFile> $keep Временные копии файлов должны жить до close(): addFile читает их лениво. */
        $keep = [];
        try {
            foreach ($sources as $source) {
                $origin = $this->resolver->resolve($source);
                $origin->mount->assertSupports(FsOperation::Download);
                $fs = $origin->filesystem();
                if ($origin->isFile()) {
                    $keep[] = $this->addFile($zip, $fs, $origin->location(), null, $source->name(), $budget);
                    continue;
                }
                $zip->addEmptyDir($source->name());
                $budget->entry();
                $base = rtrim($origin->location(), '/');
                foreach ($fs->listContents($origin->location(), true) as $attributes) {
                    $relative = ltrim(substr($attributes->path(), strlen($base)), '/');
                    $entryName = $source->name() . '/' . $relative;
                    if ($attributes->isDir()) {
                        $zip->addEmptyDir($entryName);
                        $budget->entry();
                        continue;
                    }
                    $known = $attributes instanceof FileAttributes ? $attributes->fileSize() : null;
                    $keep[] = $this->addFile($zip, $fs, $attributes->path(), $known, $entryName, $budget);
                }
            }
        } catch (FilesystemException $e) {
            $zip->close();
            throw StorageFailureException::wrap($e, $targetDir->toString(), 'archive');
        }
        if ($zip->close() !== true) {
            throw StorageFailureException::wrap(new RuntimeException('Не удалось записать ZIP.'), $targetDir->toString(), 'archive');
        }
        unset($keep);

        $fileName = self::archiveName($sources, $name);
        $upload = new UploadSource($zipFile->path, (int)filesize($zipFile->path), $fileName, 'application/zip');
        return $this->uploader->upload($targetDir, $upload, null, $strategy);
    }

    public function extract(VirtualPath $archive, ?VirtualPath $targetDir, ConflictStrategy $strategy): ExtractResult
    {
        $origin = $this->resolver->resolveFile($archive);
        $origin->mount->assertSupports(FsOperation::Download);

        $zipFile = new TempFile();
        try {
            $stream = $origin->filesystem()->readStream($origin->location());
        } catch (FilesystemException $e) {
            throw StorageFailureException::wrap($e, $archive->toString(), 'extract');
        }
        try {
            $zipFile->fillFrom($stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $zip = new ZipArchive();
        $code = $zip->open($zipFile->path, ZipArchive::RDONLY);
        if ($code !== true) {
            throw new InvalidOperationException('Файл не является корректным ZIP-архивом.', $archive->toString(), ['zipError' => $code]);
        }

        try {
            $this->assertBudget($zip, $archive);
            $destination = $this->destinationFor($archive, $targetDir);
            $destination->mount->assertSupports(FsOperation::Extract);
            $destinationPath = $destination->path;

            $total = $zip->numFiles;
            $extracted = 0;
            $skipped = [];
            /** @var array<string, true> $dirs Уже созданные (или существующие) папки — по виртуальному пути. */
            $dirs = [];

            for ($i = 0; $i < $total; $i++) {
                $rawName = (string)$zip->getNameIndex($i);
                try {
                    $segments = self::entrySegments($rawName);
                    $isDir = str_ends_with($rawName, '/');
                    $fileName = $isDir ? null : array_pop($segments);
                    $dirPath = $this->ensureDirectories($destinationPath, $segments, $dirs);
                    if ($fileName === null) {
                        $extracted++;
                        continue;
                    }
                    $entry = new TempFile();
                    $entryStream = $zip->getStream($rawName);
                    if ($entryStream === false) {
                        throw StorageFailureException::wrap(new RuntimeException('Не удалось прочитать запись архива.'), $archive->toString(), 'extract');
                    }
                    try {
                        $size = $entry->fillFrom($entryStream);
                    } finally {
                        fclose($entryStream);
                    }
                    $this->uploader->upload($dirPath, new UploadSource($entry->path, $size, $fileName), null, $strategy);
                    $extracted++;
                } catch (FsException $e) {
                    if (count($skipped) < ExtractResult::MAX_SKIPPED_LISTED) {
                        $skipped[] = ['name' => $rawName, 'code' => $e->errorCode(), 'message' => $e->getMessage()];
                    }
                }
            }
        } finally {
            $zip->close();
        }

        return new ExtractResult($this->nodes->fromPath($destination->mount, $destinationPath), $total, $extracted, $skipped);
    }

    // ---------------------------------------------------------------- сборка

    /**
     * Добавить файл в архив через временную копию: ZipArchive читает файлы при close(), а
     * поток хранилища к тому моменту закрыт — поэтому копия, и она живёт до конца сборки.
     */
    private function addFile(ZipArchive $zip, Filesystem $fs, string $location, ?int $knownSize, string $entryName, ArchiveBudget $budget): TempFile
    {
        $budget->entry();
        $budget->bytes($knownSize ?? $fs->fileSize($location));
        $copy = new TempFile();
        $stream = $fs->readStream($location);
        try {
            $copy->fillFrom($stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        if (!$zip->addFile($copy->path, $entryName)) {
            throw StorageFailureException::wrap(new RuntimeException("Не удалось добавить в архив: {$entryName}."), null, 'archive');
        }
        return $copy;
    }

    /**
     * @param list<VirtualPath> $sources
     */
    private static function archiveName(array $sources, ?string $name): string
    {
        $base = $name !== null && trim($name) !== '' ? trim($name) : (count($sources) === 1 ? $sources[0]->name() : 'archive');
        if (count($sources) === 1 && $name === null && str_contains($base, '.')) {
            $base = pathinfo($base, PATHINFO_FILENAME) ?: $base; // file.txt → file.zip
        }
        return str_ends_with(strtolower($base), '.zip') ? $base : $base . '.zip';
    }

    // ---------------------------------------------------------------- распаковка

    /** Число записей и суммарный несжатый размер — до записи первого байта. */
    private function assertBudget(ZipArchive $zip, VirtualPath $archive): void
    {
        if ($zip->numFiles > $this->limits->maxTransferEntries) {
            throw new TooLargeException('В архиве слишком много записей.', $archive->toString(), ['limit' => $this->limits->maxTransferEntries]);
        }
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $total += (int)($stat['size'] ?? 0);
            if ($total > $this->limits->archiveMaxBytes) {
                throw new TooLargeException('Несжатый размер архива превышает лимит.', $archive->toString(), ['limit' => $this->limits->archiveMaxBytes]);
            }
        }
    }

    /** Папка назначения: указанная либо новая рядом с архивом с его именем (при занятости — «имя (2)»). */
    private function destinationFor(VirtualPath $archive, ?VirtualPath $targetDir): Target
    {
        if ($targetDir !== null) {
            return $this->resolver->resolveDirectory($targetDir);
        }
        $parentPath = $archive->parent();
        if ($parentPath === null) {
            throw new InvalidOperationException('У архива нет родительской папки.', $archive->toString());
        }
        $parent = $this->resolver->resolveDirectory($parentPath);
        $baseName = pathinfo($archive->name(), PATHINFO_FILENAME) ?: $archive->name();
        $decision = $this->conflicts->resolve($parent, $baseName, ConflictStrategy::Rename, NodeKind::Dir);
        $node = $this->directories->create($parentPath, $decision->destination->path->name());
        return $this->resolver->resolveDirectory($node->path);
    }

    /**
     * Сегменты пути записи; zip-slip отвергается здесь.
     *
     * @return list<string>
     */
    private static function entrySegments(string $rawName): array
    {
        $normalized = str_replace('\\', '/', $rawName);
        if ($normalized === '' || str_starts_with($normalized, '/')) {
            throw new InvalidOperationException('Недопустимый путь записи архива.', null, ['entry' => $rawName]);
        }
        $segments = array_values(array_filter(explode('/', $normalized), static fn(string $s): bool => $s !== ''));
        foreach ($segments as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new InvalidOperationException('Недопустимый путь записи архива.', null, ['entry' => $rawName]);
            }
        }
        if ($segments === []) {
            throw new InvalidOperationException('Пустой путь записи архива.', null, ['entry' => $rawName]);
        }
        return $segments;
    }

    /**
     * Создать цепочку папок под назначением (каждое имя — через DirectoryCreator, т.е. валидацию
     * и политику). Существующие папки принимаются.
     *
     * @param list<string> $segments
     * @param array<string, true> $dirs
     */
    private function ensureDirectories(VirtualPath $destination, array $segments, array &$dirs): VirtualPath
    {
        $current = $destination;
        foreach ($segments as $segment) {
            $next = $current->child($segment);
            $key = $next->toString();
            if (!isset($dirs[$key])) {
                try {
                    $created = $this->directories->create($current, $segment);
                    $next = $created->path; // имя могло быть нормализовано валидатором
                } catch (AlreadyExistsException) {
                    // уже есть — распаковываем внутрь
                }
                $dirs[$key] = true;
            }
            $current = $next;
        }
        return $current;
    }
}
