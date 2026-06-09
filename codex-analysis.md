# Моя заметка по пакетам и модулям входящим в данный рабочий процесс

Пути к пакетам:

- `/workspace/app/packages/besnovatyj/yii2-cms-file-before` - Старый пакет, в состоянии до разделения на отдельные
  пакеты и до перехода на Flysystem

- `/workspace/app/packages/besnovatyj/ckeditor5-codemirror` - Плагин codemirror для CKEditor 5 (PHP+TS)
- `/workspace/app/packages/npm/filemanager-core` - Файловый менеджер на TS.
- `/workspace/app/packages/besnovatyj/ckeditor5-filemanager` - CKEditor 5 адаптер файлового менеджера (TS адаптер + PHP
  AssetBundle)
- `/workspace/app/packages/besnovatyj/yii2-cms-ckeditor5` - CKEditor 5 (TS CKEditor 5 ядро, TS базовый редактор с кучей
  плагинов (собираем в бандл все плагины, включаем необходимые через конфиг в PHP виджете), PHP-виджет, PHP-контракт для
  AssetBundle плагинов)
- `/workspace/app/packages/besnovatyj/yii2-cms-file` - PHP бэкэнд файлового менеджера на Flysystem + PHP виджет CKEditor
  5 с подключёнными плагинами
- `/workspace/app/packages/besnovatyj/yii2-cms-file-manager` - Standalone PHP виджет файлового менеджера
  \+ slim TS FM wrap

Заметка отражает текущее состояние по пакетам на 9 июня 2026 года.

---

# Анализ разделения `yii2-cms-file-before`

Дата первоначального анализа: 8 июня 2026 года. Повторная проверка: 9 июня 2026 года.

## Краткий вывод

Разделение выполнено в правильном архитектурном направлении, но пока не завершено до состояния полноценных независимо
поставляемых пакетов.

Границы ответственности выбраны в основном удачно:

- `yii2-cms-file` владеет Yii2-модулем, HTTP API и хранилищами;
- `yii2-cms-ckeditor5` владеет базовой сборкой CKEditor и Yii2-виджетом;
- `ckeditor5-filemanager` является адаптером между CKEditor и файловым менеджером;
- `ckeditor5-codemirror` изолирует независимый CKEditor-плагин;
- `filemanager-core` больше не зависит от CKEditor и потенциально пригоден для standalone-использования.

Это существенно лучше исходного пакета, где backend, Yii2-виджет, сборка CKEditor, два плагина и весь frontend файлового
менеджера находились в одном дереве.

Однако текущий результат следует считать **рабочей локальной декомпозицией**, а не готовым релизом `1.0.0`. Основные
причины после повторной проверки: declaration-файлы созданы, но публичные declarations `filemanager-core` содержат
неразрешимые для потребителя алиасы `@/`; сохраняются рассинхронизация нескольких DTO, lifecycle-утечки и отсутствие
автоматических тестов контрактов. Локальная `file:`-зависимость и несовместимые диапазоны CKEditor исправлены.

## Что сделано хорошо

### 1. Правильно выделено framework-agnostic ядро

`filemanager-core` не импортирует CKEditor. Его публичная точка входа `src/standalone.ts` экспортирует только runtime,
конфигурацию, доменную сущность и backend-порты. Это хорошая граница: CKEditor стал внешним адаптером, а не
инфраструктурой внутри доменной логики файлового менеджера.

Особенно удачны:

- `AppConfig` как входной контракт;
- `IFileManagerBackend` как порт к серверу;
- `createApp()` как composition root;
- возможность подмены backend через `config.backend`;
- отделение `AppRuntime` от CKEditor API.

### 2. CKEditor-плагины подключаются через явный PHP-контракт

`CkeditorPluginAssetInterface` удачно отделяет базовый виджет от конкретных плагинов. Виджет знает только URL
ESM-модуля, имя экспорта и вклад в конфигурацию редактора.

Использование import map для единственного экземпляра `ckeditor5` также корректно решает типичную проблему дублирования
CKEditor-классов и неработающего `instanceof Plugin`.

### 3. Сохранена основная обратная совместимость PHP-виджета

`Besnovatyj\File\widgets\CkeditorCustomWidget` оставлен как тонкий наследник нового виджета и включает оба прежних
плагина. Это снижает объём изменений в формах других CMS-модулей.

### 4. Composer-зависимости отражают фактическую сборку

Основной модуль явно требует базовый редактор и оба PHP AssetBundle плагинов. Для локальной разработки через path
repository это работает прозрачно.

