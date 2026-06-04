<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

namespace Besnovatyj\File\services;

use Besnovatyj\File\storage\StorageMount;
use DomainException;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use yii\base\InvalidArgumentException;
use yii\web\UploadedFile;
use const PHP_MAXPATHLEN;

// https://symfony.com/doc/current/components/finder.html
// https://symfony.com/doc/current/components/filesystem.html
// https://symfony.ru/doc/current/components/filesystem.html
// https://symfony.ru/doc/current/components/finder.html
class FileManagerService
{
    /**
     * Точка монтирования, к которой привязан сервис. Все обращения к ФС идут ТОЛЬКО через
     * {@see StorageMount::path()} — единый traversal-safe примитив (защита от обхода директорий).
     */
    public StorageMount $mount;
    public string $realRoot = ''; // Указывается базовая директория, где разрешена работа с файлами. Это предотвращает возможность обхода директорий.
    public string $baseUrl = '';

    public function __construct(StorageMount $mount)
    {
        // Сервис привязан к одной точке монтирования (mount). realRoot/baseUrl продублированы из неё
        // для краткости внутренних обращений; источник истины и проверка границ — в самом mount.
        $this->mount = $mount;
        $this->realRoot = $mount->realRoot;
        $this->baseUrl = $mount->baseUrl;
    }

    function getFolderDto(string $path): array
    {
        // $path — путь ВНУТРИ точки монтирования ('' = её корень). mount->path() безопасно отображает
        // его в абсолютный путь и не выпускает за пределы realRoot (защита от Directory Traversal).
        $realPath = $this->mount->path($path);

        $this->checkPathLength($realPath);

        if (!is_dir($realPath)) {
            throw new InvalidArgumentException("Directory $path does not exist");
        }

        // Корень точки монтирования показываем под её id (чтобы ключ на фронтенде был '/{id}').
        $isMountRoot = (trim($path, '/') === '');
        // Виртуальный путь самой директории — он же родитель для её дочерних элементов на фронтенде.
        $dirVirtual = $this->mount->virtual($path);

        $rootSpl = new SplFileInfo($realPath);

        $data = [
            // название директории без пути к ней (для корня точки монтирования — её id)
            'name' => $isMountRoot ? $this->mount->id : $rootSpl->getBasename(),
            // виртуальный путь родителя этой директории (без её собственного имени)
            'path' => $this->mount->virtualParent($path),
            'type' => $rootSpl->getType(),
//            'countChildDirs' => $this->hasChildDirs($rootSpl->getPathname()),
            'countChildDirs' => $this->countChildDirs($rootSpl->getPathname()),
//            'countChildFiles' => $this->hasChildFiles($rootSpl->getPathname()),
            'countChildFiles' => $this->countChildFiles($rootSpl->getPathname()),
            'meta' => [
                'permissions' => $rootSpl->getPerms(),
                'mTime' => $rootSpl->getMTime(),
                'aTime' => $rootSpl->getATime(),
                'size' => 000,  // Вычисляемое
            ],
            'folders' => [],
            'files' => [],
        ];

        $fsIterator = new FilesystemIterator($realPath);

        if ($fsIterator->valid()) {
            foreach ($fsIterator as $file) {

                $file->getPathInfo(); // Метод получает объект класса SplFileInfo для родителя текущего файла
                $file->getBasename('.' . $file->getExtension()); // Имя файла без расширения

                if ($file->isDir()) {

//                    $countChildDirs = $this->hasChildDirs($file->getPathname());
                    $countChildDirs = $this->countChildDirs($file->getPathname());
//                    $countChildFiles = $this->hasChildFiles($file->getPathname());
                    $countChildFiles = $this->countChildFiles($file->getPathname());

                    $data['folders'][] = [
                        'name' => $file->getBasename(), // https://www.php.net/manual/ru/splfileinfo.getfilename.php#118067
                        'path' => $dirVirtual, // родитель ребёнка — текущая директория (виртуальный путь)
                        'type' => $file->getType(), // file, link, dir, block, fifo, char, socket, unknown
                        'countChildDirs' => $countChildDirs,
                        'countChildFiles' => $countChildFiles,
                        'meta' => [
                            'permissions' => $file->getPerms(),
                            'mTime' => $file->getMTime(),
                            'aTime' => $file->getATime(),
                            'size' => 000,  // Вычисляемое
                        ],
                        'folders' => [],
                        'files' => [],
                    ];
                }
                if ($file->isFile()) {
                    $data['files'][] = [
                        'name' => $file->getFilename(), // https://www.php.net/manual/ru/splfileinfo.getfilename.php#118067
                        'path' => $dirVirtual, // родитель ребёнка — текущая директория (виртуальный путь)
                        'extension' => $file->getExtension(),
                        'type' => $file->getType(),  // file, link, dir, block, fifo, char, socket, unknown
                        'fileType' => 'TODO - человекопонятный тип файла', // Вычисляемое
                        'url' => $this->mount->url(mb_substr($file->getPath(), mb_strlen($this->realRoot)) . '/' . $file->getFilename()),
                        'meta' => [
                            'isWritable' => $file->isWritable(),
                            'isReadable' => $file->isReadable(),
                            'isExecutable' => $file->isExecutable(),
                            'permissions' => $file->getPerms(),
                            'mTime' => $file->getMTime(),
                            'aTime' => $file->getATime(),
                            'size' => $file->getSize(),
                            'dimensions' => [
                                'height' => 500,
                                'width' => 800,
                            ],
                        ],
                    ];
                }

                if ($file->isLink()) {
                    $data['links'][] = [
                        'name' => $file->getFilename(),
                        'type' => $file->getType(),  // file, link, dir, block, fifo, char, socket, unknown
                    ];
                }

            }
        }

        return $data;
    }

