# `src/fs` — домен виртуальной файловой системы (v2)

Слой, который ничего не знает про HTTP, Yii и JSON. Его задача — безопасно выполнять файловые
операции над несколькими хранилищами (League\Flysystem: локальный каталог, ZIP-архив, S3 и любой
другой адаптер) по единой адресации `/{mountId}/путь`.

Канон протокола, который этот слой обслуживает: `app/packages/npm/filemanager-core2/docs/API-CONTRACT.md`.
Модель угроз: там же, `docs/SECURITY.md`.

## Карта каталога

| Каталог | Что внутри | С чего начать читать |
|---|---|---|
| `path/` | `VirtualPath` — адрес узла и первый рубеж защиты от обхода каталога; `PathResolver` + `Target` — путь → (mount, location для Flysystem) с проверками существования; `PathScope` — область видимости запроса | `VirtualPath::parse()` |
| `naming/` | `NameRules` (правила, отдаются клиенту), `NameValidator` (отклоняет), `NameSanitizer` (исправляет — только для upload), `UniqueNameGenerator` (`file (2).txt`), `NameParts`; `TransliteratorInterface` + `CyrillicTransliterator` (опция `naming.transliterate`) | `NameValidator::validate()` |
| `node/` | `Node` (единая модель файла/папки/mount'а), `NodeKind`, `NodeFactory` (из атрибутов Flysystem) | `NodeFactory::fromAttributes()` |
| `mount/` | `Mount`, `MountCapabilities` (что хранилище умеет), `MountDefinition` (конфиг), `MountRegistry`, `MountFactory`; `adapter/*` — фабрики адаптеров (точка расширения «новый тип хранилища»); `url/*` — стратегии публичных URL | `MountFactory::make()` |
| `policy/` | Конвейер правил `OperationPolicy` + правила (блок-лист расширений, белый список, лимит размера, проверка содержимого). Точка расширения «новая проверка безопасности» | `PolicyRuleInterface` |
| `operation/` | Сервисы операций: `Lister`, `Inspector`, `DirectoryCreator`, `Renamer`, `Transfer` (+`TreeCopier`), `Deleter`, `Uploader`, `Downloader`, `Searcher` (+`NameMatcher`, `SearchBudget` — поиск по именам с бюджетами обхода); `ConflictResolver`/`ConflictStrategy`; `NameGuard`; `report/*` — отчёты пакетных операций | `Transfer::move()` |
| `archive/` | ZIP: `Archiver` (сборка из любых хранилищ через временные файлы; распаковка — каждая запись через `Uploader`/`DirectoryCreator`, zip-slip и бюджеты до записи), `ArchiveBudget`, `TempFile`, `ExtractResult` | `Archiver::extract()` |
| `thumbnail/` | Серверные миниатюры: `Thumbnailer` (лимиты, sniff, кэш → `DownloadStream`), `ThumbnailCache` (файловый, ключ по mount/пути/mtime/размеру), `ThumbnailGeneratorInterface`/`ImagineGenerator` (Imagick→GD, strip+autorotate, перекодирование), `ThumbnailConfig` | `Thumbnailer::open()` |
| `tus/` | Докачиваемая загрузка: `TusServer` (протокол tus 1.0.0), `TusStoreInterface`/`FileTusStore`, `TusUpload`, `TusConfig`; байты попадают в хранилище только через `Uploader` при финализации | `TusServer::patch()` |
| `exception/` | `FsException` и наследники — каждое несёт код контракта (`not_found`, `exists`, …) | `FsException` |
| `FsLimits.php` | количественные лимиты (DoS-защита) | |
| `VirtualFileSystem.php` | фасад для других модулей CMS | |

## Ключевые инварианты

1. **Путь никогда не «чинится».** `VirtualPath::parse()` принимает только канонический вид и
   отклоняет всё подозрительное. Нормализацию делает клиент.
2. **Имя проверяется одинаково во всех операциях** — через `NameGuard`. Санитизация только в
   `Uploader` (имя пришло из ОС пользователя), с возвратом фактического имени клиенту.
3. **Возможности ≠ права.** `MountCapabilities` — что хранилище умеет физически; `readOnly` —
   административный запрет; права пользователя — RBAC по маршрутам (вне домена).
4. **Пакетные операции не прерываются на первой ошибке**: каждый элемент получает статус в
   `OperationReport`.
5. **Кросс-mount перенос — потоковый**: файл не читается в память целиком.
6. **Метаданные — вспомогательные**: сбой запроса mtime/mime не превращает успешную операцию в
   ошибку (`NodeFactory::quiet()`).
7. **Область видимости сужает, но не расширяет**: `PathScope` применяется ко всем путям запроса
   в `Payload` до вызова домена; права RBAC остаются как есть.
8. **Временные байты (tus) — не файлы хранилища**: они лежат вне mount'ов и становятся узлом
   только через `Uploader` (та же санитизация и политика, что у multipart-загрузки).

## Как добавить новый тип хранилища

1. Класс `fs/mount/adapter/FooAdapterFactory implements AdapterFactoryInterface`: ключ (`'foo'`),
   сборка Flysystem-адаптера из `MountDefinition::$options`, честный профиль `MountCapabilities`
   (умеет ли нативно перемещать папки, есть ли visibility, эмулирует ли папки).
2. Зарегистрировать фабрику в `config/container.fs.php` (`AdapterFactoryRegistry`).
3. Описать точку монтирования в `params.fs.mounts` с `'adapter' => 'foo'`.

Больше ничего: сервисы, API, фронтенд узнают о хранилище из `describe`.

## Как добавить новое правило безопасности

Класс в `fs/policy/`, реализующий `PolicyRuleInterface` (`id()`, `check()`, `describe()`),
и регистрация в `OperationPolicy` (`config/container.fs.php`). Правило само решает, к каким
операциям применимо (`PolicyContext::$operation`).
