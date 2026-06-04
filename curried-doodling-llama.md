# План: разнесение CKEditor 5 (PHP + TS) по репозиториям и подключение через Composer

## Context

Сейчас всё (PHP-модуль файлов + ядро CKEditor + base-редактор + плагины filemanager/codemirror +
их сборка) лежит в одном composer-пакете `besnovatyj/yii2-cms-file`
(`app/packages/besnovatyj/yii2-cms-file`). TS-исходники и собранный `dist/` живут внутри
`src/widgets/customeditor/src/media/ckeditor5-custom/`. Сборка устроена в три слоя (см.
`.../ckeditor5-custom/readme.md`): ядро `ckeditor5.js`, base-редактор `ckeditor-base.js`,
плагины — каждый внешним ESM-бандлом, склейка в браузере через import map.

Цели:
- Плагин filemanager должен использоваться **и standalone, и как плагин CKEditor**.
- TS собирается там, где есть Node/Docker; артефакты коммитятся; **на проекте Node не нужен**.
- Подключение — через Composer (GitHub VCS + git-теги), как уже сделано для всех `besnovatyj/yii2-cms-*`
  (dev — `repositories.local` path+symlink из `app/packages/besnovatyj/*`).
- «Пересобрал один плагин → bump одного composer-пакета → готово», без пересборки редактора.

Решения (подтверждены пользователем):
1. **Рантайм-сборка**: каждый плагин — отдельный composer-пакет (dist + AssetBundle); виджет
   регистрирует бандлы и склеивает редактор в браузере.
2. **filemanager = 2 репозитория**: чистое ядро + тонкий CKEditor-адаптер.
3. **Отдельный пакет редактора** `besnovatyj/yii2-cms-ckeditor5`; `yii2-cms-file` зависит от него.
4. **Доставка**: GitHub `type:vcs` + git-теги, dist закоммичен в тег.

## Принцип разнесения (ответ на «какие ещё репозитории»)

- **Исходный CKEditor 5 — НЕ репозиторий.** Это upstream npm-зависимость `ckeditor5`, объявленная
  внутри пакета редактора. Своего репо не требует.
- **Ядро (`ckeditor5.js`) и base-редактор (`ckeditor-base.js`) — НЕ отдельные репозитории.** Это
  две esbuild-точки входа внутри ОДНОГО пакета редактора `yii2-cms-ckeditor5`.
- Отдельные репозитории нужны только для: ядра-библиотеки ФМ, адаптеров-плагинов и PHP-пакетов.

## Целевая карта репозиториев

| # | Репозиторий | Мир | Содержит | Зависит от |
|---|-------------|-----|----------|------------|
| R1 | `filemanager-core` | npm (TS-библиотека) | framework-agnostic ФМ, mount-API, бэкенд-порт | — |
| R2 | `ckeditor5-filemanager` | npm-сборка **+** composer | CKEditor-адаптер (Plugin-обёртка) + dist + AssetBundle | R1, `ckeditor5` (peer/external) |
| R3 | `ckeditor5-codemirror` | npm-сборка **+** composer | плагин CodeMirror + dist + AssetBundle | `ckeditor5` (peer/external) |
| R4 | `besnovatyj/yii2-cms-ckeditor5` | npm-сборка **+** composer | `ckeditor5.ts`+`ckeditor-base.ts` (слои 1–2), их esbuild, PHP-виджет, core AssetBundle, dist | R2, R3 (composer); `ckeditor5` (npm) |
| R5 | `besnovatyj/yii2-cms-file` | composer (как есть) | файловый модуль (PHP) | R4 |

«Dual-репозитории» (R2/R3/R4) — это один git-репозиторий с двойной природой: `package.json`+esbuild
для сборки Node-ом и `composer.json`+AssetBundle+закоммиченный `dist/` для потребления PHP-ом без Node.

## Детали по репозиториям

### R1 — `filemanager-core` (npm-библиотека)
- Извлечь из `.../ckeditor5-custom/plugins/filemanager/src` всё, КРОМЕ CKEditor-обвязки
  (`DoubleClickObserver`, `class FileManager extends Plugin`, регистрация кнопки тулбара — верх
  текущего `src/index.ts`). FSD-структура (`app/`, `entities/`, `features/`, `shared/`, `widgets/`,
  `pages/`) переезжает как ядро.
- Публичный API: `mount(el: HTMLElement, config: AppConfig)` (через существующий `createApp`/`AppRuntime`)
  + порт бэкенда `IFileManagerBackend` и реализация `HttpFileManagerBackend` (уже есть в
  `src/shared/api/fileManager/`).
- `package.json`: `exports { ".": "./dist/standalone.js" }`, никаких зависимостей от `ckeditor5`.
- CSS вшит в JS (`sassPlugin type: 'css-text'`), как сейчас.

