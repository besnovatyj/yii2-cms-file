# besnovatyj/yii2-cms-file

Модуль файлов Yii2 CMS: точки монтирования (League\Flysystem: локальный каталог, ZIP, S3), серверная
часть файлового менеджера. Две линии API:

| Линия | Маршруты | Клиент | Статус |
|---|---|---|---|
| **v2** (`bescms-fs` v1.0) | `/File/backend/api/{operation}`, `/File/backend/tus/*` | `@besnovatyj/filemanager-core2` (проводник) | основная |
| v1 | `/File/backend/file-manager/*` | `@besnovatyj/filemanager-core` | до замены |

Документация протокола и модели угроз — в пакете клиента:
`app/packages/npm/filemanager-core2/docs/{API-CONTRACT,SECURITY,UPLOAD-TUS}.md`.
Устройство серверных слоёв — `src/fs/README.md` (домен) и `src/api/README.md` (протокол).

## Структура v2

```
src/fs/            домен виртуальной ФС (пути, имена, узлы, mount'ы, политики, операции, tus)
src/api/           протокол: конверт, коды ошибок, Payload, операции, describe, ScopeToken
src/controllers/backend/ApiController.php   /File/backend/api/{op} — операции контракта
src/controllers/backend/TusController.php   /File/backend/tus/{create,upload} — докачка
src/commands/TusController.php              php yii File/tus/purge
src/commands/ThumbnailController.php        php yii File/thumbnail/purge
src/config/config.php                       params.fs — все настройки v2
src/config/container.fs.php                 DI-проводка v2 (подключается из container.php)
```

## Настройки (`modules.File.params.fs`)

| Ключ | По умолчанию | Назначение |
|---|---|---|
| `mounts` | `null` (автосборка: `static` всегда; `zip` при наличии `@static/zip/test.zip`; `aws` при `s3.key`+`s3.bucket`) | список `MountDefinition`: `id`, `adapter` (`local`/`zip`/`s3`), `label`, `icon`, `options`, `baseUrl`, `readOnly` |
| `defaultMount` | `'static'` | стартовое хранилище для клиентов |
| `naming` | `[]` | `maxLength`, `forbiddenChars`, `forbiddenNames`, `allowLeadingDot`, `allowTrailingDotOrSpace`, `unicodeNormalization`, **`transliterate`** (кириллица → латиница при загрузке) |
| `policy.blockedExtensions` | `null` (дефолтный блок-лист) | `[]` — выключить (не рекомендуется) |
| `policy.allowedExtensions` | `null` | белый список для upload/rename |
| `policy.maxFileSize` | `null` (ini PHP) | байт |
| `policy.contentSniff` | `false` | `true` — отклонять исполняемое содержимое; `'strict'` — плюс сверка с расширением |
| `limits` | `[]` | `listPageSize`, `maxBatchItems`, `contentMaxBytes`, `maxTransferEntries`, `imageProbeMaxBytes`, `previewMaxBytes`, `searchMaxResults` (500), `searchMaxEntries` (50 000 — бюджет обхода одного поиска) |
| `tus` | включено | `enabled`, `dir`, `chunkSize`, `threshold`, `ttl` — см. UPLOAD-TUS.md |
| `thumbnails` | включено | `enabled`, `dir` (`@runtime/fs-thumbs`), `sizes` (`[64,128,256,512]`), `quality`, `maxPixels`, `ttl` — миниатюры для плитки/значков (контракт §9.14); операция появляется только при наличии `ext-imagick` или `ext-gd` |

Секции `s3` и `upload` общие с v1 (S3-ключи настраиваются через модуль настроек, см. `config/options.php`).

## Права (RBAC по маршрутам ядра, fail-closed)

Каждая операция — отдельный маршрут. Минимальный набор для чтения: `describe`, `list`, `tree`,
`stat`, `content`, `download`, `preview`, `thumbnail`, `search`. Для редактирования: `mkdir`, `rename`, `move`, `copy`, `delete`,
`upload`. Для докачки: `/File/backend/tus/create`, `/File/backend/tus/upload`,
`/File/backend/api/upload-finalize`. `describe` показывает клиенту только разрешённые операции.

## Область видимости (scope)

Виджет, открытый для конкретной сущности (папка поста), выпускает подписанный токен
(`Besnovatyj\File\api\scope\ScopeToken::issue('/static/origin/Blog/12')`); клиент шлёт его
заголовком `X-Fs-Scope` (для GET — query `scope`), и любой путь вне области отклоняется
`forbidden`. Токен привязан к пользователю, действует 12 часов, подписан ключом, производным от
`cookieValidationKey`. Виджеты: `JoditWidget::$explorerScoped`, `ExplorerWidget::$scope`.

## Требования к окружению

- PHP ≥ 8.4, `ext-fileinfo`, `ext-mbstring`, `ext-zip`; `ext-intl` желательно (NFC-нормализация имён).
- nginx `client_max_body_size` и PHP `post_max_size` ≥ `tus.chunkSize` (5 МБ).
- `ext-imagick` (предпочтительно) или `ext-gd` — для миниатюр; `ext-exif` — чтобы миниатюры учитывали ориентацию снимков.
- cron: `php yii File/tus/purge` раз в час; `php yii File/thumbnail/purge` раз в сутки.
- Прод с OPcache `validate_timestamps=0`: после обновления кода — `systemctl reload php8.4-fpm`.

## Расширение

- Новый тип хранилища — `fs/mount/adapter/*AdapterFactory` + регистрация в `container.fs.php`.
- Новое правило безопасности — `fs/policy/PolicyRuleInterface` + регистрация в `OperationPolicy`.
- Новая операция API — `api/operations/*Operation` + регистрация в `OperationRegistry`.
- Другая транслитерация — реализация `fs/naming/TransliteratorInterface`.
- Другое хранилище tus-загрузок (Redis/общий диск) — реализация `fs/tus/TusStoreInterface`.
- Другой генератор миниатюр (imgproxy, внешний сервис) — реализация `fs/thumbnail/ThumbnailGeneratorInterface`, привязка в `container.fs.php`.

Программный доступ из других модулей — фасад `Besnovatyj\File\fs\VirtualFileSystem` (DI).
