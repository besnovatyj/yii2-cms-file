<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\naming;

/**
 * Правила именования файлов и папок — единый источник для сервера и клиента.
 *
 * Сервер применяет их в {@see NameValidator} (отклонение) и {@see NameSanitizer} (исправление при
 * загрузке), клиент получает их из `describe.naming` и проверяет ввод «на лету» теми же правилами.
 * Поэтому набор полей зеркалит `NamingRules` контракта (docs/API-CONTRACT.md §6).
 *
 * Дефолты кроссплатформенные (Windows-ограничения строже POSIX): файл, созданный через менеджер,
 * должен без проблем скачиваться/синхронизироваться на любую ОС и в любое хранилище (S3-ключи,
 * ZIP-записи).
 */
final class NameRules
{
    /** Символы, запрещённые в имени (Windows + разделители путей). */
    public const array DEFAULT_FORBIDDEN_CHARS = ['/', '\\', ':', '*', '?', '"', '<', '>', '|'];

    /** Зарезервированные имена Windows (регистронезависимо, с расширением и без). */
    public const array DEFAULT_FORBIDDEN_NAMES = [
        'CON', 'PRN', 'AUX', 'NUL',
        'COM1', 'COM2', 'COM3', 'COM4', 'COM5', 'COM6', 'COM7', 'COM8', 'COM9',
        'LPT1', 'LPT2', 'LPT3', 'LPT4', 'LPT5', 'LPT6', 'LPT7', 'LPT8', 'LPT9',
    ];

    /**
     * @param int $maxLength Максимальная длина имени в БАЙТАХ UTF-8.
     * @param list<string> $forbiddenChars Запрещённые символы.
     * @param list<string> $forbiddenNames Зарезервированные имена (без учёта регистра и расширения).
     * @param bool $allowLeadingDot Разрешать скрытые файлы (`.env`).
     * @param bool $allowTrailingDotOrSpace Разрешать точку/пробел в конце (Windows их молча срезает).
     * @param string|null $unicodeNormalization 'NFC' — нормализовать при наличии ext-intl; null — не трогать.
     * @param bool $transliterate Транслитерировать кириллицу в латиницу при ЗАГРУЗКЕ (см. NameSanitizer).
     */
    public function __construct(
        public readonly int $maxLength = 255,
        public readonly array $forbiddenChars = self::DEFAULT_FORBIDDEN_CHARS,
        public readonly array $forbiddenNames = self::DEFAULT_FORBIDDEN_NAMES,
        public readonly bool $allowLeadingDot = false,
        public readonly bool $allowTrailingDotOrSpace = false,
        public readonly ?string $unicodeNormalization = 'NFC',
        public readonly bool $transliterate = false,
    ) {
    }

    /**
     * Сборка из массива настроек модуля (`params.fs.naming`); отсутствующие ключи — дефолты.
     *
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $defaults = new self();
        return new self(
            maxLength: isset($config['maxLength']) ? max(1, (int)$config['maxLength']) : $defaults->maxLength,
            forbiddenChars: isset($config['forbiddenChars']) ? array_values((array)$config['forbiddenChars']) : $defaults->forbiddenChars,
            forbiddenNames: isset($config['forbiddenNames']) ? array_values((array)$config['forbiddenNames']) : $defaults->forbiddenNames,
            allowLeadingDot: (bool)($config['allowLeadingDot'] ?? $defaults->allowLeadingDot),
            allowTrailingDotOrSpace: (bool)($config['allowTrailingDotOrSpace'] ?? $defaults->allowTrailingDotOrSpace),
            unicodeNormalization: array_key_exists('unicodeNormalization', $config)
                ? ($config['unicodeNormalization'] === null ? null : (string)$config['unicodeNormalization'])
                : $defaults->unicodeNormalization,
            transliterate: (bool)($config['transliterate'] ?? $defaults->transliterate),
        );
    }

    /**
     * Представление для `describe.naming` (без `blockedExtensions` — их добавляет политика,
     * см. {@see \Besnovatyj\File\fs\policy\ExtensionBlocklistRule::describe()}).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'maxLength' => $this->maxLength,
            'forbiddenChars' => $this->forbiddenChars,
            'forbiddenNames' => $this->forbiddenNames,
            'allowLeadingDot' => $this->allowLeadingDot,
            'allowTrailingDotOrSpace' => $this->allowTrailingDotOrSpace,
            'unicodeNormalization' => $this->unicodeNormalization,
            'transliterate' => $this->transliterate,
        ];
    }
}
