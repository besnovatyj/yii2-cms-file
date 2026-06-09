# Анализ состояния `yii2-cms-file` и проверка сопроводительных .md (Claude, 2026-06-09)

Проверены: исходники `yii2-cms-file/src/`, соседние пакеты (`npm/filemanager-core`,
`ckeditor5-filemanager`, `ckeditor5-codemirror`, `yii2-cms-ckeditor5`, `yii2-cms-file-manager`),
старое состояние `yii2-cms-file-before`, корневой `app/composer.json`, а также заметки:
`TODO.md`, `curried-doodling-llama.md`, `adaptive-scribbling-pixel.md`,
`adaptive-scribbling-pixel_upd.md`, `codex-analysis.md`.

---

## 1. Краткий вывод

Текущее состояние исходников — **рабочая, архитектурно правильная декомпозиция с завершённым
переходом на Flysystem**, но с незакрытыми хвостами уровня release engineering (контракты DTO,
lifecycle фронтенда, error mapping, документация). Я в целом **согласен с codex-analysis.md** —
это самый точный из пяти документов; все его ключевые фактические утверждения, которые я проверил
по коду, подтвердились (см. §4). Самый устаревший документ — `TODO.md` (см. §3.2).

Моя оценка совпадает с codex по порядку величины: архитектура ~7–8/10, готовность к публикации
как независимых пакетов ~5/10.

---

## 2. Моя оценка исходников `yii2-cms-file` (текущее состояние)

### 2.1. Что сделано хорошо