    /**
     * Get the mime-type of a given file.
     * @param string $path
     * @return false|string
     */
    public function mimeType(string $path): false|string
    {
        return finfo_file(finfo_open(FILEINFO_MIME_TYPE), $path);
    }

    private function countChildDirs(string $dir): int
    {
        $counter = 0;
        $fsIterator = $this->getFilesystemIterator($dir);
        if ($fsIterator->valid()) { // valid == 1 only if dir not empty
            foreach ($fsIterator as $fileInfo) {
                if ($fileInfo->isDir()) {
                    $counter++;
                }
            }
        }
        return $counter;
    }

    private function countChildFiles(string $dir): int
    {
        $counter = 0;
        $fsIterator = $this->getFilesystemIterator($dir);
        if ($fsIterator->valid()) { // valid == 1 only if dir not empty
            foreach ($fsIterator as $fileInfo) {
                if ($fileInfo->isFile()) {
                    $counter++;
                }
            }
        }
        return $counter;
    }

    public function createDir(string $parentPath, string $name): array
    {
        $safeName = $this->sanitizeName($name);
        if ($safeName === '') {
            throw new DomainException('Directory name is empty or invalid.');
        }

        // mount->path() гарантирует, что родитель существует и лежит внутри realRoot (Directory Traversal).
        $realParent = $this->mount->path($parentPath);

        if (!is_dir($realParent)) {
            throw new DomainException('Parent directory does not exist: ' . $parentPath);
        }

        $realDir = $realParent . DIRECTORY_SEPARATOR . $safeName;

        $this->checkPathLength($realDir);

        if (file_exists($realDir)) {
            throw new DomainException('File or directory already exists: ' . $safeName);
        }

        if (!mkdir($realDir, 0775, false)) {
            throw new DomainException('Failed to create directory: ' . $safeName);
        }

        $spl = new \SplFileInfo($realDir);

        // виртуальный путь родителя новой директории (без её собственного имени)
        $relativePath = $this->mount->virtual($parentPath);

        return [
            'name' => $spl->getBasename(),       // 'new-folder'
            'path' => $relativePath,             // '/{mountId}/files/blog'
            'extension' => null,
            'type' => $spl->getType(),           // 'dir'
            'fileType' => 'directory',
            'url' => null,
            'meta' => [
                'isWritable' => $spl->isWritable(),
                'isReadable' => $spl->isReadable(),
                'isExecutable' => $spl->isExecutable(),
                'permissions' => $spl->getPerms(),
                'mTime' => $spl->getMTime(),
                'aTime' => $spl->getATime(),
                'size' => 0,
                'dimensions' => null,
            ],
        ];
    }

