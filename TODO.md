# CKEditor 5: разнесение по пакетам — статус и TODO

Дата среза: 2026-06-01.

Документ описывает миграцию монолитного редактора (раньше всё лежало в
`yii2-cms-file/src/widgets/customeditor/`) на набор отдельных Composer/npm-пакетов.
План целиком: `/home/node/.claude/plans/curried-doodling-llama.md` (для Claude).

---

## Решения (зафиксированы)

1. **Рантайм-сборка**: каждый плагин — отдельный composer-пакет (dist + AssetBundle); виджет
   регистрирует бандлы и склеивает редактор в браузере через import map + динамический `import()`.
2. **Файловый менеджер — ДВА пакета** (восстановлено 2026-06-02): чистое ядро + тонкий
   CKEditor-адаптер. Промежуточно (2026-06-01) сливали в один пакет из-за того, что контейнер
   сборки Node был смонтирован на папку пакета и не видел соседние → межпакетная `file:`-зависимость
   на этапе сборки не резолвилась. Ограничение снято: сборка запускается per-package (каждый
   `package.json` отдельной кнопкой build в PHPStorm), но контекст доступен из корня проекта, поэтому
   `file:`-зависимость на соседний пакет работает. Ядро снова вынесено в отдельный npm-пакет.
    - **Соглашение о расположении**: npm-only пакеты живут в `app/packages/npm/`, composer-пакеты
      (`yii2-extension`) — в `app/packages/besnovatyj/`.
3. **Отдельный пакет редактора** `besnovatyj/yii2-cms-ckeditor5`; `yii2-cms-file` зависит от него.
4. **Доставка**: GitHub `type:vcs` + git-теги, `dist/` коммитится в тег. На потребляющем проекте
   Node не нужен.
5. **Направление зависимостей** (во избежание циклов composer): плагины зависят от пакета редактора
   (берут интерфейс), а редактор о плагинах не знает. Список плагинов задаёт `yii2-cms-file`.
6. **Контекст сборки**: каждый пакет собирается самодостаточно у себя в папке (кнопка build в
   PHPStorm). Все зависимости — из npm-реестра в локальный `node_modules`. Никаких `file:`/alias
   на соседние папки.

---

## Карта пакетов

| Пакет                              | Путь                                            | Роль                                                                                       |
|------------------------------------|-------------------------------------------------|--------------------------------------------------------------------------------------------|
| `@besnovatyj/filemanager-core`     | `app/packages/npm/filemanager-core`             | npm-only ядро ФМ (FSD), framework-agnostic, `dist/standalone.js`; CKEditor не знает        |
| `besnovatyj/yii2-cms-ckeditor5`    | `app/packages/besnovatyj/yii2-cms-ckeditor5`    | ядро `ckeditor5.js` + base `ckeditor-base.js` + PHP-виджет + контракт плагинов             |
| `besnovatyj/ckeditor5-filemanager` | `app/packages/besnovatyj/ckeditor5-filemanager` | ФМ-адаптер CKEditor (`dist/index.js`, экспорт `FileManager`) + AssetBundle; ядро вбандлено |
| `besnovatyj/ckeditor5-codemirror`  | `app/packages/besnovatyj/ckeditor5-codemirror`  | плагин CodeMirror + AssetBundle                                                            |
| `besnovatyj/yii2-cms-file`         | `app/packages/besnovatyj/yii2-cms-file`         | файловый модуль; даёт BC-виджет с преднастроенными плагинами                               |

Связи: `ckeditor5-filemanager` → `@besnovatyj/filemanager-core` (npm `file:`, вбандливается в dist) +
`yii2-cms-ckeditor5` (composer); `ckeditor5-codemirror` → `yii2-cms-ckeditor5`;
`yii2-cms-file` → три composer-пакета.

---

## ✅ Сделано

### Пакет редактора `yii2-cms-ckeditor5`

- [x] `assets/ckeditor5.ts` (слой 1) и `assets/ckeditor-base.ts` (слой 2) перенесены.
- [x] `esbuild-ckeditor5.js` (ядро), `esbuild.js` (base, **без** copy-шага и сборки плагинов),
  `build-all.js` (две точки входа параллельно).
