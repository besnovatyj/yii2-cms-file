# Файловый менеджер: безопасность + мульти-рут на Flysystem — статус

> Срез на 2026-06-04. Журнал изменений + остаток. Выполнено: **P0-безопасность (traversal)**, **P0-frontend**,
> **mount-архитектура** и **полный переход на League\Flysystem v3** с адаптерами Local / ZipArchive / AWS S3 (v3).
> Часть находок осознанно отложена (отмечено). Исходный аналитический документ — в
> `adaptive-scribbling-pixel.md` (не изменяется).
>
> Объекты: `app/packages/besnovatyj/yii2-cms-file/src/` (сервис, контроллёры, слой `storage/`),
> `app/packages/npm/filemanager-core/src`.

## Context

Триггер — «почему ФМ не обрабатывает ответ с `path:""`». Это не баг данных: сервер намеренно прячет реальный корень и
отдаёт пути относительно него, где `''` = корень; падала клиентская валидация. Разбор вырос в задачу: сделать модель
«фронт говорит виртуальными путями — бэкенд работает с разными хранилищами» безопасной и мульти-рут/мульти-адаптер
(local + AWS S3 + ZIP + далее FTP). Решение: P0-безопасность точечно, затем **полноценный переход на Flysystem** с тремя
адаптерами; контентную/MIME-валидацию загрузки НЕ трогаем (много легитимных изображений с битой MIME-типизацией).

---

## 1. Контракт путей (виртуальная адресация)

Фронтенд видит единую «как будто локальную» ФС; реальные расположения скрыты внутри Flysystem-адаптеров:

```
'/'               → виртуальный корень: перечень точек монтирования как папок
'/{mountId}'      → корень точки монтирования (static / zip / aws)
'/{mountId}/sub'  → вложенный путь внутри неё
```

- DTO отдают **виртуальные** пути (`/{mountId}/...`) в поле `path`; `url` файла — от `baseUrl` точки монтирования
  (статик-домен / CDN / S3), отвязан от API-коннектора.
- Семантика **`'' = корень`** сохранена и легитимна (релакс валидаторов на фронте выполнен).

---

## 2. Security BACKEND — статус

### ✅ Исправлено

