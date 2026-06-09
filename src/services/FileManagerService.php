<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

namespace Besnovatyj\File\services;

use Besnovatyj\File\storage\StorageMount;
use Besnovatyj\File\upload\UploadPolicy;
use DomainException;
use League\Flysystem\FileAttributes;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use Yii;
use yii\web\UploadedFile;

/**
 * Файловые операции одной точки монтирования поверх League\Flysystem (адаптеро-независимо:
 * локальный каталог, ZIP-архив, AWS S3 / S3-совместимые).
 *
 * Сервис привязан к {@see StorageMount} и оперирует ОТНОСИТЕЛЬНЫМИ путями внутри неё ('' = корень).
 * Наружу (фронтенду) отдаёт ВИРТУАЛЬНЫЕ пути `/{mountId}/...` (через {@see StorageMount::virtual()})
 * и публичные URL файлов (через {@see StorageMount::url()}). Защита от обхода каталога обеспечивается
 * самим Flysystem (нормализация путей) + парсером {@see \Besnovatyj\File\storage\VirtualPath}.
 *
 * Метаданные файлов/папок адаптеро-независимы: Flysystem не даёт POSIX-прав/atime, поэтому права
 * синтезируются из visibility, а реальный контроль доступа — на бэкенде (RBAC), не в правах ФС.
 */
class FileManagerService
{
    public StorageMount $mount;

    /**
     * @param UploadPolicy|null $policy Серверная политика загрузки (null — без проверок;
     *                                  в боевой сборке всегда передаётся из StorageManager).
     */
    public function __construct(StorageMount $mount, private readonly ?UploadPolicy $policy = null)
    {
        $this->mount = $mount;
    }

    private function fs(): Filesystem
    {
        return $this->mount->filesystem();
    }

    /** Путь Flysystem из внутримаунтового пути ('' = корень, без ведущего слеша). */
    private function fsPath(string $relative): string
    {
        return ltrim($relative, '/');
    }

    // ======= LIST =======

    /**
     * Содержимое директории внутри точки монтирования. $path — относительный путь ('' = корень).
     */
    public function getFolderDto(string $path): array
    {
        $fs = $this->fs();
        $rel = $this->fsPath($path);

        // '' — корень точки монтирования (существует всегда). Остальное проверяем.
        // DomainException — как у остальных операций: контроллёр маппит доменные ошибки в 422.
        if ($rel !== '' && !$fs->directoryExists($rel)) {
            throw new DomainException("Directory $path does not exist");
        }

        $isMountRoot = ($rel === '');
        $dirVirtual = $this->mount->virtual($path); // путь самой директории — он же родитель её детей

        $data = [
            // название директории (для корня точки монтирования — её id)
            'name' => $isMountRoot ? $this->mount->id : basename($rel),
            // виртуальный путь родителя этой директории (без её собственного имени)
            'path' => $this->mount->virtualParent($path),
            'type' => 'dir',
            // Подсчёт детей-внуков не делаем: на S3 это N лишних LIST-запросов. Ленивая загрузка при заходе.
            'countChildDirs' => 0,
            'countChildFiles' => 0,
            'meta' => $this->dirMeta(),
            'folders' => [],
            'files' => [],
        ];

        foreach ($fs->listContents($rel, false) as $item) {
            $name = basename($item->path());

            if ($item instanceof FileAttributes) {
                $extension = pathinfo($name, PATHINFO_EXTENSION);
                $data['files'][] = [
                    'name' => $name,
                    'path' => $dirVirtual, // родитель ребёнка — текущая директория (виртуальный путь)
                    'extension' => $extension,
                    'type' => 'file',
                    // В листинге человекочитаемый тип — из расширения: mimeType() на каждый файл
                    // был бы N+1 запросом к адаптеру (особенно S3). Точный MIME отдают analyze
                    // и одиночные операции (rename/move).
                    'fileType' => $extension,
                    'url' => $this->publicUrl($item->path()),
                    'meta' => $this->fileMeta($item->lastModified(), $item->fileSize(), $item->visibility()),
                ];
            } else {
                // DirectoryAttributes
                $data['folders'][] = [
                    'name' => $name,
                    'path' => $dirVirtual,
                    'type' => 'dir',
                    'countChildDirs' => 0,
                    'countChildFiles' => 0,
                    'meta' => $this->dirMeta($item->lastModified()),
                    'folders' => [],
                    'files' => [],
                ];
            }
        }

        return $data;
    }

    // ======= MKDIR =======

