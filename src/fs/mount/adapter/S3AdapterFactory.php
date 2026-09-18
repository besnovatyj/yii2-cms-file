<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\mount\adapter;

use Aws\S3\S3Client;
use Besnovatyj\File\fs\mount\MountCapabilities;
use Besnovatyj\File\fs\mount\MountDefinition;
use InvalidArgumentException;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;

/**
 * AWS S3 и S3-совместимые хранилища (MinIO, Yandex Object Storage и т. п.).
 *
 * Опции: `key`, `secret`, `bucket` (обяз.), `region`, `prefix` (скрытый «корень» внутри бакета),
 * `endpoint`, `usePathStyle`.
 *
 * S3 не имеет папок: они эмулируются префиксами (`directories: 'emulated'`), поэтому пустая папка
 * существует только как объект-маркер `prefix/`; перемещение папок — только рекурсивно по объектам.
 */
final class S3AdapterFactory implements AdapterFactoryInterface
{
    public function key(): string
    {
        return 's3';
    }

    public function build(MountDefinition $definition): AdapterBuild
    {
        $o = $definition->options;
        $bucket = (string)($o['bucket'] ?? '');
        if ($bucket === '' || (string)($o['key'] ?? '') === '') {
            throw new InvalidArgumentException("Точка монтирования '{$definition->id}': нужны options.key и options.bucket.");
        }

        $clientConfig = [
            'version' => 'latest',
            // S3Client требует непустой регион даже для совместимых хранилищ.
            'region' => (string)($o['region'] ?? '') ?: 'us-east-1',
            'credentials' => [
                'key' => (string)$o['key'],
                'secret' => (string)($o['secret'] ?? ''),
            ],
        ];
        if (!empty($o['endpoint'])) {
            $clientConfig['endpoint'] = (string)$o['endpoint'];
        }
        if (!empty($o['usePathStyle'])) {
            $clientConfig['use_path_style_endpoint'] = true;
        }

        $adapter = new AwsS3V3Adapter(new S3Client($clientConfig), $bucket, (string)($o['prefix'] ?? ''));

        return new AdapterBuild($adapter, new MountCapabilities(
            publicUrl: $definition->baseUrl !== '',
            visibility: true,
            directories: 'emulated',
            search: true, // обход listContents(deep) — поиск по именам без индекса
            nativeDirectoryMove: false,
        ));
    }
}