    public function move(string $sourcePath, string $targetPath): bool
    {
        // Источник и целевая директория проходят один и тот же traversal-safe резолв mount->path()
        // (раньше цель НЕ конфайнилась — это и была дыра обхода каталога при перемещении).
        $sourceReal = $this->mount->path($sourcePath);

        $targetDirReal = $this->mount->path($targetPath);
        if (!is_dir($targetDirReal)) {
            throw new DomainException('Target directory does not exist: ' . $targetPath);
        }

        $baseName = basename($sourceReal);
        $destReal = $targetDirReal . DIRECTORY_SEPARATOR . $baseName;

        $this->checkPathLength($destReal);

        if (file_exists($destReal)) {
            throw new DomainException('Target already exists in destination directory.');
        }

        if (!is_writable(dirname($sourceReal)) || !is_writable($targetDirReal)) {
            throw new DomainException('Not enough permissions to move.');
        }
        // TODO
        //  Функция rename() также может быть использована для перемещения директорий между разными путями (если файловая система поддерживает это)
        //  Однако, если директория содержит большое количество файлов, это может занять значительное время.
        //  Если $oldname является символической ссылкой, то rename() переименует саму ссылку, а не целевой объект.

        if (!@rename($sourceReal, $destReal)) {
            throw new DomainException('Failed to move.');
        }

        return true;
    }

    public function getConfigDto(): array
    {
        return [
            'fileMaxSize' => '',
            'allowedMimeTypes' => [
                // TODO in js: `<input type="file" id="fileInput" accept="image/*" />`
                // TODO in js: `if (file && file.type.startsWith('image/')) {}`
                'image/*',
                'image/jpeg',
                'image/png',
            ],
        ];
    }

    private function hasChildDirs(string $dir): bool
    {
        $fsIterator = $this->getFilesystemIterator($dir);
        if ($fsIterator->valid()) { // valid == 1 only if dir not empty
            foreach ($fsIterator as $fileInfo) {
                if ($fileInfo->isDir()) {
                    return true;
                }
            }
        }
        return false;
    }

    private function hasChildFiles(string $dir): bool
    {
        $fsIterator = $this->getFilesystemIterator($dir);
        if ($fsIterator->valid()) { // valid == 1 only if dir not empty
            foreach ($fsIterator as $fileInfo) {
                if ($fileInfo->isFile()) {
                    return true;
                }
            }
        }
        return false;
    }

    private function isEmpty(string $dir): bool
    {
        $fsIterator = $this->getFilesystemIterator($dir);
        return !$fsIterator->valid(); // valid == 1 only if dir not empty
    }