### 5. Backend получил более сильную модель хранения

Переход на Flysystem, `StorageMount`, `MountRegistry`, `StorageManager` и виртуальные пути является отдельным
архитектурным улучшением. Реальный корень скрыт от frontend, а выбор mount инкапсулирован. Это лучше прямой работы со
`SplFileInfo` из исходного сервиса.

При этом это уже не просто extraction, а самостоятельная крупная переработка. Её риски должны проверяться отдельно от
самого разделения пакетов.

## Критичные и значимые проблемы

### P0. ⚠️ Declaration-файлы появились, но типизированный API `filemanager-core` всё ещё не готов к публикации

Повторная проверка подтвердила, что исходная проблема частично исправлена:

- `dist/standalone.d.ts`, `dist/index.d.ts` и `dist/codemirror.d.ts` существуют и отслеживаются Git;
- у всех трёх npm-пакетов есть скрипт `types`;
- внутренний `tsc --noEmit` проходит у `filemanager-core` и `ckeditor5-filemanager`.

Но `filemanager-core/dist/standalone.d.ts` экспортирует сущности через пути вида `@/app/createApp`, а внутренние `.d.ts`
содержат множество таких же импортов. Алиас `@/*` настроен только в `tsconfig.json` самого core и не является частью
контракта установленного npm-пакета. Обычный consumer не обязан знать этот алиас, поэтому declarations формально есть,
но публичная точка `types` не является переносимой.

Дополнительно `ckeditor5-codemirror` не проходит обычный `tsc -p tsconfig.json --noEmit`: `emitDeclarationOnly: true`
остаётся в `tsconfig.json`, но `declaration` задаётся только CLI-скриптом `types`, из-за чего TypeScript выдаёт `TS5069`.
У `ckeditor5-filemanager` и `ckeditor5-codemirror` нет `prepublishOnly`, а обычный `npm run build` по-прежнему создаёт
только JS. Поэтому единый build/release pipeline пока не гарантирует наличие актуальных `.d.ts`.

Рекомендация:

1. В каждом публикуемом npm-пакете сделать единый `build`, который гарантированно создаёт JS, source map и `.d.ts`.
2. Добавить отдельный `tsconfig.build.json` и переписать алиасы в declarations на относительные пути либо собирать один
   bundled `.d.ts` (`rollup-plugin-dts`, API Extractor и аналог).
3. Проверять существование всех путей из `main`, `types` и `exports` в CI и через `npm pack --dry-run`.
4. Не считать пакет выпущенным, если `npm install` из tarball и consumer typecheck не проходят.

### P0. ✅ Исправлено: `ckeditor5-filemanager` использует переносимую npm-зависимость

Зависимость задана как:

```json
{
    "@besnovatyj/filemanager-core": "file:../../npm/filemanager-core"
}
```

В текущем `package.json` используется `"@besnovatyj/filemanager-core": "^1.0.0"`, а lock-файл ссылается на npm tarball.
Исходная локальная `file:`-зависимость удалена.

Остался небольшой документационный долг: README адаптера всё ещё утверждает, что `npm install` подтягивает core через
`file:../../npm/filemanager-core`. Эту инструкцию нужно привести в соответствие с манифестом.

### P1. ✅ Исправлено: версии CKEditor между пакетами выровнены

- базовый пакет использует `ckeditor5 ^48.2.0`;
- оба плагина объявляют peer `ckeditor5 ^48.2.0`;
- lock-файлы плагинов содержат `48.2.0`.

Формальная несовместимость peer dependency устранена. Перед релизом всё равно нужен общий browser/integration smoke test,
потому что совпадение версий само по себе не проверяет import map и загрузку обоих ESM-плагинов.

### P1. Контракт `rename` frontend и backend не совпадает

`filemanager-core/src/shared/api/fileManager/ports.ts` описывает `RenameResponse` как:

```ts
{
    path: string;
    item: DirDto
}
```

Новый Yii2 backend возвращает из `FileManagerService::rename()` одиночный DTO переименованного файла или директории, то
есть структуру уровня `FileDto`, а не `{path, item}`.

Это скрыто тем, что TypeScript доверяет generic-параметру `http.post<RenameResponse>()` и не валидирует JSON runtime. В
результате слой типов создаёт ложную гарантию.

Рекомендация: зафиксировать один OpenAPI/JSON Schema контракт либо хотя бы общие contract fixtures. Затем привести PHP
response и TypeScript DTO к одному формату.

