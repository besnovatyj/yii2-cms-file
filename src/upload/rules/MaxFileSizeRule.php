<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\upload\rules;

use Besnovatyj\File\storage\StorageMount;
use Besnovatyj\File\upload\UploadRejectedException;
use Besnovatyj\File\upload\UploadRuleInterface;
use yii\web\UploadedFile;

/**
 * Лимит размера загружаемого файла.
 *
 * Эффективный лимит = минимум из настроенного (параметр модуля `upload.maxFileSize`, байты)
 * и ini-лимитов PHP (upload_max_filesize / post_max_size). ini-лимиты PHP применяет и сам —
 * до нашего кода; правило дублирует их осознанно: даёт пользователю внятное 422-сообщение
 * вместо невнятного «файл не пришёл» и сообщает действующий лимит фронтенду через capabilities.
 */
final class MaxFileSizeRule implements UploadRuleInterface
{
    /**
     * @param int|null $configuredLimit Лимит в БАЙТАХ из настроек модуля; null — только ini-лимиты.
     */
    public function __construct(private readonly ?int $configuredLimit = null)
    {
    }

    public function validate(UploadedFile $file, string $targetName, StorageMount $mount): void
    {
        $limit = $this->effectiveLimit();
        if ($limit !== null && $file->size > $limit) {
            throw new UploadRejectedException(sprintf(
                'Загрузка отклонена: файл "%s" (%d байт) превышает лимит %d байт.',
                $targetName,
                $file->size,
                $limit
            ));
        }
    }

    public function capabilities(): array
    {
        // Контракт BackendCapabilities.upload.maxFileSize: БАЙТЫ, null = лимита нет.
        return ['maxFileSize' => $this->effectiveLimit()];
    }

    /** Действующий лимит в байтах: min(настроенный, ini-лимиты PHP); null — лимита нет. */
    public function effectiveLimit(): ?int
    {
        $limits = array_filter([
            $this->configuredLimit,
            self::iniToBytes((string)ini_get('upload_max_filesize')),
            self::iniToBytes((string)ini_get('post_max_size')),
        ]);

        return $limits === [] ? null : min($limits);
    }

    /**
     * Перевод ini-нотации размера ('2M', '512K', '1G', '100') в байты.
     * null — значение пустое или 0/-1 (в семантике ini «без лимита»).
     */
    private static function iniToBytes(string $value): ?int
    {
        $value = trim($value);
        if ($value === '' || $value === '0' || $value === '-1') {
            return null;
        }

        $bytes = (float)$value;
        $bytes *= match (strtolower(substr($value, -1))) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        };

        return $bytes > 0 ? (int)$bytes : null;
    }
}
