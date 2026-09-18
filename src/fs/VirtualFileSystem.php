<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs;

use Besnovatyj\File\fs\mount\MountRegistry;
use Besnovatyj\File\fs\node\Node;
use Besnovatyj\File\fs\operation\ConflictStrategy;
use Besnovatyj\File\fs\operation\ContentResult;
use Besnovatyj\File\fs\operation\Deleter;
use Besnovatyj\File\fs\operation\DirectoryCreator;
use Besnovatyj\File\fs\operation\Downloader;
use Besnovatyj\File\fs\operation\DownloadStream;
use Besnovatyj\File\fs\operation\Inspector;
use Besnovatyj\File\fs\operation\Lister;
use Besnovatyj\File\fs\operation\Listing;
use Besnovatyj\File\fs\operation\ListOptions;
use Besnovatyj\File\fs\operation\Renamer;
use Besnovatyj\File\fs\operation\report\OperationReport;
use Besnovatyj\File\fs\operation\Transfer;
use Besnovatyj\File\fs\operation\Uploader;
use Besnovatyj\File\fs\operation\UploadResult;
use Besnovatyj\File\fs\operation\UploadSource;
use Besnovatyj\File\fs\path\VirtualPath;

/**
 * Фасад домена для ДРУГИХ модулей CMS, которым нужны файловые операции программно (например,
 * положить сгенерированный файл в хранилище). Тонкий делегат к сервисам операций; API-слой
 * файлового менеджера пользуется сервисами напрямую и через фасад не ходит.
 *
 * Все методы принимают {@see VirtualPath} — строки из недоверенных источников сначала разбираются
 * через {@see VirtualPath::parse()}.
 */
final class VirtualFileSystem
{
    public function __construct(
        private readonly MountRegistry $mounts,
        private readonly Lister $lister,
        private readonly Inspector $inspector,
        private readonly DirectoryCreator $directories,
        private readonly Renamer $renamer,
        private readonly Transfer $transfer,
        private readonly Deleter $deleter,
        private readonly Uploader $uploader,
        private readonly Downloader $downloader,
    ) {
    }

    public function mounts(): MountRegistry
    {
        return $this->mounts;
    }

    public function list(VirtualPath $directory, ?ListOptions $options = null): Listing
    {
        return $this->lister->list($directory, $options ?? new ListOptions());
    }

    public function tree(VirtualPath $directory): Listing
    {
        return $this->lister->tree($directory);
    }

    public function stat(VirtualPath $path): Node
    {
        return $this->inspector->stat($path);
    }

    public function content(VirtualPath $path, ?int $maxBytes = null): ContentResult
    {
        return $this->inspector->content($path, $maxBytes);
    }

    public function mkdir(VirtualPath $parent, string $name): Node
    {
        return $this->directories->create($parent, $name);
    }

    public function rename(VirtualPath $path, string $newName): Node
    {
        return $this->renamer->rename($path, $newName);
    }

    /** @param list<VirtualPath> $sources */
    public function move(array $sources, VirtualPath $target, ConflictStrategy $strategy = ConflictStrategy::Fail): OperationReport
    {
        return $this->transfer->move($sources, $target, $strategy);
    }

    /** @param list<VirtualPath> $sources */
    public function copy(array $sources, VirtualPath $target, ConflictStrategy $strategy = ConflictStrategy::Fail): OperationReport
    {
        return $this->transfer->copy($sources, $target, $strategy);
    }

    /** @param list<VirtualPath> $paths */
    public function delete(array $paths): OperationReport
    {
        return $this->deleter->delete($paths);
    }

    public function upload(VirtualPath $directory, UploadSource $source, ?string $name = null, ConflictStrategy $strategy = ConflictStrategy::Fail): UploadResult
    {
        return $this->uploader->upload($directory, $source, $name, $strategy);
    }

    public function download(VirtualPath $path): DownloadStream
    {
        return $this->downloader->open($path);
    }
}
