# `src/api` — протокол bescms-fs v1 (серверная сторона)

Слой между HTTP-адаптером (`controllers/backend/ApiController` + `OperationAction`) и доменом
`src/fs`. Здесь живёт всё, что относится к **форме** обмена с клиентом; всё, что относится к
**смыслу** операций, — в `src/fs`.

Канонический документ контракта: `app/packages/npm/filemanager-core2/docs/API-CONTRACT.md`.
Этот слой — его реализация; при расхождении прав документ, код правится.

## Состав

| Файл | Роль |
|---|---|
| `Contract.php` | имя/версия контракта и сервера (попадают в `describe` и `meta` конверта) |
| `ErrorCode.php` | коды ошибок контракта ↔ HTTP-статусы |
| `ApiException.php` | ошибка API; строится из доменного `FsException` или ошибки входа |
| `Envelope.php` | конверт `{ok, data, meta}` / `{ok:false, error}` |
| `Payload.php` | типизированное чтение входа (обязательность, типы, лимиты списков, виртуальные пути с проверкой области видимости) |
| `ApiContext.php` | кто вызывает, что ему разрешено (предикат от хоста) и в какой области (`PathScope`) |
| `scope/ScopeToken.php` | выпуск (`issue`) и проверка (`verify`) подписанного токена области `X-Fs-Scope`; единственный Yii-зависимый класс слоя |
| `NodeSerializer.php` | `Node`/`Listing`/`OperationReport` → JSON-структуры контракта |
| `OperationInterface.php`, `OperationRegistry.php` | контракт операции и реестр |
| `StreamResult.php` | результат потоковой операции (`download`) |
| `operations/*` | по классу на операцию: `describe`, `list`, `tree`, `stat`, `content`, `mkdir`, `rename`, `move`, `copy`, `delete`, `upload`, `download`, `preview` (inline-изображения для предпросмотра), `upload-finalize` (tus; регистрируется только при `params.fs.tus.enabled`), `thumbnail` (GET-миниатюры; регистрируется только при `params.fs.thumbnails.enabled` и наличии Imagick/GD), `search` (поиск по именам обходом поддерева с бюджетами), `archive`/`extract` (ZIP через `fs/archive/Archiver`) |

## Поток запроса

```
POST /File/backend/api/move
  → DefaultDenyAccessControl (RBAC по маршруту, fail-closed)   [ядро]
  → ApiController::actions()['move'] → OperationAction         [адаптер Yii]
      → Payload из JSON-тела, ApiContext с предикатом прав
      → MoveOperation::execute()                                [api]
          → Transfer::move()                                    [fs]
      → Envelope::success({report}) / Envelope::error(...)
```

## Область видимости запроса

`OperationAction` читает токен из заголовка `X-Fs-Scope` (или query `scope` для GET), проверяет его
через `ScopeToken::verify()` и передаёт `PathScope` в `Payload` и `ApiContext`. Нет токена — область
не ограничена; невалидный токен — `forbidden`. `describe` сообщает `scope.root`. Выпуск токена —
задача виджета при рендере страницы (`ScopeToken::issue($path)`).

## Докачиваемая загрузка

tus-endpoints (`TusController`) не входят в реестр операций: это другой протокол (заголовки
`Upload-*`, тело — сырые байты). В контракт они попадают через `describe.upload.tus` и операцию
`upload-finalize`, которая переносит завершённую загрузку в папку через `Uploader`. Подробно —
`filemanager-core2/docs/UPLOAD-TUS.md`.

## Как добавить операцию

1. Описать её в `API-CONTRACT.md` (вход, выход, коды ошибок), поднять minor версии контракта.
2. Класс `operations/FooOperation implements OperationInterface`: `name()` = сегмент URL,
   `method()`, `info()`, `execute(Payload, ApiContext)` — разобрать вход, вызвать домен, вернуть `data`.
3. Добавить класс в список в `config/container.fs.php` (`OperationRegistry`).
4. Маршрут `/File/backend/api/foo` появится автоматически; выдать на него права в RBAC.
5. Зеркально добавить типы и метод клиента в `filemanager-core2/src/api`.

## Что адаптер (`OperationAction`) гарантирует операциям

- метод запроса совпадает с `method()` операции (иначе 405 в конверте);
- вход уже разобран (JSON / form-data / query), файл — как доменный `UploadSource`;
- любое исключение превращается в конверт: клиентские — без логирования, внутренние — с записью
  причины в лог и нейтральным текстом наружу;
- потоковая отдача — только `attachment` + `nosniff`.