### P1. ⚠️ Синхронизация остальных DTO выполнена лишь частично

Исходное замечание по upload исправлено: frontend ожидает `path` и `fileName`, PHP возвращает оба поля и дополнительный
`url`. Такой ответ совместим со структурной типизацией TypeScript.

Но остались другие расхождения:

- `move()` объявлен как `Promise<void>`, а PHP возвращает `{status: "ok"}`;
- неуспешные элементы `delete` не содержат обязательный для `DeleteResponseDto` `type`;
- PHP возвращает `analyze.exif: null`, а TypeScript допускает только объект или отсутствие поля;
- `createDir` и rename директории заявлены как `FileDto`, но backend кладёт в `meta` сокращённый `FolderMetaDto` без
  `isWritable`, `isReadable`, `isExecutable` и `dimensions`.

Рекомендация: описать точные response DTO для каждой операции и проверять их общими fixtures/JSON Schema. Для операций,
результат которых frontend намеренно игнорирует, всё равно не объявлять заведомо другой wire-format.

### P1. Ошибки клиента превращаются в HTTP 500

В `FileManagerController` проверки виртуального корня и разных mount выполняются внутри `try`, после чего общий
`catch (Throwable)` оборачивает даже `BadRequestHttpException` в `ServerErrorHttpException`.

Это ломает семантику API: некорректный запрос становится серверной ошибкой. Проблема появилась в ходе новой
mount-архитектуры и должна быть устранена до стабилизации контракта.

Рекомендация: выполнять валидацию до `try`, отдельно пробрасывать `HttpException`, либо ловить только инфраструктурные
исключения.

### P1. Lifecycle `filemanager-core` оставляет глобальные listeners

`AppRuntime::setupGlobalErrorBoundary()` добавляет `window` listeners для `error` и `unhandledrejection`, но `destroy()`
их не снимает. В коде уже есть соответствующий TODO.

CKEditor-плагин создаёт новый runtime при каждом повторном открытии файлового менеджера. Поэтому listeners
накапливаются, удерживают старые runtime/DI-объекты и дублируют диагностику ошибок.

Рекомендация: хранить стабильные ссылки на handlers и обязательно удалять их в идемпотентном `destroy()`.

### P1. Есть ещё несколько утечек событий из-за `bind()`

Например, `DirectoryContentFeature` подписывается и отписывается через новые результаты `bind(this)`, поэтому отписка не
удаляет исходный listener. Аналогичный дефект есть у resize listener в `SplitPanelWC`: подписка использует
`this.handleResize.bind(this)`, отписка — `this.handleResize`.

Кроме того, `DirectoryContentFeature` не сохраняет функции отписки от `navState`, `FolderRegistry` и `SelectionStore`.
Даже после исправления `bind()` эти три подписки продолжат удерживать уничтоженную feature и вызывать повторный render.

В монолите такие дефекты были локальной проблемой виджета. После выделения reusable core они становятся частью качества
публичной библиотеки и должны быть закрыты lifecycle-тестами.

### P2. Публичный API адаптера нарушает инкапсуляцию runtime

`ckeditor5-filemanager` закрывает окно через `this.runtime?.['bus']?.emit(...)`, обращаясь к private-полю строковым
индексом. Это хрупкая связь с внутренней реализацией `AppRuntime`.

Рекомендация: добавить публичный `close(source)` или `selectAndClose(...)` в runtime. Адаптер должен использовать только
экспортированный API.

### P2. `yii2-cms-file` чрезмерно связан с CKEditor-плагинами

Основной файловый модуль напрямую требует:

- `yii2-cms-ckeditor5`;
- `ckeditor5-filemanager`;
- `ckeditor5-codemirror`.

Это сохраняет поведение «батарейки в комплекте», но делает backend файлов и хранилищ неустанавливаемым без редактора и
CodeMirror. После декомпозиции это не оптимальная зависимость.

Лучше один из вариантов:

1. `yii2-cms-file` содержит только backend/API, а интеграционный пакет вроде `yii2-cms-file-ckeditor5` содержит
   compatibility widget и требует оба мира.
2. CKEditor-зависимости переводятся в `suggest`, а compatibility widget создаётся только при их наличии.

Вариант 1 чище и исключает циклическую концептуальную ответственность: файловый backend не должен владеть конфигурацией
текстового редактора.

### P2. Корневое приложение дублирует транзитивные зависимости

