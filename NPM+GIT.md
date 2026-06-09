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
