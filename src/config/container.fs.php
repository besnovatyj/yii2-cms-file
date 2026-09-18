<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

use Besnovatyj\File\api\NodeSerializer;
use Besnovatyj\File\api\OperationRegistry;
use Besnovatyj\File\api\operations\ContentOperation;
use Besnovatyj\File\api\operations\CopyOperation;
use Besnovatyj\File\api\operations\DeleteOperation;
use Besnovatyj\File\api\operations\DescribeOperation;
use Besnovatyj\File\api\operations\DownloadOperation;
use Besnovatyj\File\api\operations\ListOperation;
use Besnovatyj\File\api\operations\MkdirOperation;
use Besnovatyj\File\api\operations\MoveOperation;
use Besnovatyj\File\api\operations\PreviewOperation;
use Besnovatyj\File\api\operations\RenameOperation;
use Besnovatyj\File\api\operations\SearchOperation;
use Besnovatyj\File\api\operations\StatOperation;
use Besnovatyj\File\api\operations\ThumbnailOperation;
use Besnovatyj\File\api\operations\TreeOperation;
use Besnovatyj\File\api\operations\UploadFinalizeOperation;
use Besnovatyj\File\api\operations\UploadOperation;
use Besnovatyj\File\fs\FsLimits;
use Besnovatyj\File\fs\mount\adapter\AdapterFactoryRegistry;
use Besnovatyj\File\fs\mount\adapter\LocalAdapterFactory;
use Besnovatyj\File\fs\mount\adapter\S3AdapterFactory;
use Besnovatyj\File\fs\mount\adapter\ZipAdapterFactory;
use Besnovatyj\File\fs\mount\MountDefinition;
use Besnovatyj\File\fs\mount\MountFactory;
use Besnovatyj\File\fs\mount\MountRegistry as FsMountRegistry;
use Besnovatyj\File\fs\naming\NameRules;
use Besnovatyj\File\fs\naming\NameSanitizer;
use Besnovatyj\File\fs\naming\CyrillicTransliterator;
use Besnovatyj\File\fs\naming\NameValidator;
use Besnovatyj\File\fs\naming\TransliteratorInterface;
use Besnovatyj\File\fs\naming\UniqueNameGenerator;
use Besnovatyj\File\fs\node\NodeFactory;
use Besnovatyj\File\fs\operation\ConflictResolver;
use Besnovatyj\File\fs\operation\Deleter;
use Besnovatyj\File\fs\operation\DirectoryCreator;
use Besnovatyj\File\fs\operation\Downloader;
use Besnovatyj\File\fs\operation\Inspector;
use Besnovatyj\File\fs\operation\Lister;
use Besnovatyj\File\fs\operation\NameGuard;
use Besnovatyj\File\fs\operation\Previewer;
use Besnovatyj\File\fs\operation\Renamer;
use Besnovatyj\File\fs\operation\Transfer;
use Besnovatyj\File\fs\operation\TreeCopier;
use Besnovatyj\File\fs\operation\Uploader;
use Besnovatyj\File\fs\path\PathResolver;
use Besnovatyj\File\fs\policy\AllowedExtensionsRule;
use Besnovatyj\File\fs\policy\ContentSniffRule;
use Besnovatyj\File\fs\policy\ExtensionBlocklistRule;
use Besnovatyj\File\fs\policy\MaxFileSizeRule;
use Besnovatyj\File\fs\policy\OperationPolicy;
use Besnovatyj\File\fs\thumbnail\ImagineGenerator;
use Besnovatyj\File\fs\thumbnail\ThumbnailCache;
use Besnovatyj\File\fs\thumbnail\ThumbnailConfig;
use Besnovatyj\File\fs\thumbnail\Thumbnailer;
use Besnovatyj\File\fs\thumbnail\ThumbnailGeneratorInterface;
use Besnovatyj\File\fs\tus\FileTusStore;
use Besnovatyj\File\fs\tus\TusConfig;
use Besnovatyj\File\fs\tus\TusServer;
use Besnovatyj\File\fs\tus\TusStoreInterface;
use Besnovatyj\File\fs\VirtualFileSystem;
use yii\di\Container;