**2.1. Directory Traversal (`list`/`mkdir`/`upload`/`move`-цель).** Был рассинхрон: голый `realRoot . $path` в части
методов. → Все операции теперь идут через **League\Flysystem**, который нормализует пути и запрещает `..`; первый рубеж
— `VirtualPath::parse()` (запрет `..`/NUL/`\`). Локальный realpath-примитив больше не нужен и убран — это и есть смысл
перехода на адаптеры.

**2.4. Канонизация/границы корня.** Больше не актуально вручную: confine обеспечивает адаптер
(`LocalFilesystemAdapter` префиксует root; `AwsS3V3Adapter` — bucket+prefix; `ZipArchiveAdapter` — границы архива).

### ⏸️ Отложено осознанно

**2.2. Контентная/MIME/extension-валидация загрузки.** Намеренно НЕ вводим (битая MIME у легитимных изображений).
`uploadFile` пишет потоком (`writeStream`) — traversal-safe, но содержимое не проверяет. К будущей `UploadPolicy`.

**2.5. Утечка внутренних сообщений** (`ServerErrorHttpException($e->getMessage())`) — к рефакторингу error-mapping.
(Метод `clearDir` с абсолютными путями в сообщениях удалён вместе с переходом на Flysystem.)

**2.6. AuthZ только на уровне приложения** (`as access`, `backend/config/main.php:83`). Пакетный RBAC (read/write/delete)
— к рефакторингу.

**2.9. SUA-коннектор — заглушка** (`service->sua()`, через `StorageManager::defaultService()`) — к реализации по
будущей upload-политике.

> Закрыто переходом: N+1 на `countChild*` и `getDirSizeRecursive` (методы удалены; листинг отдаёт `countChild*=0`,
> ленивая загрузка); политика symlink (адаптер сам решает).

**CSRF:** виджет шлёт `x-csrf-token`, `JsonParser` настроен — покрыто (перепроверить `enableCsrfValidation`).

---

## 3. Security FRONTEND — статус

- **3.1. Пустой `path`** — ✅ принят (`FileEntity.setPath`, `FolderEntity.setPath`/`fillFolders`/`fillFiles`: проверяется
  только тип).
- **3.2. Валидация загрузки** (`UploadService.ts:73`) — ⏸️ оставлена выключенной осознанно.
- **3.3. Meta-контракт.** `FileMeta` требует строго `>0` для `mTime/aTime/permissions` и `size>=0`. У S3/ZIP нет
  POSIX-прав/atime → сервер отдаёт **адаптеро-независимую** meta: права синтезируются из `visibility` (положительное
  число), `mTime=aTime=lastModified|now`, `size=fileSize|0`, `dimensions=null`. UI «права» = справочно, не контроль
  доступа.

---

## 4. Архитектура: mount + Flysystem (реализовано)

### 4.1. Слой `src/storage/`

| Файл | Роль |
|------|------|
| `VirtualPath.php` | Парсинг `/{mountId}/{rel}`; запрет `..`/NUL/`\`; `''`/`/` = виртуальный корень. |
| `StorageMount.php` | Value object: `id`, **`League\Flysystem\Filesystem`**, публичный `baseUrl`, `label`. Хелперы `virtual()`/`virtualParent()`/`url()`. Без realRoot/realpath — confine у Flysystem. |
| `StorageMountFactory.php` | Сборка `StorageMount` по декларации: `match(adapter)` → Local / ZipArchive / AwsS3V3. Единственное место, знающее об адаптерах. |
| `MountRegistry.php` | Реестр `id → StorageMount` + дефолт. |
| `StorageManager.php` | Фасад контроллёров: `serviceFor()`, `defaultService()`, `virtualRootDto()` (синтетическая meta, без stat). |
| `exceptions/*` | `StorageException`/`PathTraversalException`/`UnknownMountException` (наследуют `DomainException`). |

- **`FileManagerService`** полностью переписан на операции Flysystem (`listContents`/`createDirectory`/`writeStream`/
  `move`/`delete`/`deleteDirectory`/`readStream`/`mimeType`/…) — адаптеро-независимо. Удалены локальные хелперы
  (`clearDir`, `formatPermissions`, ручные итераторы, `checkPathLength`, finfo-`mimeType`, `getDirSizeRecursive`,
  отдельные `deleteFile/deleteDirectory`); существенные TODO (upload, sua, config, hex-dump) сохранены.
- **Контроллёры** — без изменений API (через `StorageManager` + `VirtualPath`); `move` между разными mount запрещён
  явной ошибкой (отдельная фича копирования между адаптерами).
- **DI** `src/config/container.php`: собирает mounts через фабрику. Поднимается `Module::setContainerConfig()` из
  `BaseModule::init()`.

### 4.2. Адаптеры

- **Local** → `LocalFilesystemAdapter(@static)` — всегда.
- **ZipArchive** → `ZipArchiveAdapter(FilesystemZipArchiveProvider(@static/zip/test.zip))` — регистрируется при наличии
  файла.
- **AWS S3 v3** → `AwsS3V3Adapter(S3Client, bucket, prefix)` — регистрируется при заданных `key`+`bucket`. Параметры —
  через модуль **`yii2-cms-config`**: `src/config/options.php` (`modules.File.params.s3.*`), дефолты в
  `src/config/config.php`, читаются в `container.php`. Поддержаны S3-совместимые (`endpoint` + `usePathStyle`); `prefix`
  — скрытый «корень» внутри бакета.

### 4.3. Composer

В `composer.json` модуля добавлено: `league/flysystem ^3`, `league/flysystem-aws-s3-v3 ^3`,
`league/flysystem-ziparchive ^3`, `ext-zip`. Резолвятся в общий `app/vendor` при `composer update` (так и должно быть —
у Composer один vendor приложения).

---

## 5. ⚠️ Миграция/эксплуатация

- Дефолтные `fmDefaultPath` должны быть mount-префиксными: виджет `yii2-cms-file-manager` — `'/'` (ОК); редактор
  (`'/demo'`) и демо (`'/files'`) — поправить на `'/'` или `'/static'`.
- Тестовый `app/static/zip/test.zip`: хост-пользователь не пишет в `@static` (uid 82), поэтому архив создаётся вручную в
  контейнере. До создания zip-mount не регистрируется (UI не ломается). Команда — в чате/README.
- URL файлов из ZIP-mount пока относительные (нет download-эндпоинта) — отдельная задача.

---

## 6. Остаток (P1/P2)

`UploadPolicy` (после решения вопроса MIME); error-mapping (безопасные сообщения наружу); пакетный RBAC; download-эндпоинт
для ZIP/непубличных адаптеров; человекочитаемый `fileType`; пагинация листинга для больших каталогов/бакетов; FTP/SFTP —
тривиально (ещё ветка фабрики + `league/flysystem-ftp`).

---

## 7. Verification

- **Traversal:** `/static/../../etc`, `..`, NUL, `\` → отказ (Flysystem + `VirtualPath`).
- **Контракт:** `list('/')` → перечень точек монтирования; `/static` → содержимое (имя корня = `static`, `path=''`);
  navigate вглубь; mkdir/upload/rename/move/delete внутри mount возвращают виртуальные пути.
- **Адаптеры:** local — чтение/запись `@static`; zip — листинг архива (после создания `test.zip`); aws — после задания
  ключей в настройках, листинг/загрузка в бакет.
- **Прогон (Docker):** `composer update` (подтянет flysystem + aws-sdk + ziparchive); `php -l` файлов `storage/` и
  сервиса; создать `static/zip/test.zip`; при необходимости пересобрать фронт-ядро.

---

### Прямой ответ на исходный вопрос

ФМ не обрабатывал тот ответ, потому что ядро считало `path === ''` невалидным, тогда как сервер намеренно отдаёт `''`
как корень (сокрытие реального расположения). Релакс на фронте выполнен; заодно выстроена mount/виртуальная адресация и
выполнен полный переход на Flysystem с адаптерами Local/ZIP/S3.
