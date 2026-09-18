<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\api\scope;

use Besnovatyj\File\api\ApiException;
use Besnovatyj\File\api\ErrorCode;
use Besnovatyj\File\fs\exception\PathInvalidException;
use Besnovatyj\File\fs\path\PathScope;
use Besnovatyj\File\fs\path\VirtualPath;
use RuntimeException;
use Yii;

/**
 * Подписанный токен области видимости (контракт §1, заголовок `X-Fs-Scope` / query `scope`).
 *
 * Выпускается PHP-виджетом при рендере страницы (он один знает, для какой сущности открыт
 * менеджер), проверяется {@see \Besnovatyj\File\controllers\backend\OperationAction} на каждом
 * запросе. Подделать нельзя: HMAC-SHA256 на ключе, производном от `cookieValidationKey`
 * приложения; токен привязан к id пользователя и имеет срок жизни.
 *
 * Формат: `base64url(json{p: путь, u: userId, e: exp}) . '.' . hmac`.
 *
 * Это единственный Yii-зависимый класс в `api/` (ему нужен ключ приложения) — вынесен в отдельный
 * подкаталог, чтобы остальной протокольный слой оставался переносимым.
 */
final class ScopeToken
{
    /** Срок жизни по умолчанию — 12 часов (дольше типичной сессии редактирования). */
    public const int DEFAULT_TTL = 43_200;

    private const string HKDF_INFO = 'bescms-fs-scope';

    private function __construct()
    {
    }

    /**
     * Выпустить токен для области $rootPath текущему пользователю.
     *
     * @param string $rootPath Канонический виртуальный путь корня области ('/static/blog/12').
     * @param int|string|null $userId Пользователь; null — текущий (Yii::$app->user).
     * @throws PathInvalidException путь некорректен (ошибка конфигурации виджета).
     */
    public static function issue(string $rootPath, int|string|null $userId = null, int $ttl = self::DEFAULT_TTL): string
    {
        // Конфиг виджетов часто пишут с хвостовым слешем ('/static/origin/Blog/12/') — нормализуем,
        // остальное валидируем строго: битый путь в токене бессмыслен.
        $normalized = preg_replace('#/{2,}#', '/', $rootPath) ?? $rootPath;
        $normalized = $normalized === '/' ? '/' : rtrim($normalized, '/');
        $root = VirtualPath::parse($normalized);
        $payload = json_encode([
            'p' => $root->toString(),
            'u' => $userId ?? Yii::$app->getUser()->getId(),
            'e' => time() + $ttl,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $data = self::base64url($payload);
        // hashData возвращает hex(hmac) . data — берём только подпись, data кладём отдельно (читаемо).
        $signed = Yii::$app->getSecurity()->hashData($data, self::key());
        return $data . '.' . substr($signed, 0, -strlen($data));
    }

    /**
     * Проверить токен и вернуть область. Любая проблема (подпись, срок, чужой пользователь,
     * формат) — `forbidden`: клиент с битым токеном не должен получить доступ «без области».
     *
     * @throws ApiException
     */
    public static function verify(string $token, int|string|null $currentUserId): PathScope
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw self::rejected('malformed');
        }
        [$data, $signature] = $parts;

        $validated = Yii::$app->getSecurity()->validateData($signature . $data, self::key());
        if ($validated === false) {
            throw self::rejected('signature');
        }

        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        $claims = $decoded === false ? null : json_decode($decoded, true);
        if (!is_array($claims) || !isset($claims['p'], $claims['e'])) {
            throw self::rejected('malformed');
        }
        if ((int)$claims['e'] < time()) {
            throw self::rejected('expired');
        }
        if ((string)($claims['u'] ?? '') !== (string)($currentUserId ?? '')) {
            throw self::rejected('user_mismatch');
        }

        try {
            return new PathScope(VirtualPath::parse((string)$claims['p']));
        } catch (PathInvalidException $e) {
            throw self::rejected('path', $e);
        }
    }

    private static function rejected(string $reason, ?\Throwable $cause = null): ApiException
    {
        return new ApiException(ErrorCode::Forbidden, 'Недействительный токен области доступа.', null, ['reason' => 'scope_token_' . $reason], $cause);
    }

    /** Ключ подписи — производный от ключа валидации cookie (есть у любого web-приложения Yii). */
    private static function key(): string
    {
        $master = (string)(Yii::$app->getRequest()->cookieValidationKey ?? '');
        if ($master === '') {
            throw new RuntimeException('ScopeToken: не задан cookieValidationKey — нечем подписывать токен области.');
        }
        return Yii::$app->getSecurity()->hkdf('sha256', $master, null, self::HKDF_INFO);
    }

    private static function base64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