- **Слой `src/storage/` — главное достижение.** `VirtualPath` (первый рубеж: запрет `..`, NUL,
  `\`), `StorageMount` (value object), `StorageMountFactory` (единственное место, знающее об
  адаптерах), `MountRegistry`, `StorageManager` (фасад). Ответственности разделены чисто, добавление
  FTP/SFTP действительно сведётся к одной ветке `match`.
- **Traversal закрыт системно.** Сравнение со старым кодом (`yii2-cms-file-before`) подтверждает:
  там `getFolderDto`/`createDir`/`uploadFile` склеивали `realRoot . $path` без confine (строки
  38/190/368), защита была только в delete/move-source/rename. Теперь все операции идут через
  Flysystem (нормализация + `PathTraversalDetected`) поверх `VirtualPath::parse()`. Рассинхрон
  защиты устранён архитектурно, а не точечными заплатками — правильное решение.
- `FileManagerService` стал адаптеро-независимым и компактным (492 строки против ~800 у старого),
  upload — потоковый (`writeStream`), метаданные синтезируются осмысленно (visibility → POSIX-mode).
- DI через `container.php` + `Module::setContainerConfig()` — декларативно, mounts с защитой от
  «битых» регистраций (zip — только при наличии файла, S3 — только при key+bucket).
- S3-параметры через модуль настроек (`options.php` → `params.s3`) с поддержкой S3-совместимых
  (endpoint, path-style) — соответствует принятой в проекте схеме конфигурирования.
- BC-виджет `Besnovatyj\File\widgets\CkeditorCustomWidget` — корректный тонкий шим (только
  преднастройка `$plugins`).

### 2.2. Проблемы, которые я нашёл сам (в заметках не отмечены или отмечены неточно)

1. ✅ ИСПРАВЛЕНО (шаг 3): **Устаревшие PHPDoc-ссылки на удалённый `StorageMount::path()`** — были
   в трёх местах (`FileManagerController`, `VirtualPath`, `PathTraversalException`). Докблоки
   переписаны на фактическую модель: первый рубеж — `VirtualPath::parse()`, второй — сам Flysystem
   (`PathNormalizer` → `PathTraversalDetected`).
2. ✅ ИСПРАВЛЕНО (шаг 3): **`rename()`: `oldName` не валидировался как одиночный сегмент** —
   склеивался в путь сырым, `oldName = "sub/file.txt"` позволял оперировать объектом вне заявленной
   родительской директории (в пределах mount — Flysystem конфайнит). Добавлен хелпер
   `assertSingleSegment()` (запрет `/`, `\`, NUL, `.`/`..`, пустого имени) → `DomainException` (422).
   У `createDir` имя фактически закрыто `sanitizeName` (заменяет `/` на `_`).
3. ✅ ИСПРАВЛЕНО (шаг 2): **Неконсистентные типы исключений**: `getFolderDto` бросал
   `yii\base\InvalidArgumentException`, остальные методы — `DomainException`. Унифицировано на
   `DomainException` вместе с введением error mapping в контроллёре.
4. **`composer.json`**: placeholder `"email": "your-email@example.com"`; захардкоженное поле
   `"version": "1.0.0"` (при доставке через git-теги его лучше убрать — классический источник
   рассинхрона тег↔манифест).
5. Мелочи: `sua()` — мёртвый `if (true)` (известная заглушка, TODO стоит); `getConfigDto()` отдаёт
   `fileMaxSize: ''` при заявленном на фронте `string|null`; для zip-mount `url()` с `baseUrl=''`
   даёт относительные URL (известно, отмечено в `_upd` §5 как отдельная задача
   download-эндпоинта).

### 2.3. Подтверждаю проблемы, отмеченные в заметках и всё ещё актуальные

- ✅ ИСПРАВЛЕНО (шаг 1, коммит `34a775e`): **Любая ошибка → HTTP 500.** Валидация запроса
  (разбор `VirtualPath`, проверки `isRoot()`, cross-mount) вынесена до `try`; добавлены хелперы
  `parsePath()` (`PathTraversalException` → 400) и `serviceFor()` (`UnknownMountException` → 404).
  В `delete` сервисы всех mount резолвятся до операций — неизвестный mount отклоняет весь батч
  до первого удаления.
- ✅ ИСПРАВЛЕНО (шаг 2): **Утечка внутренних сообщений**: введён error mapping через хелпер
  `execute()`: `DomainException` (бизнес-правило, сообщение адресовано пользователю, содержит
  только виртуальные пути) → 422 с сообщением + `Yii::warning`; прочие `Throwable`
  (инфраструктура/адаптеры) → 500 с нейтральным текстом, детали только в лог (`logException`).
- **AuthZ только глобальный `as access`**, в пакете нет `behaviors()`/RBAC — отложено, актуально.
- **Upload без контентной валидации** — осознанное решение (битые MIME у легитимных изображений),
  зафиксировано в memory проекта и в коде комментарием. Не считаю дефектом текущей стадии, но
  напомню: `.php/.svg/.html` на статик-домен — это хранимый XSS/RCE-вектор, и `UploadPolicy`
  остаётся самым важным незакрытым security-пунктом бэкенда.

---

## 3. Корректность .md-файлов

### 3.1. `curried-doodling-llama.md` (план разнесения) — корректен, реализован

План соответствует фактической структуре пакетов: ядро в `app/packages/npm/filemanager-core`,
адаптер/codemirror/редактор — отдельные dual-пакеты, `yii2-cms-file` зависит от трёх composer-пакетов
(плюс появившийся `yii2-cms-file-manager`, которого в плане R1–R5 не было). Раздел «Работа с
репозиториями» уже исполнен дальше, чем там написано: `file:`-зависимость заменена на `^1.0.0`,
и lock адаптера ссылается на **реальный tarball registry.npmjs.org с integrity-хэшем** — т.е.
`@besnovatyj/filemanager-core@1.0.0` фактически опубликован в npm (план это описывал как «после
публикации»).

TODO:

1) Опционально `.gitattributes` `export-ignore` на `src/`, `node_modules`, `assets/`, `*.map` в R2/R3/R4 —
   чтобы composer-архив пакета был лёгким (только dist + PHP).
2) Прод/CI-сборка R2: npm-зависимость на R1 через git-URL (`"@besnovatyj/filemanager-core":
  "github:besnovatyj/filemanager-core#v1.2.0"`) — без npm-registry.
3) В `app/composer.json` добавить `repositories` `type:vcs` для трёх пакетов (готовый блок —
   в `app/composer.md`). Делать ТОЛЬКО после создания репозиториев.
4) Ядро `@besnovatyj/filemanager-core` — npm-only пакет (своего composer-репо НЕ требует). Для
   прод/CI-сборки адаптера зависимость на ядро через git-URL
   (`"@besnovatyj/filemanager-core": "github:besnovatyj/filemanager-core#v1.0.0"`); локально — `file:`.
   Адаптер вбандливает ядро в свой `dist/index.js`, поэтому отдельный composer-пакет для ядра не нужен.

R1 — `filemanager-core` (npm-библиотека)  
R2 — `ckeditor5-filemanager` (адаптер, npm + composer)  
R3 — `ckeditor5-codemirror` (npm + composer)  
R4 — `besnovatyj/yii2-cms-ckeditor5` (пакет редактора)  
R5 — `besnovatyj/yii2-cms-file`

### 3.2. `TODO.md` (срез 2026-06-01) — самый устаревший документ, требует актуализации

Переименовал в `NPM+GIT.md`, удалил всё, кроме справки по подключению NPM пакетов напрямую из GitHub, так как все пункты
по разделению монолитного модуля были выполнены

### 3.3. `adaptive-scribbling-pixel.md` (анализ безопасности) — был точен, теперь историчен

Сверил ключевые находки со старым кодом `yii2-cms-file-before` — **ссылки на строки и суть
совпадают** (голый `realRoot . $path` в `getFolderDto:38`, `createDir:190`, `uploadFile:368`;
realpath-confine только в delete/move-source/rename). Анализ был качественным и честным.
Сейчас документ описывает уже не существующий код (сервис переписан), но целевая архитектура из
его §4 (VirtualPath, StorageManager/MountRegistry, Flysystem, mount в адресации) реализована
практически дословно. Статус «исходный аналитический документ, не изменяется» — корректен.

### 3.4. `adaptive-scribbling-pixel_upd.md` (срез 2026-06-04) — точен почти полностью

- ✅ §2.1/2.4 (traversal/confine закрыты Flysystem + VirtualPath) — подтверждаю по коду.
- ✅ §2.2/2.5/2.6/2.9 «отложено осознанно» — статусы верны и сегодня.
- ✅ §3.1 — пустой `path` принят: `FileEntity.setPath`/`FolderEntity.setPath` проверяют только
  `typeof === 'string'`, с поясняющими комментариями.
- ✅ §3.2 — `throw` в `UploadService.upload` действительно закомментирован.
- ✅ §4 — таблица слоя `storage/` соответствует файлам один в один; composer-зависимости добавлены.
- ⚠️ §5 (миграция): «`fmDefaultPath` редактора `'/demo'` — поправить на `'/'` или `'/static'`» —
  **до сих пор не поправлено**: в `yii2-cms-ckeditor5/src/CkeditorCustomWidget.php` по-прежнему
  `public string $fmDefaultPath = '/demo'`. У standalone-виджета `yii2-cms-file-manager` — `'/'` (ок).
  Пункт остаётся открытой задачей, документ это и предсказывал.

### 3.5. `codex-analysis.md` — подтверждаю; точность высокая

Проверил по коду каждое значимое фактическое утверждение:

| Утверждение codex                                                              | Моя проверка                                                                                                                                                                                                                                                       |
|--------------------------------------------------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| P0: `@/`-импорты в публичных `dist/*.d.ts` core                                | ✅ Подтверждено: `dist/standalone.d.ts` — все 7 экспортов через `@/...`; алиас живёт только в tsconfig core. Для стороннего consumer types непереносимы.                                                                                                            |
| `prepublishOnly` есть только у core                                            | ✅ Подтверждено (у адаптера и codemirror — нет).                                                                                                                                                                                                                    |
| codemirror: `tsc --noEmit` падает с TS5069                                     | ✅ Подтверждено конфигом: `declaration: false` + `emitDeclarationOnly: true` в tsconfig.                                                                                                                                                                            |
| `file:` заменён на `^1.0.0`, lock → npm tarball                                | ✅ Подтверждено (resolved: registry.npmjs.org + integrity).                                                                                                                                                                                                         |
| README адаптера всё ещё про `file:../../npm/filemanager-core`                  | ✅ Подтверждено (`readme.md:20`).                                                                                                                                                                                                                                   |
| CKEditor выровнен на `^48.2.0` во всех трёх пакетах                            | ✅ Подтверждено.                                                                                                                                                                                                                                                    |
| P1: `rename` — фронт ждёт `{path, item: DirDto}`, PHP отдаёт одиночный FileDto | ✅ Подтверждено. Нюанс, которого нет у codex: фронт фактически использует **только `res.path`** (`RenameFeature` → `nav:refresh`), а поле `path` в PHP FileDto есть — поэтому в рантайме работает «случайно». `res.item` нигде не читается. Тип лжёт, но не падает. |
| P1: `move(): Promise<void>` vs `{status:'ok'}`                                 | ✅ Подтверждено (структурно безвредно, но wire-format объявлен неверно).                                                                                                                                                                                            |
| P1: failed-элементы `delete` без обязательного `type`                          | ✅ Подтверждено: обе ветки ошибок в `deletePaths()` не кладут `type`, а `DeleteResponseDto` требует `'file'\|'folder'`.                                                                                                                                             |
| P1: `analyze.exif: null` vs `exif?: Record<string,string>`                     | ✅ Подтверждено (PHP: `'exif' => null`).                                                                                                                                                                                                                            |
| P1: meta директорий — сокращённый FolderMetaDto внутри заявленного FileDto     | ✅ Подтверждено: `dirMeta()` отдаёт 4 поля без `isWritable/isReadable/isExecutable/dimensions`.                                                                                                                                                                     |
| P1: клиентские ошибки → HTTP 500                                               | ✅ Подтверждено. **Исправлено** шагами 1–2 (см. §2.3 и §5).                                                                                                                                                                                                          |
| P1: `AppRuntime.destroy()` не снимает window-listeners                         | ✅ Подтверждено (`addEventListener` в `setupGlobalErrorBoundary`, TODO на строке 108, в `destroy()` снятия нет).                                                                                                                                                    |
| P1: утечки `bind(this)`                                                        | ✅ Подтверждено в `DirectoryContentFeature` (add/remove с разными результатами `bind`) и в `SplitPanelWC` (`handleResize` — уже arrow-property, но подписка через лишний `.bind(this)` → `removeEventListener(this.handleResize)` снимает не то).                   |
| P1: подписки `navState`/`registry`/`selectionStore` не сохраняются для отписки | ✅ Подтверждено: в `unsubs` уходит только `viewModeStore`; три подписки (строки 47/52/67) теряются → уничтоженная feature продолжает рендерить.                                                                                                                     |
| P2: адаптер лезет в приватный `runtime['bus']`                                 | ✅ Подтверждено (`ckeditor5-filemanager/src/index.ts:93,319`).                                                                                                                                                                                                      |
| P2: `yii2-cms-file` тянет редактор и оба плагина                               | ✅ Подтверждено + он ещё тянет `yii2-cms-file-manager` (codex это не упомянул). Согласен с рекомендацией «вариант 1» (вынести интеграционный пакет), но это вопрос приоритета, не корректности.                                                                     |
| P2: дубли в корневом `app/composer.json`                                       | ✅ Подтверждено (ckeditor5, оба плагина, file, file-manager перечислены наряду с yii2-cms-file).                                                                                                                                                                    |
| P2: старый namespace потребителей в workspace нет                              | ✅ Подтверждено grep'ом.                                                                                                                                                                                                                                            |

Расхождений с кодом в codex-analysis.md я **не нашёл**. Единственные уточнения: (а) нюанс про
`rename` выше — серьёзность чуть ниже заявленной, это типовая, а не рантайм-поломка; (б) масштаб
«lifecycle-утечек» ограничен сценарием многократного открытия/закрытия ФМ в одной сессии админки —
для текущего использования это деградация, а не критический дефект; для публичной библиотеки —
согласен, надо закрывать. Итоговые оценки codex (границы пакетов — хорошо; release readiness —
нет) разделяю.

---

## 4. Сводный статус и приоритеты (моя редакция)

Что я бы закрывал в первую очередь, объединяя остатки всех документов:

1. ✅ СДЕЛАНО (шаги 1–2). **Error handling в `FileManagerController`**: валидации `isRoot()`/
   cross-mount — до `try`; `PathTraversalException`/`UnknownMountException` → 400/404;
   `DomainException` → 422 с сообщением; прочее → 500 с нейтральным текстом, детали в лог.
2. ✅ СДЕЛАНО (шаг 3). **Стейл-докблоки `StorageMount::path()`** (3 файла) + валидация `oldName`
   как одиночного сегмента в `rename`.
3. ✅ СДЕЛАНО (шаги 4–6). **Frontend lifecycle**: `AppRuntime` window-listeners (шаг 4);
   `bind()`-пары и потерянные подписки в `DirectoryContentFeature`/`SplitPanelWC` (шаг 5);
   публичный `close()` в runtime вместо `['bus']` (шаг 6). Остаётся пересборка dist.
4. **Синхронизация DTO** (`rename`, `move`, delete-failures `type`, `exif`, dir-meta) — лучше
   одним заходом с фиксацией контракта (хотя бы общие fixtures).
5. **Публикуемость types ядра**: убрать `@/` из declarations (tsconfig.build с переписыванием
   путей или bundled d.ts), единый build+types pipeline, `prepublishOnly` у всех трёх npm-пакетов;
   поправить README адаптера (упоминание `file:`).
6. ✅ СДЕЛАНО (пользователем): `TODO.md` переименован в `NPM+GIT.md`, выполненные пункты удалены.
7. Дальше по плану: `UploadPolicy`, пакетный RBAC, download-эндпоинт для zip/непубличных mount,
   `fmDefaultPath` `/demo` → `/static`, GitHub-доставка (vcs-блок).

Пункты 1–2 — внутри `yii2-cms-file` и не требуют пересборки фронта; 3–5 — в npm-пакетах с
пересборкой dist.

---

## 5. Журнал правок (фиксация по шагам)

- **Шаг 1** (коммит `34a775e`, `yii2-cms-file`): `FileManagerController` — корректные HTTP-статусы.
  Валидация запроса вынесена до `try`; хелперы `parsePath()` → 400 при traversal/NUL/`\`,
  `serviceFor()` → 404 при неизвестном mount; в `delete` неизвестный mount отклоняет весь батч
  до первого удаления. `catch (Throwable)` → 500 остался только вокруг файловых операций.
- **Шаг 2** (`yii2-cms-file`): error mapping + устранение утечки внутренних сообщений.
  Хелпер `execute()` в контроллёре: `DomainException` → 422 (сообщение пользователю, лог-warning),
  прочие `Throwable` → 500 «Внутренняя ошибка файловой операции» (детали только в лог).
  `FileManagerService::getFolderDto()`: `yii\base\InvalidArgumentException` → `DomainException`
  (унификация доменных ошибок, иначе «директория не существует» стала бы нейтральным 500).
  Контракт для фронтенда: «не найдено/уже существует/некорректное имя» теперь приходят как 422
  с прежним текстом в `message`; нейтральный 500 — только для настоящих внутренних сбоев.
- **Шаг 3** (`yii2-cms-file`): comment rot + контракт `rename`.
  Переписаны три устаревших PHPDoc, ссылавшихся на удалённый `StorageMount::path()`
  (`FileManagerController`, `VirtualPath`, `PathTraversalException`) — теперь описывают фактические
  рубежи защиты (`VirtualPath::parse()` + Flysystem `PathNormalizer`).
  `FileManagerService::rename()`: `oldName` валидируется хелпером `assertSingleSegment()`
  (запрет `/`, `\`, NUL, `.`/`..`, пустого имени) — имя обязано быть одиночным сегментом внутри
  `parentPath`, а не путём.
- **Шаг 4** (`npm/filemanager-core`): `AppRuntime` — window-listeners error boundary
  (`error`, `unhandledrejection`) вынесены в стабильные поля-стрелки и снимаются в `destroy()`
  (закрыт TODO на строке 108); `destroy()` стал идемпотентным (флаг `destroyed`) — его вызывают
  и обработчик `fm:close`, и внешний владелец (CKEditor-адаптер), раньше это давало двойной
  `widget.destroy()`. ⚠️ Требуется пересборка dist ядра и адаптера (адаптер бандлит ядро);
  разумно сделать один раз после шагов 5–6.
- **Шаг 5** (`npm/filemanager-core`, коммит `89e933f`): lifecycle-утечки listeners.
  `DirectoryContentFeature`: подписки `navState`/`registry`/`selectionStore` теперь кладутся в
  `unsubs` (раньше их `Unsubscriber` терялись); DOM-обработчики переведены в поля-стрелки —
  `add/removeEventListener` получают одну ссылку (раньше пары делали разные `bind(this)`, отписка
  снимала «не тот» обработчик). `SplitPanelWC`: `pointerdown` биндится один раз, у поля-стрелки
  `handleResize` убран лишний `bind`; регистрация слушателей вынесена из-под guard'а `shadowRoot` —
  disconnect/connect симметричны (повторное подключение элемента к DOM восстанавливает слушатели).
  dist по-прежнему не пересобран (после шага 6).
- **Шаг 6** (`npm/filemanager-core` `98d06cf` + `ckeditor5-filemanager` `6921d0e`): инкапсуляция
  закрытия ФМ. В `AppRuntime` добавлен публичный `close(source = 'external')` — эмитит `fm:close`,
  то есть проходит ту же единственную точку уничтожения, что крестик/Esc/overlay. Адаптер в обоих
  местах переведён с `runtime['bus']` (доступ к private-полю строковым индексом) на
  `runtime.close('select')`.
  ⚠️ **Пересборка dist (шаги 4–6)**: адаптер typecheck'ается против установленного из npm
  `filemanager-core@1.0.0`, в котором `close()` ещё нет. Порядок: (1) в core — `npm run build` +
  `npm run types`, поднять версию (1.0.1) и опубликовать в npm (либо временно вернуть
  `file:../../npm/filemanager-core` в адаптере); (2) в адаптере — обновить зависимость,
  `npm install && npm run build` (+ `types`); (3) закоммитить dist обоих пакетов.