    public function createDir(string $parentPath, string $name): array
    {
        $safeName = $this->sanitizeName($name);
        if ($safeName === '') {
            throw new DomainException('Directory name is empty or invalid.');
        }

        $fs = $this->fs();
        $parentRel = $this->fsPath($parentPath);
        $newRel = ($parentRel === '' ? '' : $parentRel . '/') . $safeName;

        if ($fs->has($newRel)) {
            throw new DomainException('File or directory already exists: ' . $safeName);
        }

        $fs->createDirectory($newRel);

        // Контракт createDir — FileDto (type='dir'), поэтому meta полная (FileMetaDto),
        // а не сокращённая FolderMetaDto из листинга.
        return [
            'name' => $safeName,
            'path' => $this->mount->virtual($parentPath), // виртуальный путь родителя
            'extension' => null,
            'type' => 'dir',
            'fileType' => 'directory',
            'url' => null,
            'meta' => $this->dirFileMeta(),
        ];
    }

    // ======= MOVE =======

    /**
     * Перемещение объекта в директорию $targetPath.
     * Возвращает операционный отчёт MoveResponse (контракт TS-first): фактическое состояние
     * объекта на новом месте — фронтенд по нему валидирует и логирует операцию.
     */
    public function move(string $sourcePath, string $targetPath): array
    {
        $fs = $this->fs();
        $sourceRel = $this->fsPath($sourcePath);
        $targetDirRel = $this->fsPath($targetPath);

        if ($targetDirRel !== '' && !$fs->directoryExists($targetDirRel)) {
            throw new DomainException('Target directory does not exist: ' . $targetPath);
        }

        $destRel = ($targetDirRel === '' ? '' : $targetDirRel . '/') . basename($sourceRel);

        if ($fs->has($destRel)) {
            throw new DomainException('Target already exists in destination directory.');
        }

        // Flysystem сам нормализует пути и запрещает обход каталога (..).
        $fs->move($sourceRel, $destRel);

        $targetVirtual = $this->mount->virtual($targetPath);

        return [
            'ok' => true,
            'sourcePath' => $this->mount->virtual($sourcePath),
            'targetPath' => $targetVirtual,
            'item' => $this->itemDto($destRel, $targetVirtual),
        ];
    }

    // ======= UPLOAD =======

