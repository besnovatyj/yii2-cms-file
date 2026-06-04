<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\storage;

use Aws\S3\S3Client;
use Besnovatyj\File\storage\exceptions\StorageException;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\ZipArchive\FilesystemZipArchiveProvider;
use League\Flysystem\ZipArchive\ZipArchiveAdapter;
use Yii;

/**
 * Фабрика точек монтирования: по декларативному описанию собирает {@see StorageMount} с нужным
 * Flysystem-адаптером. Единственное место, где код знает о конкретных адаптерах — добавление нового
 * типа сводится к новой ветке `match`.
 *
 * Формат описания (см. config/container.php):
 *   ['id' => 'static', 'adapter' => 'local', 'root' => '@static',           'baseUrl' => '@staticHostName', 'label' => '...']
 *   ['id' => 'zip',    'adapter' => 'zip',   'archive' => '@static/zip/test.zip', 'baseUrl' => '', 'label' => '...']
 *   ['id' => 'aws',    'adapter' => 's3',    's3' => [key,secret,region,bucket,prefix,endpoint,usePathStyle], 'baseUrl' => '...', 'label' => '...']
 */
final class StorageMountFactory
{
    public function make(array $def): StorageMount
    {
        $type = (string)($def['adapter'] ?? '');

        $adapter = match ($type) {
            'local' => new LocalFilesystemAdapter((string)Yii::getAlias((string)$def['root'])),
            'zip' => new ZipArchiveAdapter(
                new FilesystemZipArchiveProvider((string)Yii::getAlias((string)$def['archive']))
            ),
            's3' => $this->makeS3Adapter((array)($def['s3'] ?? [])),
            default => throw new StorageException("Неизвестный тип адаптера хранилища: '{$type}'"),
        };

        return new StorageMount(
            id: (string)$def['id'],
            filesystem: new Filesystem($adapter),
            baseUrl: (string)($def['baseUrl'] ?? ''),
            label: (string)($def['label'] ?? ''),
        );
    }

    /**
     * Строит адаптер AWS S3 (v3). Поддерживает и S3-совместимые хранилища через endpoint/path-style.
     * Параметры приходят из настроек модуля (config-модуль → params.s3).
     */
    private function makeS3Adapter(array $s3): FilesystemAdapter
    {
        $clientConfig = [
            'version' => 'latest',
            // S3Client требует непустой регион даже для S3-совместимых хранилищ — даём безопасный дефолт.
            'region' => (string)($s3['region'] ?? '') ?: 'us-east-1',
            'credentials' => [
                'key' => (string)($s3['key'] ?? ''),
                'secret' => (string)($s3['secret'] ?? ''),
            ],
        ];
        if (!empty($s3['endpoint'])) {
            $clientConfig['endpoint'] = (string)$s3['endpoint'];
        }
        if (!empty($s3['usePathStyle'])) {
            $clientConfig['use_path_style_endpoint'] = true;
        }

        $client = new S3Client($clientConfig);

        // prefix играет роль скрытого «корня» внутри бакета (аналог realRoot у локального адаптера).
        return new AwsS3V3Adapter($client, (string)($s3['bucket'] ?? ''), (string)($s3['prefix'] ?? ''));
    }
}
