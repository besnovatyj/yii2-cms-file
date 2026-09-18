<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\api\operations;

use Besnovatyj\File\api\ApiContext;
use Besnovatyj\File\api\ApiException;
use Besnovatyj\File\api\ErrorCode;
use Besnovatyj\File\api\NodeSerializer;
use Besnovatyj\File\api\OperationInterface;
use Besnovatyj\File\api\Payload;
use Besnovatyj\File\api\StreamResult;
use Besnovatyj\File\fs\exception\AlreadyExistsException;
use Besnovatyj\File\fs\operation\ConflictStrategy;
use Besnovatyj\File\fs\operation\Uploader;
use Besnovatyj\File\fs\operation\UploadSource;
use Besnovatyj\File\fs\tus\TusException;
use Besnovatyj\File\fs\tus\TusServer;

/**
 * `upload-finalize` (контракт §9.12): завершённая tus-загрузка → файл в папке.
 *
 * Вход: `{ uploadId, path, name?, onConflict? }`; ответ — как у `upload` (`{node, renamed, requestedName}`).
 * Байты уже на сервере, поэтому при конфликте (`exists`) загрузка сохраняется, и клиент повторяет
 * финализацию с другой стратегией без повторной передачи. При любом другом исходе (успех, отказ
 * политики) временный файл удаляется.
 */
final class UploadFinalizeOperation implements OperationInterface
{
    public function __construct(
        private readonly TusServer $tus,
        private readonly Uploader $uploader,
        private readonly NodeSerializer $serializer,
    ) {
    }

    public function name(): string
    {
        return 'upload-finalize';
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
        $uploadId = $input->nonEmptyString('uploadId');
        $directory = $input->path('path');
        $strategy = ConflictStrategy::fromInput($input->optString('onConflict'));

        try {
            $upload = $this->tus->completed($uploadId, (string)($context->userId ?? ''));
        } catch (TusException $e) {
            throw new ApiException(
                $e->status === 409 ? ErrorCode::InvalidOperation : ErrorCode::NotFound,
                $e->getMessage(),
                null,
                ['uploadId' => $uploadId],
                $e,
            );
        }

        $source = new UploadSource($this->tus->dataPath($upload), $upload->length, $upload->clientName(), $upload->clientMime());

        try {
            $result = $this->uploader->upload($directory, $source, $input->optString('name'), $strategy);
        } catch (AlreadyExistsException $e) {
            throw $e; // байты остаются для повтора с другой стратегией
        } catch (\Throwable $e) {
            $this->tus->discard($upload);
            throw $e;
        }
        $this->tus->discard($upload);

        return [
            'node' => $this->serializer->node($result->node),
            'renamed' => $result->renamed,
            'requestedName' => $result->requestedName,
        ];
    }
}