    private function getFilesystemIterator($dir): FilesystemIterator
    {
        if (!is_dir($dir)) {
            throw new DomainException('Directory "' . $dir . '" does not exist.');
        }
        return new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS);
    }

    private function checkPathLength(string $file): void
    {
        $maxPathLength = PHP_MAXPATHLEN - 2;
        if (strlen($file) > $maxPathLength) {
            throw new RuntimeException('Path length is more than' . $maxPathLength . ' characters.');
        }
    }

    private function getDirSizeRecursive(string $dir): int
    { // TODO Использовать FilesystemHelper
        $recDirIterator = new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS);
        $recDirIterator->setFlags(FilesystemIterator::FOLLOW_SYMLINKS);
        $it = new RecursiveIteratorIterator($recDirIterator);
        $size = 0;
        foreach ($it as $fi) {
            $size += $fi->getSize();
        }
        return $size;
    }

    public function uploadFile(string $path): array
    {

        // TODO Надо кучу проверок или чужую библиотеку (как в ckfinder).
        // TODO В любом случае надо проверять директорию куда загружаем (имеем ли право, существует ли)
        // TODO В любом случае надо проверять файлы на безопасность
        // TODO По итогу всё равно надо отвязываться от Yii2

        $file = UploadedFile::getInstanceByName('file');

        if (!$file instanceof UploadedFile) {
            throw new DomainException('File for uploading is not found.');
        }

        // mount->path() гарантирует, что директория загрузки существует и лежит внутри realRoot
        // (traversal-safe). Контентную валидацию файла здесь намеренно НЕ делаем — см. TODO выше.
        $realPath = $this->mount->path($path);

        if (!is_dir($realPath)) {
            throw new DomainException('Upload directory does not exist: ' . $path);
        }

        $fileName = $file->name;
        $fileName = $this->sanitizeName($fileName);

        $this->checkPathLength($realPath . DIRECTORY_SEPARATOR . $fileName);

        if (!$file->saveAs($realPath . DIRECTORY_SEPARATOR . $fileName)) {
            throw new DomainException('File saving error.');
        }

        return [
            'path' => $this->mount->virtual($path),
            'fileName' => $fileName,
            'url' => $this->mount->url($path . '/' . $fileName),
            // сюда же можно добавить мета-данные, если нужно сразу
        ];

    }

    // ======= DELETE =======

    public function deletePaths(array $paths): array
    {
        $results = [];
        foreach ($paths as $path) {
            try {
                // Безопасно разрешаем путь внутри точки монтирования (traversal-safe). Если записи нет,
                // mount->path() бросит исключение — оно попадёт в catch как «путь не существует».
                $fullPath = $this->mount->path($path);

                if (is_file($fullPath)) {
                    $this->deleteFile($path);
                    $results[] = [
                        'path' => $this->mount->virtual($path),
                        'ok' => true,
                        'message' => 'Файл успешно удалён',
                        'type' => 'file'
                    ];
                } elseif (is_dir($fullPath)) {
                    $this->deleteDirectory($path);
                    $results[] = [
                        'path' => $this->mount->virtual($path),
                        'ok' => true,
                        'message' => 'Директория успешно удалена',
                        'type' => 'folder'
                    ];
                } else {
                    // Если путь не существует, или это что-то другое (ссылка, сокет)
                    $results[] = [
                        'path' => $this->mount->virtual($path),
                        'ok' => false,
                        'message' => 'Ошибка: путь не является файлом или директорией, или не существует.'
                    ];
                }
            } catch (\DomainException|\RuntimeException $e) {
                $results[] = [$path, false, 'Ошибка удаления: ' . $e->getMessage()];
            }
        }
        return [
            'results' => $results,
            'summary' => [
                'total' => count($paths),
                'deleted' => count(array_filter($results, fn($r) => $r['ok'])),
                'failed' => count(array_filter($results, fn($r) => !$r['ok'])),
            ]
        ];

    }

    public function deleteFiles(array $paths): array
    {
        $files = [];
        foreach ($paths as $filePath) {
            try {
                $this->deleteFile($filePath);
                $files[] = [$filePath, 'Файл успешно удалён'];
            } catch (\DomainException $e) {
                $files[] = [$filePath, 'Ошибка удаления файла: ' . $e->getMessage()];
            }
        }
        return [
            'files' => $files,
        ];
    }

    /**
     * Удаляет файл по указанному пути.
     * @param string $userPath Путь к файлу, который нужно удалить.
     * @return bool Возвращает true в случае успешного удаления файла, иначе false.
     * @throws DomainException Выбрасывает исключение при возникновении ошибок.
     */
    public function deleteFile(string $userPath): bool
    {
        // 1. Формируем полный путь и нормализуем его
        $fullPath = $this->realRoot . $userPath;

        // 2. Используем realpath для разрешения символических ссылок и сокращений типа ".."
        // Если злоумышленник создает символическую ссылку внутри разрешенной директории, которая указывает на внешний файл (`/allowed_root/my_link -> /etc/passwd`), `realpath()` раскроет истинный путь.
        $realPath = realpath($fullPath);

        if ($realPath === false) {
            throw new DomainException("Файл не существует или недоступен: " . $userPath);
        }

        // 3. !!! КРИТИЧЕСКАЯ ПРОВЕРКА БЕЗОПАСНОСТИ !!!
        // Проверяем, что реальный путь начинается с realRoot. Это предотвращает Directory Traversal.
        // Ваша проверка `!str_starts_with($realPath, $this->realRoot)` гарантирует, что даже если символическая ссылка указывает за пределы `$this->realRoot`, попытка удаления будет заблокирована, так как раскрытый путь не будет начинаться с `$this->realRoot`.
        if (!str_starts_with($realPath, $this->realRoot)) {
            // Логирование попытки обхода директорий может быть полезным.
            throw new DomainException("Попытка обхода корневой директории: " . $userPath);
        }

        // 4. Проверяем, что это ФАЙЛ, а не директория, если цель функции — удалять только файлы
        if (!is_file($realPath)) {
            throw new DomainException("Указанный путь не является файлом: " . $userPath);
        }

        // Проверяем, существует ли файл
        // if (!file_exists($filePath)) {
        //      throw new DomainException("Файл не существует: " . $filePath);
        // }

        // 5. Проверяем права доступа. Для unlink обычно нужны права на запись в родительской директории.
        // Хотя проверка на is_writable($realPath) может быть достаточной,
        // более строгая проверка:
        if (!is_writable(dirname($realPath))) {
            // Для удаления файла требуется право на запись (w) в РОДИТЕЛЬСКОЙ директории.
            throw new DomainException("Нет прав на удаление файла в директории: " . dirname($userPath));
        }

        // 6. Удаляем файл.
        // Используем @, чтобы не выбрасывать PHP-ошибку, а обработать неудачу через возвращаемое значение.
        if (@unlink($realPath)) {
            return true;
        } else {
            throw new DomainException("Не удалось удалить файл: " . $userPath);
        }

    }

    /**
     * Удаляет директорию (включая непустые) по относительному пути.
     *
     * @param string $userPath Относительный путь к директории, которую нужно удалить (напр. '/files/my-folder').
     * @return bool Возвращает true в случае успешного удаления.
     * @throws DomainException Выбрасывает исключение при возникновении ошибок.
     */
    public function deleteDirectory(string $userPath): bool
    {
        // 1. Формируем полный путь и нормализуем его
        $fullPath = $this->realRoot . $userPath;

        // 2. Используем realpath для разрешения символических ссылок и сокращений типа ".."
        $realPath = realpath($fullPath);

        // Проверка существования/доступности и обхода директорий
        if ($realPath === false) {
            throw new DomainException("Директория не существует или недоступна: " . $userPath);
        }

        // !!! КРИТИЧЕСКАЯ ПРОВЕРКА БЕЗОПАСНОСТИ !!!
        // Проверяем, что реальный путь остается внутри корневой директории.
        if (!str_starts_with($realPath, $this->realRoot)) {
            throw new DomainException("Попытка обхода корневой директории при удалении: " . $userPath);
        }

        // 3. Проверяем, что это ДИРЕКТОРИЯ
        if (!is_dir($realPath)) {
            throw new DomainException("Указанный путь не является директорией: " . $userPath);
        }

        // 4. Проверяем права доступа
        // Для удаления директории (даже пустой) требуются права на запись (w) в РОДИТЕЛЬСКОЙ директории.
        if (!is_writable(dirname($realPath))) {
            throw new DomainException("Нет прав на удаление директории в: " . dirname($userPath));
        }

        // 5. Рекурсивное удаление содержимого и самой директории.
        // Используем вашу вспомогательную функцию clearDir с флагом $selfFlag = true
        if ($this->clearDir($realPath, true)) {
            // Дополнительная проверка: убедимся, что директория действительно удалена
            if (!file_exists($realPath)) {
                return true;
            } else {
                // Это может произойти, если rmdir внутри clearDir не сработал
                throw new DomainException("Не удалось удалить директорию: " . $userPath);
            }
        } else {
            throw new DomainException("Не удалось очистить содержимое директории перед удалением: " . $userPath);
        }
    }

    public static function clearDir(string $dir, bool $selfFlag = false): bool
    {   // TODO или Использовать FilesystemHelper
        // TODO Сделать как в \Symfony\Component\Filesystem::doRemove() // See https://bugs.php.net/52176
        if (!is_dir($dir)) {
            throw new RuntimeException('Trying to clean up a non-existent directory.');
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $fileInfo) {
            $realPath = $fileInfo->getRealPath();

            if ($fileInfo->isDir()) {
                if (!@rmdir($realPath)) {
                    // Если не удалось удалить поддиректорию, выбрасываем исключение
                    throw new RuntimeException("Не удалось удалить поддиректорию: " . $realPath);
                }
            } elseif ($fileInfo->isFile()) {
                if (!@unlink($realPath)) {
                    // Если не удалось удалить файл, выбрасываем исключение
                    throw new RuntimeException("Не удалось удалить файл: " . $realPath);
                }
            }
            // Игнорируем символические ссылки, сокеты и т.п. для чистоты операции
        }

        if ($selfFlag) {
            if (@rmdir($dir)) {
                return true;
            } else {
                // Если не удалось удалить саму корневую директорию
                throw new RuntimeException("Не удалось удалить корневую директорию: " . $dir);
            }
        }

        // Если $selfFlag = false, возвращаем true, если директория пуста
        $iterator = new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS);
        return !$iterator->valid();
    }

    // ======= DELETE  END =======

    /** @see https://www.php.net/manual/ru/function.fileperms.php */
    protected function formatPermissions($perms): string
    {
        switch ($perms & 0xF000) {
            case 0xC000: // сокет
                $info = 's';
                break;
            case 0xA000: // символическая ссылка
                $info = 'l';
                break;
            case 0x8000: // обычный
                $info = 'r';
                break;
            case 0x6000: // файл блочного устройства
                $info = 'b';
                break;
            case 0x4000: // каталог
                $info = 'd';
                break;
            case 0x2000: // файл символьного устройства
                $info = 'c';
                break;
            case 0x1000: // FIFO канал
                $info = 'p';
                break;
            default: // неизвестный
                $info = 'u';
        }

        // Владелец
        $info .= (($perms & 0x0100) ? 'r' : '-');
        $info .= (($perms & 0x0080) ? 'w' : '-');
        $info .= (($perms & 0x0040) ?
            (($perms & 0x0800) ? 's' : 'x') :
            (($perms & 0x0800) ? 'S' : '-'));

        // Группа
        $info .= (($perms & 0x0020) ? 'r' : '-');
        $info .= (($perms & 0x0010) ? 'w' : '-');
        $info .= (($perms & 0x0008) ?
            (($perms & 0x0400) ? 's' : 'x') :
            (($perms & 0x0400) ? 'S' : '-'));

        // Мир
        $info .= (($perms & 0x0004) ? 'r' : '-');
        $info .= (($perms & 0x0002) ? 'w' : '-');
        $info .= (($perms & 0x0001) ?
            (($perms & 0x0200) ? 't' : 'x') :
            (($perms & 0x0200) ? 'T' : '-'));

        return $info;
    }

    public function rename(string $path, string $oldName, string $newName): array
    {
        // $path — каталог ВНУТРИ точки монтирования ('' = её корень). Конфайн обеспечивает realpath ниже.
        $safeNewName = $this->sanitizeName($newName);
        if ($safeNewName === '') {
            throw new DomainException('New name is empty or invalid.');
        }

        // Нормализуем исходный полный путь, чтобы избежать атак путем обхода директорий.
        $oldRealPath = realpath($this->realRoot . $path . DIRECTORY_SEPARATOR . $oldName);
        if ($oldRealPath === false) {
            throw new DomainException('Source does not exist: ' . $path);
        }

        if (!str_starts_with($oldRealPath, $this->realRoot)) {
            throw new DomainException('Source is outside of base directory.');
        }

        $dirReal = dirname($oldRealPath);
        $newRealPath = $dirReal . DIRECTORY_SEPARATOR . $safeNewName;

        $this->checkPathLength($newRealPath);

        if (file_exists($newRealPath)) {
            throw new DomainException('Target already exists: ' . $safeNewName);
        }

        if (!is_writable($oldRealPath) || !is_writable($dirReal)) {
            throw new DomainException('Not enough permissions to rename.');
        }

        if (!@rename($oldRealPath, $newRealPath)) {
            throw new DomainException('Failed to rename.');
        }

        $spl = new \SplFileInfo($newRealPath);

        // относительный путь каталога (без имени файла/папки), mount-internal — для URL
        $relativeDir = mb_substr($dirReal, mb_strlen($this->realRoot));
        $relativeDir = $relativeDir === false ? '' : $relativeDir;
        // виртуальный путь того же каталога — для навигации на фронтенде
        $virtualDir = $this->mount->virtual($relativeDir);

        // TODO
        // TODO Протестировать все случаи переименования (мой вариант переименовывал файл в уже существующий)
        // TODO Если $oldName является символической ссылкой, то rename() переименует саму ссылку, а не целевой объект.
        // TODO Возвращать timestamp операции для логирования? $data['timestamp'] = (new \DateTime())->getTimestamp();
        // TODO Возвращать статус операции, нет смысла возвращать данные о директории или файле
        // TODO


        // если это директория — вернём в формате FileDto как "directory"
        if ($spl->isDir()) {
            return [
                'name' => $spl->getBasename(),
                'path' => $virtualDir,      // '/files/blog'
                'extension' => null,
                'type' => $spl->getType(),   // 'dir'
                'fileType' => 'directory',
                'url' => null,
                'meta' => [
                    'isWritable' => $spl->isWritable(),
                    'isReadable' => $spl->isReadable(),
                    'isExecutable' => $spl->isExecutable(),
                    'permissions' => $spl->getPerms(),
                    'mTime' => $spl->getMTime(),
                    'aTime' => $spl->getATime(),
                    'size' => 0,
                    'dimensions' => null,
                ],
            ];
        }

        // иначе — файл
        $extension = $spl->getExtension();
        $url = $this->mount->url($relativeDir . '/' . $spl->getFilename());
        $fileType = $this->mimeType($newRealPath) ?: $extension;

        return [
            'name' => $spl->getFilename(),
            'path' => $virtualDir,
            'extension' => $extension,
            'type' => $spl->getType(),       // 'file'
            'fileType' => $fileType,
            'url' => $url,
            'meta' => [
                'isWritable' => $spl->isWritable(),
                'isReadable' => $spl->isReadable(),
                'isExecutable' => $spl->isExecutable(),
                'permissions' => $spl->getPerms(),
                'mTime' => $spl->getMTime(),
                'aTime' => $spl->getATime(),
                'size' => $spl->getSize(),
                'dimensions' => null, // TODO: если надо — читать размеры для изображений
            ],
        ];
    }


    /**
     * TODO - Заглушка, реализовать
     * Обработчик загрузки через 'Simple Upload Adapter'.
     */
    public function sua(mixed $post): array
    {
        if (true) {
            return ['url' => 'https://ckeditor.com/docs/ckeditor5/latest/assets/img/volcano_2x.jpg'];
        } else {
            throw new DomainException('The image upload failed because the image was too big (max 1.5MB).');
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
     * Глубокий анализ файла для вкладки "Анализ"
     * @param string $path Относительный путь от realRoot (например, '/origin/image.png')
     * @return array Структура согласно FileAnalysisDto
     * @throws DomainException|\Exception
     */
    public function analyzeFile(string $path): array
    {
        // 1. Формируем полный путь и проверяем его безопасность (как в методе deleteFile)
        $fullPath = $this->realRoot . $path;
        $realPath = realpath($fullPath);

        if ($realPath === false || !is_file($realPath)) {
            throw new DomainException("Файл не найден или недоступен: " . $path);
        }

        // Проверка Directory Traversal
        if (!str_starts_with($realPath, $this->realRoot)) {
            throw new DomainException("Попытка доступа вне разрешенной директории.");
        }

        // 2. Определяем MIME-тип через существующий метод сервиса или напрямую
        // В вашем классе уже есть метод mimeType(), используем его
        $mime = $this->mimeType($realPath) ?: 'application/octet-stream';

        // 3. Читаем начало файла для HEX-дампа (256 байт достаточно для превью)
        $handle = @fopen($realPath, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Не удалось открыть файл для чтения.");
        }
        $chunk = fread($handle, 1024);
        fclose($handle);

        // 4. Генерируем HEX-строку
        $hexDump = $this->generateHexDump($chunk);

        // Возвращаем DTO, который ожидает фронтенд
        return [
            'mime' => $mime,
            'hexDump' => $hexDump,
            // Сюда можно будет легко добавить EXIF или другие метаданные
            'exif' => null, // Заглушка для будущего расширения
        ];
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
