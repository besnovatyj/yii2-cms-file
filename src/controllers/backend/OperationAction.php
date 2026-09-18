<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\controllers\backend;

use Besnovatyj\File\api\ApiContext;
use Besnovatyj\File\api\ApiException;
use Besnovatyj\File\api\Envelope;
use Besnovatyj\File\api\ErrorCode;
use Besnovatyj\File\api\OperationInterface;
use Besnovatyj\File\api\Payload;
use Besnovatyj\File\api\scope\ScopeToken;
use Besnovatyj\File\fs\path\PathScope;
use Besnovatyj\File\api\StreamResult;
use Besnovatyj\File\fs\exception\FsException;
use Besnovatyj\File\fs\operation\UploadSource;
use Besnovatyj\Kernel\security\AccessHelper;
use Throwable;
use Yii;
use yii\base\Action;
use yii\web\Response;
use yii\web\UploadedFile;

/**
 * Yii-адаптер одной операции API: HTTP-запрос → {@see Payload}/{@see ApiContext} → операция →
 * конверт (или поток). Единственное место, где протокол встречается с Yii.
 *
 * Обработка ошибок — по источнику:
 *  - {@see ApiException} (ошибки входа) и {@see FsException} (доменные) — конверт с кодом и
 *    статусом; клиентские ошибки в лог не пишутся;
 *  - всё прочее (адаптер хранилища, баги) — `internal`: наружу нейтральный текст, причина — в лог.
 *
 * Контроллёр создаёт по одному экземпляру на операцию (см. {@see ApiController::actions()}),
 * поэтому у каждой операции свой маршрут и своё RBAC-разрешение.
 */
final class OperationAction extends Action
{
    /** Имя поля multipart-запроса с загружаемым файлом (контракт §9.9). */
    public const string FILE_FIELD = 'file';

    /** Заголовок / query-параметр с токеном области видимости (контракт §1). */
    public const string SCOPE_HEADER = 'X-Fs-Scope';
    public const string SCOPE_QUERY = 'scope';

    public OperationInterface $operation;

    /**
     * @return array<string, mixed>|Response
     */
    public function run(): array|Response
    {
        $response = Yii::$app->response;
        $startedAt = hrtime(true);

        try {
            $this->assertMethod();
            $scope = $this->resolveScope();
            $result = $this->operation->execute($this->buildPayload($scope), $this->buildContext($scope));

            if ($result instanceof StreamResult) {
                return $this->sendStream($result, $response);
            }

            $response->format = Response::FORMAT_JSON;
            $response->setStatusCode(200);

            return Envelope::success($result, ['elapsedMs' => (int)((hrtime(true) - $startedAt) / 1_000_000)]);
        } catch (ApiException $e) {
            return $this->fail($e, $response);
        } catch (FsException $e) {
            return $this->fail(ApiException::fromFs($e), $response);
        } catch (Throwable $e) {
            // Сюда попадают и исключения Flysystem (FilesystemException — интерфейс над Throwable).
            return $this->fail(ApiException::internal($e), $response);
        }
    }

    /**
     * Метод запроса должен совпадать с объявленным операцией: мутации только POST (их защищает
     * CSRF-фильтр Yii), потоковые — GET.
     */
    private function assertMethod(): void
    {
        $expected = $this->operation->method();
        $actual = Yii::$app->request->getMethod();
        if ($actual !== $expected) {
            throw new ApiException(
                ErrorCode::BadRequest,
                "Операция '{$this->operation->name()}' принимает только {$expected}.",
                null,
                ['method' => $actual, 'expected' => $expected],
                null,
                405,
            );
        }
    }

    /**
     * Область видимости запроса из токена (заголовок для XHR, query — для GET-навигации download).
     * Нет токена — область не ограничена (standalone-менеджер, RBAC по маршрутам остаётся).
     * Есть, но невалиден — `forbidden`: битый токен не открывает «всё».
     */
    private function resolveScope(): PathScope
    {
        $request = Yii::$app->request;
        $token = $request->headers->get(self::SCOPE_HEADER) ?? $request->get(self::SCOPE_QUERY);
        if (!is_string($token) || $token === '') {
            return PathScope::unrestricted();
        }
        return ScopeToken::verify($token, Yii::$app->getUser()->getId());
    }