`app/composer.json` требует основной файловый модуль, базовый CKEditor, оба плагина и file-manager пакет одновременно.
Если эти пакеты нужны только через `yii2-cms-file`, часть строк избыточна. Если они являются самостоятельными
возможностями приложения, это следует объяснить.

Само по себе дублирование не ломает Composer, но размывает владельца dependency graph и усложняет обновления.

### P2. Обратная совместимость сохранена не полностью

Сохранён класс `Besnovatyj\File\widgets\CkeditorCustomWidget`, но исходный namespace
`Besnovatyj\File\widgets\customeditor\src\...` и `CkeditorCustomAsset` исчезли.

Если внешние модули использовали только основной widget, миграция совместима. Если они напрямую ссылались на AssetBundle
или старый namespace, это breaking change при неизменной версии `1.0.0`.

Повторный ограниченный поиск по текущим CMS-пакетам нашёл только потребителей нового
`Besnovatyj\File\widgets\CkeditorCustomWidget`; старый namespace встречается лишь в `yii2-cms-file-before` и заметках.
Для кода внутри данного workspace практическая совместимость подтверждена, но внешних потребителей это не исключает.

Рекомендация: выполнить ограниченный поиск потребителей, добавить deprecated bridge-классы на один релиз или выпустить
semver major с migration guide.

### P2. Нет доказательств независимой поставляемости Composer-пакетов

PHP-пакеты зависят от committed `dist`, что нормально, но отсутствуют автоматические проверки:

- `composer validate --strict`;
- установка каждого пакета в минимальный test project;
- проверка существования `sourcePath` и файлов AssetBundle;
- smoke test публикации assets и построения import map;
- проверка нескольких редакторов на одной странице.

На текущем хосте `php` и `composer` отсутствуют, поэтому провести эти проверки в рамках анализа было невозможно.

### P2. Документация пакетов недостаточна

У core есть полезный README, а README `ckeditor5-filemanager` уже описывает назначение, экспорт и общую схему сборки.
Однако он содержит устаревшую ссылку на `file:`-зависимость, а README `ckeditor5-codemirror` по-прежнему состоит только
из ссылок на темы. В совокупности документация пакетов не описывает:

- совместимые версии CKEditor;
- npm и Composer способы установки;
- обязательный import map;
- имя экспорта плагина;
- конфигурацию `fileManager`;
- формат backend API;
- порядок локальной сборки и релиза;
- связь npm-пакета и PHP AssetBundle.

После разделения документация становится частью межпакетного контракта, а не необязательной заметкой.

## Замечания по package design

### Двойная природа CKEditor-плагинов

`ckeditor5-filemanager` и `ckeditor5-codemirror` одновременно являются npm-библиотеками и Composer Yii2 extensions. Для
локального проекта это удобно, но для публикации создаёт две независимые версии одного артефакта в одном репозитории.

Нужно явно выбрать release policy:

- одна версия Git tag управляет и npm, и Composer;
- либо JS и PHP bridge публикуются отдельными пакетами/репозиториями;
- AssetBundle всегда содержит `dist`, собранный из той же версии source;
- CI проверяет, что `dist` не устарел относительно `src`.

Текущая схема допустима, но без автоматизации легко получить Composer-релиз со старым JS.

### `filemanager-core` фактически не только core

Пакет содержит доменные сущности, HTTP adapter, DI, все features, Web Components, SCSS, иконки и standalone composition
root. Это полноценное приложение/SDK, а не минимальное «core».

Название не критично, но дальнейшее расширение будет проще при одном из вариантов:

- переименовать концепцию в `filemanager` или `filemanager-app`;
- либо позже разделить headless domain/application core и web-component UI.

Сейчас дополнительное разделение преждевременно. Сначала важнее стабилизировать API и lifecycle.

## Что можно было сделать лучше в процессе разделения

1. Сначала зафиксировать поведение исходного монолита contract/integration тестами.
2. Выполнить механический extraction без одновременной переработки Flysystem, FSD и DI.
3. После зелёных тестов отдельными изменениями провести backend storage refactor и frontend refactor.
4. Использовать workspace для всех JS-пакетов с единым lock/release pipeline либо хотя бы root-level integration
   project.
5. Создать один источник истины для API DTO: OpenAPI, JSON Schema или генерируемые TypeScript/PHP DTO.
6. До объявления версии `1.0.0` проверить установку именно из `npm pack`/Composer archive, а не из локальных
   symlink/path repositories.

Так история изменений и диагностика регрессий были бы значительно проще. Сейчас невозможно строго отделить ошибки
extraction от ошибок новой реализации.

