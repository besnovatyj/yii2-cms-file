<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\naming;

use Besnovatyj\File\fs\exception\NameInvalidException;

/**
 * Проверка имени файла/папки по {@see NameRules}. Ничего не исправляет — только отклоняет
 * (ADR-5: пользователь должен видеть, ЧТО не так, а не получать молча изменённое имя).
 *
 * Идентификаторы правил (`rule` в details исключения) совпадают с теми, что использует клиентский
 * валидатор `domain/naming/NameValidator.ts` — по ним UI показывает конкретную подсказку.
 */
final class NameValidator
{
    public function __construct(private readonly NameRules $rules)
    {
    }

    public function rules(): NameRules
    {
        return $this->rules;
    }

    /**
     * Нормализованное (NFC, если доступно) имя, прошедшее все правила.
     *
     * @throws NameInvalidException
     */
    public function validate(string $name): string
    {
        $name = $this->normalize($name);

        if ($name === '') {
            throw NameInvalidException::rule('empty', 'Имя не может быть пустым.', $name);
        }
        if ($name === '.' || $name === '..') {
            throw NameInvalidException::rule('dot_name', 'Имена "." и ".." зарезервированы.', $name);
        }
        if (!mb_check_encoding($name, 'UTF-8')) {
            throw NameInvalidException::rule('utf8', 'Имя должно быть в кодировке UTF-8.', $name);
        }
        if (strlen($name) > $this->rules->maxLength) {
            throw NameInvalidException::rule(
                'max_length',
                sprintf('Имя длиннее %d байт.', $this->rules->maxLength),
                $name,
            );
        }
        // Управляющие (Cc) и форматирующие (Cf: zero-width, RTL-override — подмена видимого
        // расширения "exe\u{202E}gpj.") символы — всегда запрещены, независимо от настроек.
        if (preg_match('/[\p{Cc}\p{Cf}]/u', $name) === 1) {
            throw NameInvalidException::rule('control_chars', 'Имя содержит управляющие или невидимые символы.', $name);
        }
        foreach ($this->rules->forbiddenChars as $char) {
            if ($char !== '' && str_contains($name, $char)) {
                throw NameInvalidException::rule(
                    'forbidden_char',
                    sprintf('Символ "%s" недопустим в имени.', $char),
                    $name,
                );
            }
        }
        if (!$this->rules->allowTrailingDotOrSpace && (str_ends_with($name, '.') || str_ends_with($name, ' '))) {
            throw NameInvalidException::rule('trailing_dot_or_space', 'Имя не может заканчиваться точкой или пробелом.', $name);
        }
        if (str_starts_with($name, ' ')) {
            throw NameInvalidException::rule('leading_space', 'Имя не может начинаться с пробела.', $name);
        }
        if (!$this->rules->allowLeadingDot && str_starts_with($name, '.')) {
            throw NameInvalidException::rule('leading_dot', 'Скрытые имена (начинающиеся с точки) запрещены.', $name);
        }
        if ($this->isReservedName($name)) {
            throw NameInvalidException::rule('reserved_name', 'Имя зарезервировано системой.', $name);
        }

        return $name;
    }

    /** Проверка без исключения — для оценки кандидатов в санитайзере. */
    public function isValid(string $name): bool
    {
        try {
            $this->validate($name);
            return true;
        } catch (NameInvalidException) {
            return false;
        }
    }

    /**
     * Unicode-нормализация NFC (если включена и есть ext-intl). Без неё одно и то же визуальное имя
     * могло бы существовать в двух байтовых представлениях (composed/decomposed).
     */
    public function normalize(string $name): string
    {
        if ($this->rules->unicodeNormalization === 'NFC' && class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($name, \Normalizer::FORM_C);
            if (is_string($normalized)) {
                return $normalized;
            }
        }
        return $name;
    }

    /** Зарезервированное имя Windows — с расширением ('con.txt') и без, без учёта регистра. */
    private function isReservedName(string $name): bool
    {
        $stem = strtoupper(NameParts::split($name)->stem);
        return in_array($stem, array_map(strtoupper(...), $this->rules->forbiddenNames), true);
    }
}