### R2 — `ckeditor5-filemanager` (адаптер, npm + composer)
- npm-сторона: зависит от R1 (`@besnovatyj/filemanager-core`), `ckeditor5` в `peerDependencies` и
  `external` в esbuild. Содержит только CKEditor-обёртку, вызывающую `mount(...)` ядра. Точка входа
  `src/index.ts` → `dist/index.js`, **именованный** экспорт `FileManager` (как сейчас). dist коммитится.
  Базируется на текущем `plugins/filemanager/package.json` (он уже `@.../ckeditor5-filemanager`,
  `main: dist/index.js`, peerDeps на `@ckeditor/*`).
- composer-сторона: `composer.json` (`type: yii2-extension`, имя `besnovatyj/ckeditor5-filemanager`)
  + класс `FileManagerCkeditorAsset extends AssetBundle` (`sourcePath` → `dist`), реализующий общий
  интерфейс дескриптора плагина (см. ниже): URL модуля + имя экспорта `FileManager`.

### R3 — `ckeditor5-codemirror` (npm + composer)
- Из текущего `plugins/codemirror`. `ckeditor5` external, CodeMirror 6 бандлится. Точка входа
  `src/codemirror.ts` → `dist/codemirror.js`, **default**-экспорт. dist коммитится.
- composer-сторона: `besnovatyj/ckeditor5-codemirror` + `CodeMirrorCkeditorAsset` (URL модуля +
  имя экспорта `default`).

### R4 — `besnovatyj/yii2-cms-ckeditor5` (пакет редактора)
- TS-сборка (только два слоя, БЕЗ плагинов и БЕЗ copy-шага):
  - `src/ckeditor5.ts` (`export * from 'ckeditor5'` + css) → `dist/ckeditor5.js` + `dist/ckeditor5.css`
    (конфиг `esbuild-ckeditor5.js`).
  - `src/ckeditor-base.ts` (BaseEditor + официальные плагины) → `dist/ckeditor-base.js`
    (конфиг `esbuild.js`, упрощённый: убрать `esbuild-plugin-copy`).
  - `build-all.js` сводится к сборке этих двух точек.
- PHP-сторона (переезжает из `yii2-cms-file`):
  - `CkeditorCustomWidget` — переписать на мульти-бандл-склейку (см. следующий раздел).
  - `CkeditorCoreAsset` (бывший `CkeditorCustomAsset`): `css = ['ckeditor5.css']`, baseUrl для
    `ckeditor5.js` и `ckeditor-base.js`.
  - Интерфейс `CkeditorPluginAssetInterface` (`getModuleUrl(): string`, `getExportName(): string`,
    `register(View): void`) — контракт для дескрипторов плагинов из R2/R3.
- composer.json: `require` R2 и R3; dist закоммичен.

### R5 — `besnovatyj/yii2-cms-file`
- Удалить `src/widgets/customeditor/` целиком (переехало в R4).
- `composer.json`: добавить `require: besnovatyj/yii2-cms-ckeditor5`.
- Контроллеры файлового бэкенда (`sua-connector`, `file-manager` connector), на которые ссылается
  виджет (`Url::to('/file/backend/...')`), остаются в R5 — это серверная часть, к которой обращается
  ФМ. Виджет в R4 продолжает указывать на эти маршруты (URL конфигурируемы свойствами виджета).

## Ключевое изменение рантайма: мульти-бандл-склейка в виджете

Сейчас виджет ждёт плагины под единым baseUrl (`dist/plugins/...`). Теперь у каждого плагина свой
composer-пакет → свой AssetBundle → свой baseUrl. Новый `run()`:

1. Зарегистрировать `CkeditorCoreAsset` → `coreUrl`. Вывести import map
   `{"ckeditor5": "<coreUrl>/ckeditor5.js"}` **один раз на страницу** (флаг
   `view->params['ckeditor5_importmap_set']`, как сейчас) — гарантия единственного экземпляра классов
   CKEditor и работающего `instanceof Plugin`.
2. Свойство виджета `public array $plugins = [FileManagerCkeditorAsset::class, CodeMirrorCkeditorAsset::class]`
   (настраивается через DI/конфиг). Для каждого: зарегистрировать его AssetBundle, собрать
   `{url, exportName}`.