- [x] `src/CkeditorCustomWidget.php` — переписан на мульти-бандл-склейку: регистрирует core-asset +
  asset каждого плагина из `$plugins`, строит import map (1 раз на страницу), генерирует
  динамические `import()` и список экспортов, склеивает `builtinPlugins`.
- [x] `src/CkeditorCoreAsset.php` — публикует `ckeditor5.js`/`.css`, `ckeditor-base.js`.
- [x] `src/contracts/CkeditorPluginAssetInterface.php` — контракт дескриптора плагина
  (`getPluginModuleUrl`, `getPluginExport`, `getEditorConfigContribution`).
- [x] `composer.json` (psr-4 `Besnovatyj\Ckeditor5\` → `src/`, require helpers/bootstrap5),
  `package.json`, `tsconfig.json`, `.gitignore`, `readme.md`.

### Пакет `@besnovatyj/filemanager-core` (npm-only ядро ФМ)

- [x] FSD-ядро ФМ в `src/` (CKEditor нигде в ядре нет — только TODO-упоминание в `UploadService.ts`).
- [x] `src/standalone.ts` — публичный API (`createApp`, `AppRuntime`, `AppConfig`, `FileEntity`,
  `IFileManagerBackend`, `HttpFileManagerBackend`, `HttpClient`), entry `.` → `dist/standalone.js`.
- [x] `src/types.d.ts` — ambient-декларации `*.scss`/`*.svg`.
- [x] `esbuild.js` — одна точка входа (`dist/standalone.js`), alias `@`→`src`, sass (css-text),
  svg (text). Без CKEditor.
- [x] `package.json` (`@besnovatyj/filemanager-core`, `main: dist/standalone.js`, scripts `build`+`types`),
  `tsconfig.json` (`@/*`), `.gitignore`, `readme.md`.
- Расположение: `app/packages/npm/filemanager-core` (соглашение: npm-only пакеты — в `packages/npm/`).

### Пакет `ckeditor5-filemanager` (ФМ-адаптер CKEditor)

- [x] `src/index.ts` — только CKEditor-обёртка (`class FileManager extends Plugin`,
  `DoubleClickObserver`, кнопка тулбара, вставка файлов); ядро берётся из
  `@besnovatyj/filemanager-core`.
- [x] `esbuild.js` — одна точка входа (`dist/index.js`), `ckeditor5` external, ядро `filemanager-core`
  **бандлится внутрь** (его CSS уже вшит при сборке ядра). Sass/postcss больше не нужны.
- [x] `package.json` — зависимость `@besnovatyj/filemanager-core: file:../../npm/filemanager-core`,
  `ckeditor5` в peer, тонкие devDeps (esbuild/tslib/typescript), `tsconfig.json` (без `@/`-путей).
- [x] `php/FileManagerCkeditorAsset.php` — AssetBundle, экспорт `FileManager`, отдаёт `fmBaseUrl`
  через `getEditorConfigContribution()` (без изменений).
- [x] `composer.json` (psr-4 `Besnovatyj\CkeditorFileManager\` → `php/`) — без изменений.
- [x] `readme.md` обновлён под адаптер + порядок сборки (сначала ядро, потом адаптер).

### Пакет `ckeditor5-codemirror`

- [x] TS (`src/codemirror.ts`, `.d.ts`), `esbuild.js`, `tsconfig.json`, `package.json` перенесены.
- [x] `php/CodeMirrorCkeditorAsset.php` — AssetBundle, экспорт `default`, вклад в конфиг — пустой.
- [x] `composer.json` (psr-4 `Besnovatyj\CkeditorCodeMirror\` → `php/`), `.gitignore`.

### Файловый модуль `yii2-cms-file`

- [x] `src/widgets/customeditor/src/CkeditorCustomWidget.php` заменён на **тонкий BC-шим**: тот же
  namespace `Besnovatyj\File\widgets\customeditor\src`, наследует виджет из `yii2-cms-ckeditor5`,
  преднастраивает `$plugins = [FileManagerCkeditorAsset, CodeMirrorCkeditorAsset]`. Благодаря
  этому ~7 пакетов-потребителей (actors, blog, catalog, gallery, page, performance, person)
  править НЕ нужно.
- [x] `composer.json` — добавлены require: `yii2-cms-ckeditor5`, `ckeditor5-filemanager`,
  `ckeditor5-codemirror`.

### Документация

- [x] `app/composer.md` — раздел «доставка раздельных пакетов» (dev/prod, vcs-блок, контекст сборки).
- [x] `readme.md` в каждом пакете.

---

## ⏳ Предстоит сделать

### 1. Сборка TS (Node/Docker, по кнопке build в каждой папке)

- [ ] `npm/filemanager-core`: `npm install && npm run build` → `dist/standalone.js` (СНАЧАЛА — адаптер
  бандлит его dist). Опц. `npm run types` → `dist/standalone.d.ts`.
- [ ] `ckeditor5-filemanager`: `npm install && npm run build` → `dist/index.js` (подтянет ядро по
  `file:../../npm/filemanager-core`, вбандлит его).
- [ ] `ckeditor5-codemirror`: `npm install && npm run build` → `dist/codemirror.js`.
- [ ] `yii2-cms-ckeditor5`: `npm install && npm run build-all` → `dist/ckeditor5.js` + `.css` +
  `dist/ckeditor-base.js`.
- Примечание: запускать `npm run build` (esbuild), НЕ `tsc/types` (esbuild типы не проверяет; в ядре
  возможны застарелые type-замечания, на бандл не влияют).

### 2. Composer (dev) и проверка

- [ ] `composer update` в `app/` — пакеты подхватятся из path-репо `./packages/besnovatyj/*` (symlink),
  Node не нужен. (vcs-репозитории НЕ добавлять, пока нет GitHub-репо.)
- [ ] Проверка в браузере на backend-форме с редактором:
    - import map выводится один раз на страницу;
    - грузятся `ckeditor5.js`, `ckeditor-base.js`, `index.js` (ФМ), `codemirror.js`;
    - работают кнопки `fileManager` и source-editing CodeMirror;
    - в консоли нет ошибок про дублирующийся ckeditor / сломанный `instanceof Plugin`;
    - несколько редакторов на странице — один import map, каждый создаётся.

### 3. Финальный cutover (ТОЛЬКО после успешной проверки п.2)

- [ ] Удалить старый TS: `app/packages/besnovatyj/yii2-cms-file/src/widgets/customeditor/src/media/`
  (вся директория `ckeditor5-custom` — переехала в пакеты). Сейчас оставлена как страховка.
- [ ] Удалить неиспользуемый `src/widgets/customeditor/src/CkeditorCustomAsset.php` (заменён на
  `CkeditorCoreAsset` в пакете редактора).
- [ ] Решить судьбу `src/widgets/customeditor/grok-1.md`, `grok-2.md`, старого `.../ckeditor5-custom/
      readme.md` (исторические заметки по переходу — перенести/удалить).

### 4. GitHub-доставка (prod)

- [ ] Создать GitHub-репозитории: `yii2-cms-ckeditor5`, `ckeditor5-filemanager`, `ckeditor5-codemirror`.
- [ ] В каждом: `git init`, закоммитить исходники + собранный `dist/`, тег `v1.0.0`, push.
  (`node_modules` в `.gitignore`; `dist` — коммитится.)
- [ ] (опц.) `.gitattributes` `export-ignore` на `src/`, `assets/`, `*.map` — лёгкий composer-архив.
- [ ] В `app/composer.json` добавить `repositories` `type:vcs` для трёх пакетов (готовый блок —
  в `app/composer.md`). Делать ТОЛЬКО после создания репозиториев.
- [ ] `composer update` на проде → тянет zipball тегов с готовым dist.

### 5. Хвосты/проверить позже

- [ ] `besnovatyj/yii2-cms-helpers` (нужен виджету для `Json`/`Expr`) — убедиться, что он тоже едет
  на прод как composer-зависимость `yii2-cms-ckeditor5` (require уже прописан).
- [ ] Тулбар виджета содержит `fileManager`, `ckfinder` — для standalone-потребителя пакета редактора
  без этих плагинов их надо убрать из своего `$toolbar` (в `yii2-cms-file` всё ок: шим включает ФМ).
- [ ] Ядро `@besnovatyj/filemanager-core` — npm-only пакет (своего composer-репо НЕ требует). Для
  прод/CI-сборки адаптера зависимость на ядро через git-URL
  (`"@besnovatyj/filemanager-core": "github:besnovatyj/filemanager-core#v1.0.0"`); локально — `file:`.
  Адаптер вбандливает ядро в свой `dist/index.js`, поэтому отдельный composer-пакет для ядра не нужен.
- [ ] Старый вложенный git-репозиторий ФМ (`.../ckeditor5-custom/plugins/filemanager/.git`) уйдёт
  вместе с удалением `media/` в п.3.

Моё замечание: не надо ничего удалять автоматически, я все сам проанализирую и удалю когда будет перенесено 100%
функционала и заметок.

---

## Как обновлять один плагин (целевой воркфлоу)

1. Правка в репо плагина → `npm run build` (его dist).
2. `git commit` dist → `git tag vX.Y.Z` → push.
3. На проекте `composer update besnovatyj/ckeditor5-<plugin>`.
   Редактор не пересобирается, Node на проекте не нужен.

---

##  Работа с репозиториями

**Сейчас**

Оставить:

`"@besnovatyj/filemanager-core": "file:../../npm/filemanager-core"`

Это нормальная временная зависимость на этапе разделения. Ее проблема возникает только тогда, когда такой package.json
публикуется.

Перед публикацией `ckeditor5-filemanager` заменить ее на:

`"@besnovatyj/filemanager-core": "^1.0.0"`

После выделения Git-репозитория, но до публикации в npm

Git dependency вам как раз подходит:

```json
{
    "dependencies": {
        "@besnovatyj/filemanager-core": "github:besnovatyj/filemanager-core#v1.0.0"
    }
}
```

Либо с точной фиксацией commit:

```json
{
    "dependencies": {
        "@besnovatyj/filemanager-core": "github:besnovatyj/filemanager-core#abc1234"
    }
}
```

Ветка менее надежна:

`github:besnovatyj/filemanager-core#main`

Она меняется со временем, поэтому установка хуже воспроизводится.

Если `dist` не хранится в Git, в `filemanager-core` понадобится:

```json
{
    "scripts": {
        "build": "node esbuild.js",
        "prepare": "npm run build && npm run types"
    }
}
```

При установке Git dependency npm устанавливает ее `dependencies` и `devDependencies`, запускает `prepare`, затем
упаковывает пакет. Это официальный поддерживаемый сценарий: npm scripts (https://docs.npmjs.com/cli/using-npm/scripts/).

Но я бы предпочел хранить готовый `dist` в release/tag или сразу опубликовать пакет в npm. Установка из Git медленнее и
сильнее зависит от корректности build-окружения.

После публикации в npm

Окончательный контракт:

```json
{
    "dependencies": {
        "@besnovatyj/filemanager-core": "^1.0.0"
    }
}
```

Рабочий цикл будет обычным:

1. Изменить `filemanager-core`.
2. Собрать и проверить пакет.
3. Повысить версию: `1.0.1`, `1.1.0` и т. п.
4. Выполнить `npm publish`.
5. Обновить dependency в `ckeditor5-filemanager`.
6. Собрать адаптер и опубликовать его следующую версию.

Для проверки до публикации:

```shell
npm run build
npm run types
npm pack --dry-run
```

Затем полезно установить реальный tarball в адаптер:

```shell
npm pack
npm install ../filemanager-core/besnovatyj-filemanager-core-1.0.0.tgz --no-save
```

Это лучше всего выявляет отсутствующие `dist`, `.d.ts` и ошибочный `exports`.

Отдельное уточнение: **Asset Packagist не является местом публикации ваших PHP/Composer-пакетов**. Он предоставляет
npm/Bower-пакеты в формате Composer. Ваши Yii2-пакеты следует регистрировать на Packagist.org (https://packagist.org/)
либо устанавливать
напрямую из GitHub как Composer VCS repositories. `filemanager-core` публикуется в npm registry.

Итог: текущую `file:`-ссылку можно спокойно оставить до выделения репозиториев. Затем кратковременно использовать Git
dependency, а конечной схемой сделать обычную semver-зависимость из npm. Workspaces сейчас не дают вам достаточной
пользы, чтобы
перестраивать под них процесс.
