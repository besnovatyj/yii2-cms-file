<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\api\operations;

use Besnovatyj\File\api\ApiContext;
use Besnovatyj\File\api\Contract;
use Besnovatyj\File\api\OperationInterface;
use Besnovatyj\File\api\OperationRegistry;
use Besnovatyj\File\api\Payload;
use Besnovatyj\File\api\StreamResult;
use Besnovatyj\File\fs\FsLimits;
use Besnovatyj\File\fs\mount\Mount;
use Besnovatyj\File\fs\mount\MountRegistry;
use Besnovatyj\File\fs\naming\NameRules;
use Besnovatyj\File\fs\policy\OperationPolicy;
use Besnovatyj\File\fs\tus\TusConfig;
use Besnovatyj\File\fs\thumbnail\ThumbnailConfig;

/**
 * `describe` (контракт §6) — самоописание бэкенда: версия контракта, доступные ТЕКУЩЕМУ
 * пользователю операции, точки монтирования с capabilities, правила имён и загрузки, лимиты,
 * флаги функций.
 *
 * Ничего не «решает» — только собирает то, что знают реестры и политика. Поэтому новая операция,
 * новое правило или новая точка монтирования появляются здесь без правок этого класса.
 */
final class DescribeOperation implements OperationInterface
{
    public function __construct(
        private readonly OperationRegistry $operations,
        private readonly MountRegistry $mounts,
        private readonly NameRules $naming,
        private readonly OperationPolicy $policy,
        private readonly FsLimits $limits,
        private readonly TusConfig $tus,
        private readonly ThumbnailConfig $thumbnails,
    ) {
    }

    public function name(): string
    {
        return 'describe';
    }

    public function method(): string
    {
        return 'POST';
    }

    public function info(): array
    {
        return [];
    }

    public function execute(Payload $input, ApiContext $context): array|StreamResult
    {
        $operations = [];
        foreach ($this->operations->all() as $name => $operation) {
            if (!$context->isOperationAllowed($name)) {
                continue;
            }
            $operations[$name] = ['method' => $operation->method()] + $operation->info();
        }

        $policy = $this->policy->describe();

        return [
            'contract' => ['name' => Contract::NAME, 'version' => Contract::VERSION],
            'server' => ['name' => Contract::SERVER_NAME, 'version' => Contract::SERVER_VERSION],
            'operations' => $operations,
            'mounts' => array_map(static fn(Mount $m): array => $m->toArray(), $this->mounts->all()),
            'defaultMount' => $this->mounts->defaultId(),
            // array_merge (не `+`): правые ключи должны ПЕРЕКРЫВАТЬ дефолт blockedExtensions.
            'naming' => array_merge($this->naming->toArray(), ['blockedExtensions' => []], $policy['naming'] ?? []),
            'upload' => array_merge(
                [
                    'maxFileSize' => null,
                    'maxFilesPerRequest' => 1,
                    'allowedExtensions' => null,
                    'allowedMime' => null,
                    'chunked' => false,
                    'tus' => null,
                ],
                $policy['upload'] ?? [],
                // Докачка по tus объявляется только если включена и пользователю доступна финализация.
                $this->tus->enabled && isset($operations['upload-finalize'])
                    ? ['chunked' => true, 'tus' => $this->tus->toArray()]
                    : [],
            ),
            'features' => [
                'jobs' => false,
                'thumbnails' => isset($operations['thumbnail']),
                'search' => isset($operations['search']),
                'write' => false,
                'archive' => false,
            ],
            'limits' => $this->limits->toArray(),
            'thumbnails' => isset($operations['thumbnail']) ? $this->thumbnails->toArray() : null,
            // Область запроса (токен X-Fs-Scope): клиент строит навигацию от неё; null — без ограничений.
            'scope' => $context->scope->isUnrestricted() ? null : ['root' => $context->scope->root->toString()],
        ];
    }
}