3. Сгенерировать `<script type="module">`:
   ```js
   import('<coreUrl>/ckeditor-base.js').then(async ({default: BaseEditor}) => {
     const mods = await Promise.all([ import('<fmUrl>/index.js'), import('<cmUrl>/codemirror.js') ]);
     const plugins = [ mods[0].FileManager, mods[1].default ]; // exportName из дескрипторов
     class CustomEditor extends BaseEditor {}
     CustomEditor.builtinPlugins = [...BaseEditor.builtinPlugins, ...plugins];
     CustomEditor.defaultConfig = {...BaseEditor.defaultConfig, ...<jsonConfig>};
     return CustomEditor.create(el, CustomEditor.defaultConfig);
   })
   ```
   Список `import()` и `plugins` строится из дескрипторов, не хардкодом.

Добавить плагин = `composer require` его пакет + добавить его Asset-класс в `$plugins`.
Обновить плагин = bump версии одного composer-пакета (его dist уже собран и закоммичен).

## Доставка и потребление (GitHub VCS + теги)

- Каждый из R1…R4 — отдельный GitHub-репозиторий. `dist/` коммитится; `node_modules` в `.gitignore`
  (уже так в filemanager). Релиз = git-тег `vX.Y.Z`.
- В `composer.json` проекта (`app/composer.json`) добавить блок `repositories` с `type:vcs` на
  R2, R3, R4 (R5 уже подключён). Пример уже подобран в `app/composer.md`. Composer тянет zipball
  тега с готовым dist — **Node/Docker на проекте не требуются**.
- Опционально `.gitattributes` `export-ignore` на `src/`, `node_modules`, `*.map` в R2/R3/R4 —
  чтобы composer-архив пакета был лёгким (только dist + PHP).
- dev остаётся как есть: `repositories.local` (path+symlink) из `app/packages/besnovatyj/*`; новые
  пакеты кладутся туда же для локальной разработки.

## Dev-workflow TS (R1 ↔ R2)

- Прод/CI-сборка R2: npm-зависимость на R1 через git-URL (`"@besnovatyj/filemanager-core":
  "github:besnovatyj/filemanager-core#v1.2.0"`) — без npm-registry.
- Локальная активная разработка: `file:../filemanager-core` или `npm link`.
- Node нужен только на машине сборки (dev/Docker/CI), где он уже есть.

## Порядок миграции

1. R1: выделить `filemanager-core`, расцепить с CKEditor, собрать `dist/standalone.js`, проверить
   standalone-маунт на голой HTML-странице.
2. R2: оставить в `ckeditor5-filemanager` только адаптер, подключить R1, собрать `dist/index.js`,
   добавить `composer.json` + `FileManagerCkeditorAsset`.
3. R3: вынести codemirror, добавить `composer.json` + `CodeMirrorCkeditorAsset`.
4. R4: создать `yii2-cms-ckeditor5` — перенести `ckeditor5.ts`/`ckeditor-base.ts` + их esbuild
   (убрать copy-шаг и сборку плагинов), перенести и переписать виджет (мульти-бандл), `CkeditorCoreAsset`,
   интерфейс дескриптора; `require` R2/R3; собрать и закоммитить dist.
5. R5: удалить `customeditor/`, добавить `require yii2-cms-ckeditor5`.
6. GitHub: создать репозитории, закоммитить dist, проставить теги; в `app/composer.json` добавить
   `repositories` (vcs) для R2/R3/R4; обновить `repositories.local`/path для dev.
7. `composer update` и проверка.

## Verification

- **Без Node**: чистый чекаут проекта → `composer require besnovatyj/yii2-cms-ckeditor5`
  (+ плагины через VCS) → убедиться, что `dist/*.js`+css присутствуют в `vendor/` (или в symlink
  path-репо). Открыть backend-страницу с виджетом.
- **Браузер**: import map выведен ровно один раз; грузятся `ckeditor5.js`, `ckeditor-base.js`,
  `index.js` (ФМ), `codemirror.js`; редактор создаётся; кнопки fileManager и codemirror работают;
  в консоли нет ошибок о дублирующемся ckeditor / сломанном `instanceof Plugin`.
- **Обновление одного плагина**: пересобрать dist в репо плагина → тег `vX+1` →
  `composer update besnovatyj/ckeditor5-filemanager` → перезагрузка: новое поведение, ядро редактора
  не трогалось, Node на проекте не использовался.
- **Standalone ФМ**: смонтировать R1 на странице без CKEditor — работает автономно.
- Несколько редакторов на одной странице: import map один, у каждого свой `editor.create`.

## Открытые моменты (не блокеры)

- Прод-механизм `besnovatyj/*` в репозитории пока не зафиксирован (VCS-блок отсутствует) — этот план
  его и вводит; решение фиксируем в `app/composer.md`.
- Имя npm-scope (`@besnovatyj` vs текущий `@mycompany` в filemanager/package.json) — унифицировать.
- Контракт «дескриптор плагина» (интерфейс) можно расширить полем доп. CSS, если у будущих плагинов
  CSS не будет вшит в JS.