    public function uploadFile(string $path): array
    {
        // Серверные проверки загрузки — в UploadPolicy (блок-лист расширений, лимит размера;
        // состав правил собирается в config/container.php). Новая валидация = новое правило
        // UploadRuleInterface — этот метод не меняется.
        // Контентную/MIME-валидацию намеренно НЕ включаем: много легитимных изображений с битой
        // MIME-типизацией ложно отвергаются. Когда понадобится — отдельное правило в UploadPolicy.

        $file = UploadedFile::getInstanceByName('file');
        if (!$file instanceof UploadedFile) {
            throw new DomainException('File for uploading is not found.');
        }

        $fs = $this->fs();
        $dirRel = $this->fsPath($path);
        if ($dirRel !== '' && !$fs->directoryExists($dirRel)) {
            throw new DomainException('Upload directory does not exist: ' . $path);
        }

        $fileName = $this->sanitizeName($file->name);

        // Политика проверяет ФАКТИЧЕСКОЕ имя записи (после санитизации); нарушение → 422.
        $this->policy?->validate($file, $fileName, $this->mount);

        $targetRel = ($dirRel === '' ? '' : $dirRel . '/') . $fileName;

        // Пишем потоком — не держим весь файл в памяти (важно для больших файлов и S3).
        $stream = @fopen($file->tempName, 'rb');
        if ($stream === false) {
            throw new DomainException('Cannot read uploaded file.');
        }
        try {
            $fs->writeStream($targetRel, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $dirVirtual = $this->mount->virtual($path);

        // Отчёт UploadResponse: фактическое имя после санитизации + полные метаданные созданного
        // файла (item) — фронтенд сможет валидировать/логировать и оптимистично класть файл
        // в FolderRegistry без повторного list.
        return [
            'path' => $dirVirtual,
            'fileName' => $fileName,
            'url' => $this->publicUrl($targetRel),
            'item' => $this->itemDto($targetRel, $dirVirtual),
        ];
    }

    // ======= DELETE =======

    public function deletePaths(array $paths): array
    {
        $fs = $this->fs();
        $results = [];

        foreach ($paths as $path) {
            $rel = $this->fsPath((string)$path);
            $virtual = $this->mount->virtual((string)$path);

            // Тип определяем ДО удаления (после — уже не узнать): контракт DeleteResponseDto
            // требует type во ВСЕХ ветках; 'unknown' — путь не существует/неопределим.
            $isFile = $fs->fileExists($rel);
            $isDir = !$isFile && $fs->directoryExists($rel);
            $type = $isFile ? 'file' : ($isDir ? 'folder' : 'unknown');

            try {
                if ($isFile) {
                    $fs->delete($rel);
                    $results[] = ['path' => $virtual, 'ok' => true, 'message' => 'Файл успешно удалён', 'type' => 'file'];
                } elseif ($isDir) {
                    $fs->deleteDirectory($rel);
                    $results[] = ['path' => $virtual, 'ok' => true, 'message' => 'Директория успешно удалена', 'type' => 'folder'];
                } else {
                    $results[] = ['path' => $virtual, 'ok' => false, 'message' => 'Ошибка: путь не является файлом или директорией, или не существует.', 'type' => 'unknown'];
                }
            } catch (FilesystemException $e) {
                // Per-item ошибки уходят клиенту внутри HTTP 200 — та же дисциплина, что
                // error mapping контроллёра: наружу нейтрально, детали (сообщение адаптера) в лог.
                Yii::error('Ошибка удаления ' . $virtual . ': ' . $e, __METHOD__);
                $results[] = ['path' => $virtual, 'ok' => false, 'message' => 'Внутренняя ошибка при удалении.', 'type' => $type];
            }
        }

        return [
            'results' => $results,
            'summary' => [
                'total' => count($results),
                'deleted' => count(array_filter($results, static fn($r) => !empty($r['ok']))),
                'failed' => count(array_filter($results, static fn($r) => empty($r['ok']))),
            ],
        ];
    }

    // ======= RENAME =======

    /**
     * Переименование объекта внутри директории $path.
     * Возвращает операционный отчёт RenameResponse (контракт TS-first): фактическое имя после
     * санитизации (может отличаться от запрошенного!) и состояние объекта после операции.
     */
    public function rename(string $path, string $oldName, string $newName): array
    {
        // oldName — ИМЯ внутри $path, а не путь: запрещаем разделители и спецсегменты, иначе клиент
        // мог бы оперировать объектами вне заявленной родительской директории (в пределах mount).
        $this->assertSingleSegment($oldName);

        $safeNewName = $this->sanitizeName($newName);
        if ($safeNewName === '') {
            throw new DomainException('New name is empty or invalid.');
        }

        // Имя-ограничения политики (блок-лист расширений) действуют и на rename — иначе они
        // обходились бы переименованием уже загруженного файла (safe.txt → shell.php).
        $this->policy?->validateName($safeNewName, $this->mount);

        $fs = $this->fs();
        $dirRel = $this->fsPath($path);
        $oldRel = ($dirRel === '' ? '' : $dirRel . '/') . $oldName;
        $newRel = ($dirRel === '' ? '' : $dirRel . '/') . $safeNewName;

        $isDir = $fs->directoryExists($oldRel);
        if (!$isDir && !$fs->fileExists($oldRel)) {
            throw new DomainException('Source does not exist: ' . $path);
        }
        if ($fs->has($newRel)) {
            throw new DomainException('Target already exists: ' . $safeNewName);
        }

        $fs->move($oldRel, $newRel);

        $virtualDir = $this->mount->virtual($path);

        return [
            'ok' => true,
            'path' => $virtualDir,
            'oldName' => $oldName,
            'newName' => $safeNewName,
            'item' => $this->itemDto($newRel, $virtualDir),
        ];
    }

    // ======= DOWNLOAD =======

    /**
     * Поток файла для скачивания ЧЕРЕЗ БЭКЕНД — для точек монтирования без публичной отдачи
     * (ZIP-архив, приватный S3); работает и для остальных. Дополняет `url: null` в FileDto.
     *
     * Контроллёр обязан отдавать строго attachment (не inline): inline-отдача пользовательского
     * контента (.html/.svg) с backend-домена — это хранимый XSS уже в админке.
     *
     * @return array{stream: resource, name: string, mimeType: string, size: int|null}
     */
    public function download(string $path): array
    {
        $fs = $this->fs();
        $rel = $this->fsPath($path);

        if (!$fs->fileExists($rel)) {
            throw new DomainException('Файл не найден: ' . $path);
        }

        $mime = 'application/octet-stream';
        try {
            $mime = $fs->mimeType($rel) ?: $mime;
        } catch (FilesystemException) {
            // тип не определился — отдаём как octet-stream
        }

        $size = null;
        try {
            $size = $fs->fileSize($rel);
        } catch (FilesystemException) {
            // размер не критичен (без Content-Length скачивание всё равно работает)
        }

        return [
            'stream' => $fs->readStream($rel),
            'name' => basename($rel),
            'mimeType' => $mime,
            'size' => $size,
        ];
    }

    // ======= ANALYZE =======

    /**
     * Глубокий анализ файла для вкладки "Анализ": MIME + HEX-дамп начала файла.
     */
    public function analyzeFile(string $path): array
    {
        $fs = $this->fs();
        $rel = $this->fsPath($path);

        if (!$fs->fileExists($rel)) {
            throw new DomainException('Файл не найден или недоступен: ' . $path);
        }

        $mime = 'application/octet-stream';
        try {
            $mime = $fs->mimeType($rel) ?: $mime;
        } catch (FilesystemException) {
            // оставляем octet-stream
        }

        // Читаем начало файла для HEX-дампа (1 КБ достаточно для превью)
        $stream = $fs->readStream($rel);
        $chunk = stream_get_contents($stream, 1024);
        if (is_resource($stream)) {
            fclose($stream);
        }
        $hexDump = $this->generateHexDump($chunk === false ? '' : $chunk);

        return [
            'mime' => $mime,
            'hexDump' => $hexDump,
            // Сюда можно будет легко добавить EXIF или другие метаданные
            'exif' => null, // Заглушка для будущего расширения
        ];
    }

    // Конфиг/возможности бэкенда собирает StorageManager::configDto() — он знает обо ВСЕХ
    // точках монтирования (контракт GetConfigResponse: global + переопределения по mount).

    /**
     * TODO - Заглушка, реализовать
     * Обработчик загрузки через 'Simple Upload Adapter'.
     * При реализации ОБЯЗАН пройти ту же {@see UploadPolicy} ($this->policy), что и uploadFile().
     */
    public function sua(mixed $post): array
    {
        if (true) {
            return ['url' => 'https://ckeditor.com/docs/ckeditor5/latest/assets/img/volcano_2x.jpg'];
        } else {
            throw new DomainException('The image upload failed because the image was too big (max 1.5MB).');
        }
    }

    // ======= ХЕЛПЕРЫ =======

    /**
     * Публичный URL файла либо null, если у точки монтирования нет публичной отдачи
     * (пустой baseUrl, например ZIP-mount без download-эндпоинта).
     * Контракт FileDto.url: string|null — null честнее битого относительного URL.
     */
    private function publicUrl(string $rel): ?string
    {
        return $this->mount->baseUrl === '' ? null : $this->mount->url($rel);
    }

    /**
     * FileDto объекта (файла или директории) по его относительному пути — для операционных
     * отчётов (rename/move/upload): фронтенд валидирует и логирует операцию по фактическому
     * состоянию объекта после неё.
     */
    private function itemDto(string $rel, string $parentVirtual): array
    {
        $fs = $this->fs();
        $name = basename($rel);

        // если это директория — вернём в формате FileDto как "directory"
        if ($fs->directoryExists($rel)) {
            return [
                'name' => $name,
                'path' => $parentVirtual,
                'extension' => null,
                'type' => 'dir',
                'fileType' => 'directory',
                'url' => null,
                'meta' => $this->dirFileMeta(),
            ];
        }

        // иначе — файл
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $mime = null;
        try {
            $mime = $fs->mimeType($rel);
        } catch (FilesystemException) {
            // тип не определился — не критично
        }

        return [
            'name' => $name,
            'path' => $parentVirtual,
            'extension' => $extension,
            'type' => 'file',
            'fileType' => $mime ?: $extension,
            'url' => $this->publicUrl($rel),
            'meta' => $this->fileMetaFor($fs, $rel),
        ];
    }

    /**
     * Проверяет, что значение — одиночное имя файла/директории (один сегмент пути),
     * а не путь: без '/', '\', NUL-байта и спецсегментов '.'/'..'.
     */
    private function assertSingleSegment(string $name): void
    {
        if (
            $name === '' || $name === '.' || $name === '..'
            || str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, "\0")
        ) {
            throw new DomainException('Некорректное имя: ожидается имя файла/директории без разделителей пути.');
        }
    }

    private function sanitizeName(string $name): string
    {
        $name = trim($name);

        // Разрешаем только: буквы, цифры, дефис, подчёркивание, точку
        // НО не в начале имени (если в начале имени точка, то это скрытый файл, если минус, то потом не читается в методе чтения)
        $name = preg_replace('/[^a-zA-Z0-9а-яА-ЯёЁ._-]/u', '_', $name);

        // Убираем дефис/точку из начала
        $name = ltrim($name, '-.');

        // Если после очистки имя пустое — генерируем случайное
        if ($name === '') {
            $name = 'file_' . uniqid();
        }

        return $name;
    }

    /**
     * Метаданные файла в форме, ожидаемой фронтендом (FileMeta требует строго положительные
     * mTime/aTime/permissions и size>=0). Поля прав/времени адаптеро-независимы (см. шапку класса).
     */
    private function fileMeta(?int $mtime, ?int $size, ?string $visibility): array
    {
        $mtime = ($mtime !== null && $mtime > 0) ? $mtime : time();
        $size = ($size !== null && $size >= 0) ? $size : 0;

        return [
            'isWritable' => true,   // фактический контроль доступа — на бэкенде/RBAC, не в правах ФС
            'isReadable' => true,
            'isExecutable' => false,
            'permissions' => $this->visibilityToMode($visibility, false),
            'mTime' => $mtime,
            'aTime' => $mtime,      // у Flysystem нет времени доступа
            'size' => $size,
            'dimensions' => null,   // размеры изображений тут не вычисляем (адаптеро-независимо)
        ];
    }

    /** Собирает метаданные файла, опрашивая Flysystem поштучно (для rename/после операций). */
    private function fileMetaFor(Filesystem $fs, string $rel): array
    {
        $mtime = $size = null;
        $visibility = null;
        try {
            $mtime = $fs->lastModified($rel);
        } catch (FilesystemException) {
        }
        try {
            $size = $fs->fileSize($rel);
        } catch (FilesystemException) {
        }
        try {
            $visibility = $fs->visibility($rel);
        } catch (FilesystemException) {
        }
        return $this->fileMeta($mtime, $size, $visibility);
    }

    /**
     * Метаданные директории (FolderMeta на фронтенде мягче: допускает нули, но даём осмысленные значения).
     */
    private function dirMeta(?int $mtime = null): array
    {
        $mtime = ($mtime !== null && $mtime > 0) ? $mtime : time();
        return [
            'permissions' => $this->visibilityToMode(null, true),
            'mTime' => $mtime,
            'aTime' => $mtime,
            'size' => 0,
        ];
    }

    /**
     * Метаданные директории в ПОЛНОЙ форме FileMetaDto — для ответов, где папка идёт как
     * FileDto (createDir, item в rename/move). В листинге (DirDto.meta) остаётся {@see dirMeta()}.
     */
    private function dirFileMeta(?int $mtime = null): array
    {
        return [
            'isWritable' => true,   // фактический контроль доступа — на бэкенде/RBAC, не в правах ФС
            'isReadable' => true,
            'isExecutable' => false,
            'dimensions' => null,
        ] + $this->dirMeta($mtime);
    }

    /**
     * Синтетический POSIX-режим для отображения «прав» на фронтенде (у S3/ZIP их нет).
     * Возвращает положительное число — требование FileMeta (permissions > 0).
     */
    private function visibilityToMode(?string $visibility, bool $isDir): int
    {
        if ($isDir) {
            return $visibility === 'private' ? 0750 : 0755;
        }
        return $visibility === 'private' ? 0600 : 0644;
    }

    /**
     * Генерация форматированного HEX-дампа (Offset | Hex | ASCII)
     */
    private function generateHexDump(string $data): string
    {
        $hex = str_split(bin2hex($data), 2);
        $chars = str_split($data);
        $offset = 0;
        $output = "";

        foreach (array_chunk($hex, 16) as $chunk) {
            // Колонка Offset (8 знаков)
            $output .= sprintf("%08x", $offset) . "  ";

            // Колонка Hex байтов (разбиваем по 8 для визуального разделения)
            $hexPart = array_chunk($chunk, 8);
            $hexStrings = array_map(fn($c) => implode(" ", $c), $hexPart);
            $output .= str_pad(implode("  ", $hexStrings), 48, " ");

            // Колонка ASCII
            $output .= " |";
            for ($i = 0; $i < 16; $i++) {
                if (isset($chars[$offset + $i])) {
                    $char = $chars[$offset + $i];
                    $output .= (ord($char) >= 32 && ord($char) <= 126) ? $char : ".";
                } else {
                    $output .= " ";
                }
            }
            $output .= "|\n";
            $offset += 16;
        }

        return $output;
    }
}