/**
 * DI-проводка файлового менеджера v2 (домен `fs` + протокол `api`).
 *
 * Подключается из config/container.php. Читает настройки модуля `params.fs` (см. config/config.php),
 * а также общие с v1 секции `params.s3` и `params.upload` (чтобы одна настройка S3/лимита
 * действовала на обе линии, пока они живут рядом).
 *
 * Как читать файл: сверху вниз — от «сырых» настроек (правила имён, лимиты) к инфраструктуре
 * (адаптеры → mount'ы), затем политика, сервисы операций и, наконец, реестр операций API,
 * из которого контроллёр строит маршруты.
 */
return static function (Container $container): void {

    /** Настройки модуля: params.fs (+ общие секции). Читаются лениво — модуль может быть ещё не поднят. */
    $params = static function (): array {
        $module = \Yii::$app->getModule('File');
        return (array)($module?->params ?? []);
    };
    $fsParams = static fn(): array => (array)($params()['fs'] ?? []);

    /** Резолвер алиасов для адаптеров и baseUrl ('@static' → путь, '@staticHostName' → URL). */
    $aliasResolver = static fn(string $value): string => (string)\Yii::getAlias($value);

    // ---------------------------------------------------------------- правила имён и лимиты

    $container->setSingleton(NameRules::class, static fn(): NameRules => NameRules::fromArray((array)($fsParams()['naming'] ?? [])));
    $container->setSingleton(NameValidator::class, NameValidator::class);
    // Транслитерация применяется только при params.fs.naming.transliterate = true (см. NameSanitizer);
    // другая таблица — своя реализация интерфейса здесь.
    $container->setSingleton(TransliteratorInterface::class, CyrillicTransliterator::class);
    $container->setSingleton(NameSanitizer::class, NameSanitizer::class);
    $container->setSingleton(UniqueNameGenerator::class, UniqueNameGenerator::class);
    $container->setSingleton(FsLimits::class, static fn(): FsLimits => FsLimits::fromArray((array)($fsParams()['limits'] ?? [])));

    // ---------------------------------------------------------------- адаптеры и точки монтирования

    $container->setSingleton(AdapterFactoryRegistry::class, static fn(): AdapterFactoryRegistry => new AdapterFactoryRegistry([
        new LocalAdapterFactory($aliasResolver),
        new ZipAdapterFactory($aliasResolver),
        new S3AdapterFactory(),
        // Новый тип хранилища (FTP, SFTP, WebDAV…) — ещё одна фабрика здесь.
    ]));

    // ---------------------------------------------------------------- миниатюры

    $container->setSingleton(ThumbnailConfig::class, static function () use ($fsParams): ThumbnailConfig {
        $cfg = (array)($fsParams()['thumbnails'] ?? []);
        return ThumbnailConfig::fromArray($cfg, (string)\Yii::getAlias((string)($cfg['dir'] ?? '@runtime/fs-thumbs')));
    });
    $container->setSingleton(ThumbnailGeneratorInterface::class, ImagineGenerator::class);
    $container->setSingleton(ThumbnailCache::class, static fn(Container $c): ThumbnailCache => new ThumbnailCache($c->get(ThumbnailConfig::class)->dir));
    $container->setSingleton(Thumbnailer::class, Thumbnailer::class);

    $container->setSingleton(MountFactory::class, static fn(Container $c): MountFactory => new MountFactory(
        $c->get(AdapterFactoryRegistry::class),
        $aliasResolver,
        // Миниатюры объявляются у mount'ов только если включены и генератор реально доступен.
        $c->get(ThumbnailConfig::class)->enabled && $c->get(ThumbnailGeneratorInterface::class)->isAvailable(),
    ));

    $container->setSingleton(FsMountRegistry::class, static function (Container $c) use ($params, $fsParams): FsMountRegistry {
        /** @var MountFactory $factory */
        $factory = $c->get(MountFactory::class);
        $fs = $fsParams();

        $definitions = $fs['mounts'] ?? null;
        if (!is_array($definitions)) {
            // Автосборка (как в v1): локальный @static всегда; ZIP и S3 — по наличию.
            $definitions = [[
                'id' => 'static',
                'adapter' => 'local',
                'label' => 'Файлы сайта',
                'icon' => 'drive',
                'options' => ['root' => '@static'],
                'baseUrl' => '@staticHostName',
            ]];

            $zipPath = (string)\Yii::getAlias('@static/zip/test.zip');
            if (is_file($zipPath)) {
                $definitions[] = [
                    'id' => 'zip',
                    'adapter' => 'zip',
                    'label' => 'Тестовый ZIP-архив',
                    'icon' => 'archive',
                    'options' => ['archive' => $zipPath],
                ];
            }

            $s3 = (array)($params()['s3'] ?? []);
            if (!empty($s3['key']) && !empty($s3['bucket'])) {
                $definitions[] = [
                    'id' => 'aws',
                    'adapter' => 's3',
                    'label' => 'AWS S3',
                    'icon' => 'cloud',
                    'options' => $s3,
                    'baseUrl' => (string)($s3['baseUrl'] ?? ''),
                ];
            }
        }

        $mounts = [];
        foreach ($definitions as $definition) {
            $mounts[] = $factory->make(MountDefinition::fromArray((array)$definition));
        }

        $defaultId = isset($fs['defaultMount']) ? (string)$fs['defaultMount'] : null;
        $ids = array_map(static fn($m) => $m->id, $mounts);
        if ($defaultId !== null && !in_array($defaultId, $ids, true)) {
            $defaultId = null; // настроенный дефолт не собран (например, S3 без ключей) — берём первый
        }

        return new FsMountRegistry($mounts, $defaultId);
    });

    // ---------------------------------------------------------------- политика операций

    $container->setSingleton(OperationPolicy::class, static function () use ($params, $fsParams): OperationPolicy {
        $policy = (array)($fsParams()['policy'] ?? []);
        $legacyUpload = (array)($params()['upload'] ?? []); // общая с v1 секция

        $rules = [];

        $blocked = $policy['blockedExtensions'] ?? $legacyUpload['blockedExtensions'] ?? null;
        if ($blocked === null) {
            $rules[] = new ExtensionBlocklistRule();
        } elseif ((array)$blocked !== []) {
            $rules[] = new ExtensionBlocklistRule((array)$blocked);
        }
        // [] — блок-лист осознанно отключён (не рекомендуется).

        if (!empty($policy['allowedExtensions'])) {
            $rules[] = new AllowedExtensionsRule((array)$policy['allowedExtensions']);
        }

        $maxFileSize = $policy['maxFileSize'] ?? $legacyUpload['maxFileSize'] ?? null;
        $rules[] = new MaxFileSizeRule($maxFileSize !== null && (int)$maxFileSize > 0 ? (int)$maxFileSize : null);

        $sniff = $policy['contentSniff'] ?? false;
        if ($sniff === true || $sniff === 'strict') {
            $rules[] = new ContentSniffRule(strict: $sniff === 'strict');
        }

        return new OperationPolicy($rules);
    });

    // ---------------------------------------------------------------- tus (докачиваемая загрузка)

    $container->setSingleton(TusConfig::class, static function () use ($fsParams): TusConfig {
        $tus = (array)($fsParams()['tus'] ?? []);
        $dir = (string)\Yii::getAlias((string)($tus['dir'] ?? '@runtime/fs-tus'));
        // URL создания загрузки нужен только web-приложению; в консоли (purge) роутера может не быть.
        $endpoint = \Yii::$app instanceof \yii\web\Application
            ? \yii\helpers\Url::to(['/File/backend/tus/create'])
            : '';
        return TusConfig::fromArray($tus, $dir, $endpoint);
    });
    $container->setSingleton(TusStoreInterface::class, static fn(Container $c): FileTusStore => new FileTusStore($c->get(TusConfig::class)->dir));
    $container->setSingleton(TusServer::class, static function (Container $c): TusServer {
        // Лимит заявленной длины — тот же, что у обычной загрузки (MaxFileSizeRule).
        $maxLength = null;
        foreach ($c->get(OperationPolicy::class)->rules() as $rule) {
            if ($rule instanceof MaxFileSizeRule) {
                $maxLength = $rule->effectiveLimit();
            }
        }
        return new TusServer($c->get(TusStoreInterface::class), $c->get(TusConfig::class), $maxLength);
    });

    // ---------------------------------------------------------------- сервисы операций (autowiring)

    $container->setSingleton(PathResolver::class, static fn(Container $c): PathResolver => new PathResolver($c->get(FsMountRegistry::class)));
    $container->setSingleton(NodeFactory::class, static fn(): NodeFactory => new NodeFactory());
    $container->setSingleton(NameGuard::class, NameGuard::class);
    $container->setSingleton(ConflictResolver::class, ConflictResolver::class);
    $container->setSingleton(TreeCopier::class, TreeCopier::class);
    $container->setSingleton(Lister::class, Lister::class);
    $container->setSingleton(Inspector::class, Inspector::class);
    $container->setSingleton(DirectoryCreator::class, DirectoryCreator::class);
    $container->setSingleton(Renamer::class, Renamer::class);
    $container->setSingleton(Transfer::class, Transfer::class);
    $container->setSingleton(Deleter::class, Deleter::class);
    $container->setSingleton(Uploader::class, Uploader::class);
    $container->setSingleton(Downloader::class, Downloader::class);
    $container->setSingleton(Previewer::class, Previewer::class);
    $container->setSingleton(VirtualFileSystem::class, static fn(Container $c): VirtualFileSystem => new VirtualFileSystem(
        $c->get(FsMountRegistry::class),
        $c->get(Lister::class),
        $c->get(Inspector::class),
        $c->get(DirectoryCreator::class),
        $c->get(Renamer::class),
        $c->get(Transfer::class),
        $c->get(Deleter::class),
        $c->get(Uploader::class),
        $c->get(Downloader::class),
    ));

    // ---------------------------------------------------------------- реестр операций API

    $container->setSingleton(NodeSerializer::class, NodeSerializer::class);

    $container->setSingleton(OperationRegistry::class, static function (Container $c): OperationRegistry {
        $registry = new OperationRegistry();

        // describe получает сам реестр (ещё пустой) и перечисляет операции в момент вызова.
        $registry->register(new DescribeOperation(
            $registry,
            $c->get(FsMountRegistry::class),
            $c->get(NameRules::class),
            $c->get(OperationPolicy::class),
            $c->get(FsLimits::class),
            $c->get(TusConfig::class),
            $c->get(ThumbnailConfig::class),
        ));

        foreach ([
            ListOperation::class,
            TreeOperation::class,
            StatOperation::class,
            ContentOperation::class,
            MkdirOperation::class,
            RenameOperation::class,
            MoveOperation::class,
            CopyOperation::class,
            DeleteOperation::class,
            UploadOperation::class,
            DownloadOperation::class,
            PreviewOperation::class,
            SearchOperation::class,
            // Новая операция контракта — ещё один класс в этом списке.
        ] as $operationClass) {
            $registry->register($c->get($operationClass));
        }
        // Миниатюры — только если включены и есть Imagick/GD.
        if ($c->get(ThumbnailConfig::class)->enabled && $c->get(ThumbnailGeneratorInterface::class)->isAvailable()) {
            $registry->register($c->get(ThumbnailOperation::class));
        }
        // Финализация tus — только при включённой докачке (иначе describe её не объявляет).
        if ($c->get(TusConfig::class)->enabled) {
            $registry->register($c->get(UploadFinalizeOperation::class));
        }

        return $registry;
    });
};
