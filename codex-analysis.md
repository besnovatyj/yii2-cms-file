# Моя заметка по пакетам и модулям входящим в данный рабочий процесс

Пути к пакетам:

- `/workspace/app/packages/besnovatyj/yii2-cms-file-before` - Старый пакет, в состоянии до разделения на отдельные
  пакеты и до перехода на Flysystem

- `/workspace/app/packages/besnovatyj/ckeditor5-codemirror` - Плагин codemirror для CKEditor 5 (PHP+TS)
- `/workspace/app/packages/npm/filemanager-core` - Файловый менеджер на TS.
- `/workspace/app/packages/besnovatyj/ckeditor5-filemanager` - CKEditor 5 адаптер файлового менеджера (TS адаптер + PHP
  AssetBundle)
- `/workspace/app/packages/besnovatyj/yii2-cms-ckeditor5` - CKEditor 5 (TS ckeditor5 ядро, TS базовый редактор с кучей
  плагинов (собираем в бандл все плагины, включаем необходимые через конфиг в PHP виджете), PHP-виджет, PHP-контракт для
  AssetBundle плагинов)
- `/workspace/app/packages/besnovatyj/yii2-cms-file` - PHP бэкэнд файлового менеджера на Flysystem + PHP виджет CKEditor
  5 с подключёнными плагинами
- `/workspace/app/packages/besnovatyj/yii2-cms-file-manager` - Standalone PHP виджет файлового менеджера
  \+ slim TS FM wrap

Заметка отражает текущее состояние по пакетам на 9 июня 2026 года.

---

# Анализ разделения `yii2-cms-file-before`

Дата анализа: 8 июня 2026 года.

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
причины: отсутствующие declaration-файлы при заявленном типизированном API, локальная `file:`-зависимость в
npm-манифесте, несовместимые диапазоны CKEditor, рассинхронизация нескольких DTO и отсутствие автоматических тестов
контрактов.

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

### P0. npm-пакеты заявляют типы, которых нет в `dist`

Фактическое состояние:

- `filemanager-core/package.json` объявляет `types: dist/standalone.d.ts`, но Git содержит только `standalone.js` и map;
- `ckeditor5-filemanager/package.json` объявляет `dist/index.d.ts`, но файла нет;
- `ckeditor5-codemirror/package.json` объявляет `dist/codemirror.d.ts`, но файла нет.

Из-за этого `ckeditor5-filemanager` уже сейчас не проходит strict TypeScript-проверку с `TS7016`: TypeScript не находит
declarations для `@besnovatyj/filemanager-core`.

Причина в `filemanager-core`: скрипт `types` пытается переопределить `noEmit`, но declaration-файл не является частью
текущего коммита и не создаётся обычным `npm run build`. У плагинов отдельного корректного шага генерации declarations
вообще нет, а в `ckeditor5-codemirror/tsconfig.json` одновременно указаны `noEmit: true` и `emitDeclarationOnly: true`.

Рекомендация:

1. В каждом публикуемом npm-пакете сделать единый `build`, который гарантированно создаёт JS, source map и `.d.ts`.
2. Добавить отдельный `tsconfig.build.json` без `noEmit`.
3. Проверять существование всех путей из `main`, `types` и `exports` в CI и через `npm pack --dry-run`.
4. Не считать пакет выпущенным, если `npm install` из tarball и consumer typecheck не проходят.

### P0. `ckeditor5-filemanager` не является переносимым npm-пакетом

Зависимость задана как:

```json
{
    "@besnovatyj/filemanager-core": "file:../../npm/filemanager-core"
}
```

Это допустимо только для локальной разработки. После публикации или установки tarball относительный путь потребителя не
будет соответствовать структуре текущего workspace.

Рекомендация: использовать нормальный semver (`^1.0.0`) в публикуемом манифесте. Для локальной разработки выбрать npm
workspaces, pnpm workspace или overrides. Локальная топология не должна попадать в публичный dependency contract.

### P1. Версии CKEditor между пакетами рассинхронизированы