## Рекомендуемая целевая схема

### Composer

- `besnovatyj/yii2-cms-file`: backend, API, Flysystem mounts; без CKEditor и CodeMirror.
- `besnovatyj/yii2-cms-ckeditor5`: базовый Yii2 widget и CKEditor build; без конкретных плагинов.
- `besnovatyj/ckeditor5-filemanager`: допустим как npm plugin + Yii Asset bridge либо разделить PHP bridge отдельно.
- `besnovatyj/ckeditor5-codemirror`: аналогично.
- опционально `besnovatyj/yii2-cms-file-ckeditor5`: compatibility widget, который собирает file backend + editor +
  plugins.

### npm

- `@besnovatyj/filemanager-core` (или более точное имя): нормальная semver-зависимость, полный `dist` и declarations.
- `@besnovatyj/ckeditor5-filemanager`: peer dependency на тот же поддерживаемый CKEditor range; dependency на
  опубликованный core.
- `@besnovatyj/ckeditor5-codemirror`: peer dependency на тот же CKEditor range.
- общий integration test, который собирает редактор и загружает оба ESM-плагина через import map.

## Приоритетный план доведения до готовности

1. ⚠️ Частично: `.d.ts` созданы и закоммичены, но нужно убрать `@/` из публичных declarations, починить обычный typecheck
   `ckeditor5-codemirror` и включить types в единый build/prepublish pipeline.
2. ✅ Убрать `file:../../npm/filemanager-core` из публикуемого манифеста.
3. ✅ Выровнять CKEditor на одной major/minor линии и пересобрать оба плагина.
4. ⚠️ Частично: upload синхронизирован; остаются `rename`, `move`, delete error items, `analyze.exif` и directory meta.
5. Исправить lifecycle listeners и убрать доступ к private `bus`.
6. Добавить contract tests backend API и consumer typecheck из tarball.
7. Определить границу `yii2-cms-file`: backend-only или интеграционный пакет; при backend-only вынести compatibility
   widget.
8. Добавить migration guide и минимальные README для каждого пакета.
9. После этого присвоить релизные версии и проверять каждый пакет независимо.

## Выполненные проверки и ограничения анализа

Успешно:

- `filemanager-core`: TypeScript `tsc --noEmit` проходит;
- `ckeditor5-filemanager`: TypeScript `tsc --noEmit` проходит;
- подтверждено наличие tracked `.d.ts` во всех трёх npm-пакетах;
- подтверждена единая линия CKEditor `48.2.0` в манифестах и lock-файлах;
- подтверждена npm-зависимость `@besnovatyj/filemanager-core: ^1.0.0` вместо `file:`;
- проверены фактические ESM imports в собранных plugin bundles: `ckeditor5` оставлен external и должен резолвиться
  import map;
- сопоставлены PHP controller/service responses и TypeScript ports/DTO;
- рабочие деревья анализируемых Git-репозиториев до создания этого отчёта были чистыми.

Не прошло:

- `ckeditor5-codemirror`: `tsc -p tsconfig.json --noEmit` завершается с `TS5069` из-за сочетания
  `emitDeclarationOnly: true` без `declaration` при обычном typecheck;
- публичные declarations `filemanager-core` содержат внутренние `@/`-импорты и не являются самодостаточными для
  стандартного npm consumer.

Не удалось проверить:

- PHP syntax/lint и `composer validate`, потому что в текущем host-окружении нет команд `php` и `composer`;
- end-to-end работу в браузере и Yii2 runtime;
- `npm pack --dry-run` и consumer typecheck из tarball: среда анализа не позволила создать npm cache во временной
  директории;
- реальную установку из публичных registries;
- поиск потребителей старых namespace вне текущего workspace.

## Итоговая оценка

- **Выбор границ пакетов:** хороший.
- **Архитектурное направление:** хорошее.
- **Сохранение поведения:** частично подтверждено, но смешано с крупным рефакторингом.
- **Независимая локальная разработка:** в основном работает.
- **Качество публичных npm-контрактов:** недостаточное.
- **Готовность к публикации/стабильному `1.0.0`:** нет.
- **Общая оценка текущего результата:** примерно **7/10 по архитектуре** и **5/10 по release readiness**.

Главный вывод: разделять именно так было разумно. Делать это лучше следовало через сначала механическое выделение и
тестирование контрактов, затем отдельный рефакторинг. Сейчас не требуется возвращаться к монолиту; требуется завершить
package engineering и стабилизировать границы между уже правильно выделенными компонентами.

