<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\policy;

use Besnovatyj\File\fs\exception\TooLargeException;
use Besnovatyj\File\fs\operation\FsOperation;

/**
 * Лимит размера загружаемого файла.
 *
 * Эффективный лимит = min(настроенный, `upload_max_filesize`, `post_max_size`). ini-лимиты PHP
 * срабатывают раньше нашего кода; правило дублирует их осознанно — чтобы пользователь получил
 * внятный код `too_large` вместо «файл не пришёл», а фронтенд знал лимит заранее из `describe`.
 */
final class MaxFileSizeRule implements PolicyRuleInterface
{
    public const string ID = 'max_file_size';

    /**
     * @param int|null $configuredLimit Лимит в БАЙТАХ; null — только ini-лимиты.
     */
    public function __construct(private readonly ?int $configuredLimit = null)
    {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function check(PolicyContext $context): void
    {
        if ($context->operation !== FsOperation::Upload || $context->upload === null) {
            return;
        }
        $limit = $this->effectiveLimit();
        if ($limit !== null && $context->upload->size > $limit) {
            throw new TooLargeException(
                sprintf('Файл "%s" (%d байт) превышает лимит %d байт.', $context->targetName ?? $context->upload->clientName, $context->upload->size, $limit),
                $context->targetPath,
                ['rule' => self::ID, 'size' => $context->upload->size, 'limit' => $limit],
            );
        }
    }

    public function describe(): array
    {
        return ['upload' => ['maxFileSize' => $this->effectiveLimit()]];
    }

    /** Действующий лимит в байтах; null — лимита нет. */
    public function effectiveLimit(): ?int
    {
        $limits = array_filter([
            $this->configuredLimit,
            self::iniToBytes((string)ini_get('upload_max_filesize')),
            self::iniToBytes((string)ini_get('post_max_size')),
        ], static fn(?int $v): bool => $v !== null && $v > 0);

        return $limits === [] ? null : min($limits);
    }

    /** '2M', '512K', '1G', '100' → байты; null — пусто/0/-1 («без лимита» в семантике ini). */
    public static function iniToBytes(string $value): ?int
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