- базовый пакет собирается с `ckeditor5 ^48.2.0` и lock-файлом `48.2.0`;
- оба плагина объявляют peer `ckeditor5 ^47.2.0` и собраны/проверены с `47.7.2`.

Для peer dependency диапазон `^47.2.0` не допускает `48.2.0`. Формально пакетный граф несовместим, даже если текущий
ESM-код случайно работает через import map.

Дополнительно плагины компилируются против API 47, а в браузере получают API 48. Это риск несовместимости
private/semi-public CKEditor API.

Рекомендация: все пакеты одной релизной линии должны проверяться на одной версии CKEditor. Если поддерживаются две
major-версии, peer range и CI-матрица должны это явно подтверждать.

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

### P1. Контракт upload также описан неточно

Frontend ожидает `UploadResponse` с `path` и `fileName`, а PHP возвращает поля `path`, `name`, `url`, `extension`,
`type`, `fileType`, `meta`. Комментарий в интерфейсе прямо признаёт расхождение.

Пока результат upload почти игнорируется, поэтому ошибка маскируется. При дальнейшем использовании ответа типы станут
источником дефектов.

Рекомендация: вернуть `FileDto` или отдельный точно совпадающий `UploadResponseDto`; удалить комментарии вида «можно
игнорировать» из публичных контрактов.

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

У core есть полезный README, но README плагинов почти пустые и не описывают:

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

1. ✅ Исправить генерацию и публикацию `.d.ts` во всех трёх npm-пакетах.
2. ✅ Убрать `file:../../npm/filemanager-core` из публикуемого манифеста.
3. ✅ Выровнять CKEditor на одной major/minor линии и пересобрать оба плагина.
4. Синхронизировать `rename`, `upload`, `move`, delete error items и прочие DTO между PHP и TypeScript.
5. Исправить lifecycle listeners и убрать доступ к private `bus`.
6. Добавить contract tests backend API и consumer typecheck из tarball.
7. Определить границу `yii2-cms-file`: backend-only или интеграционный пакет; при backend-only вынести compatibility
   widget.
8. Добавить migration guide и минимальные README для каждого пакета.
9. После этого присвоить релизные версии и проверять каждый пакет независимо.

## Выполненные проверки и ограничения анализа

Успешно:

- `yii2-cms-ckeditor5`: TypeScript `tsc --noEmit` проходит;
- `filemanager-core`: TypeScript `tsc --noEmit` проходит;
- проверены фактические ESM imports в собранных plugin bundles: `ckeditor5` оставлен external и должен резолвиться
  import map;
- проверен состав tracked `dist`: declaration-файлы действительно отсутствуют;
- сопоставлены PHP controller/service responses и TypeScript ports/DTO;
- рабочие деревья анализируемых Git-репозиториев до создания этого отчёта были чистыми.

Не прошло:

- `ckeditor5-filemanager`: `tsc --noEmit` завершается с `TS7016` из-за отсутствующего `dist/standalone.d.ts` у core.

Не удалось проверить:

- PHP syntax/lint и `composer validate`, потому что в текущем host-окружении нет команд `php` и `composer`;
- end-to-end работу в браузере и Yii2 runtime;
- реальную установку из публичных registries, поскольку разработка использует локальные path/file dependencies;
- полный поиск внешних потребителей старых namespace из-за ошибок чтения части большого workspace; совместимость
  подтверждена только по структуре самих анализируемых пакетов.

## Итоговая оценка

- **Выбор границ пакетов:** хороший.
- **Архитектурное направление:** хорошее.
- **Сохранение поведения:** частично подтверждено, но смешано с крупным рефакторингом.
- **Независимая локальная разработка:** в основном работает.
- **Качество публичных npm-контрактов:** недостаточное.
- **Готовность к публикации/стабильному `1.0.0`:** нет.
- **Общая оценка текущего результата:** примерно **7/10 по архитектуре** и **4/10 по release readiness**.

Главный вывод: разделять именно так было разумно. Делать это лучше следовало через сначала механическое выделение и
тестирование контрактов, затем отдельный рефакторинг. Сейчас не требуется возвращаться к монолиту; требуется завершить
package engineering и стабилизировать границы между уже правильно выделенными компонентами.