    private function buildPayload(PathScope $scope): Payload
    {
        $request = Yii::$app->request;
        $params = $request->getIsGet() ? $request->get() : $request->getBodyParams();

        $files = [];
        $uploaded = UploadedFile::getInstanceByName(self::FILE_FIELD);
        if ($uploaded instanceof UploadedFile) {
            $files[self::FILE_FIELD] = $this->toUploadSource($uploaded);
        }

        return new Payload(is_array($params) ? $params : [], $files, $scope);
    }

    private function buildContext(PathScope $scope): ApiContext
    {
        $controllerRoute = '/' . $this->controller->getUniqueId() . '/';

        return new ApiContext(
            Yii::$app->getUser()->getId(),
            static fn(string $operation): bool => AccessHelper::checkRoute($controllerRoute . $operation),
            $scope,
        );
    }

    /**
     * `UploadedFile` → доменный {@see UploadSource}. Ошибки PHP-загрузки переводятся в коды
     * контракта: превышение ini-лимита — `too_large`, остальное — `bad_request`.
     */
    private function toUploadSource(UploadedFile $file): UploadSource
    {
        switch ($file->error) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                throw new ApiException(ErrorCode::TooLarge, 'Файл превышает лимит размера сервера.', null, ['phpError' => $file->error]);
            default:
                throw ApiException::badRequest('Файл не был загружен полностью.', ['phpError' => $file->error]);
        }
        if (!is_uploaded_file($file->tempName)) {
            throw ApiException::badRequest('Некорректный источник загрузки.');
        }

        return new UploadSource($file->tempName, (int)$file->size, (string)$file->name, $file->type ?: null);
    }

    /**
     * Потоковая отдача. По умолчанию строго attachment + nosniff — inline-отдача пользовательских
     * файлов (.html/.svg) с домена админки была бы хранимым XSS (см. SECURITY.md). Inline разрешён
     * только потоку, помеченному доменом (`Previewer`: растровые изображения, тип по содержимому),
     * и дополнительно закрыт CSP `sandbox`: даже если бы в изображении оказался активный контент,
     * ему не дадут ни скриптов, ни доступа к origin.
     */
    private function sendStream(StreamResult $result, Response $response): Response
    {
        $stream = $result->stream;
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        if ($stream->inline) {
            $response->headers->set('Content-Security-Policy', "default-src 'none'; sandbox");
            $response->headers->set('Cache-Control', $stream->cacheMaxAge > 0 ? "private, max-age={$stream->cacheMaxAge}" : 'private, no-store');
        } else {
            $response->headers->set('Cache-Control', 'private, no-store');
        }

        $handle = $stream->stream;
        $options = ['mimeType' => $stream->mime, 'inline' => $stream->inline];

        if ($stream->size !== null) {
            $options['fileSize'] = $stream->size;
        } elseif (!stream_get_meta_data($handle)['seekable']) {
            // Размер неизвестен, а поток не перематывается (S3/ZIP): Yii выставил бы Content-Length: 0.
            // Буферизуем в php://temp (память → файл после 2 МБ), чтобы размер стал известен.
            $buffer = fopen('php://temp/maxmemory:2097152', 'w+b');
            stream_copy_to_stream($handle, $buffer);
            fclose($handle);
            rewind($buffer);
            $handle = $buffer;
        }

        return $response->sendStreamAsFile($handle, $stream->name, $options);
    }

    /**
     * @return array<string, mixed>
     */
    private function fail(ApiException $e, Response $response): array
    {
        if ($e->isInternal()) {
            Yii::$app->errorHandler->logException($e->getPrevious() ?? $e);
        } else {
            Yii::info(sprintf('[fs-api:%s] %s: %s', $this->operation->name(), $e->errorCode->value, $e->getMessage()), __METHOD__);
        }

        $response->format = Response::FORMAT_JSON;
        $response->setStatusCode($e->httpStatus());

        return Envelope::error($e);
    }
}
