<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\naming;

/**
 * Приведение недоверенного имени (из ОС пользователя при загрузке) к имени, проходящему
 * {@see NameValidator}. Используется ТОЛЬКО для `upload` (ADR-5): пользователь не набирал это имя
 * руками, поэтому исправление уместно; результат возвращается клиенту вместе с флагом `renamed`.
 *
 * Опционально транслитерирует кириллицу ({@see TransliteratorInterface}, `params.fs.naming.transliterate`).
 *
 * Расширение при санитизации не «чинится»: заблокированное расширение отклоняет политика, а не
 * переименовывает (иначе `shell.php` тихо стал бы `shell_php` и пользователь не понял бы, что произошло).
 */
final class NameSanitizer
{
    /** Имя-заглушка, если после очистки ничего не осталось. */
    public const string FALLBACK_STEM = 'file';

    /**
     * @param TransliteratorInterface|null $transliterator Используется, если включено `NameRules::$transliterate`.
     */
    public function __construct(
        private readonly NameValidator $validator,
        private readonly ?TransliteratorInterface $transliterator = null,
    ) {
    }

    /**
     * Безопасное имя, гарантированно проходящее валидатор.
     */
    public function sanitize(string $name): string
    {
        $rules = $this->validator->rules();
        $name = $this->validator->normalize($name);

        // Имя от браузера может содержать путь (старые IE, некоторые мобильные клиенты) — берём хвост.
        $name = (string)preg_replace('#^.*[/\\\\]#', '', $name);

        // Транслитерация — до замены запрещённых символов и обрезки по длине (латиница короче в байтах).
        if ($rules->transliterate && $this->transliterator !== null) {
            $name = $this->transliterator->transliterate($name);
        }

        // Управляющие/невидимые символы и запрещённые символы → '_'.
        $name = (string)preg_replace('/[\p{Cc}\p{Cf}]/u', '_', $name);
        foreach ($rules->forbiddenChars as $char) {
            if ($char !== '') {
                $name = str_replace($char, '_', $name);
            }
        }

        $name = trim($name);
        if (!$rules->allowTrailingDotOrSpace) {
            $name = rtrim($name, '. ');
        }
        if (!$rules->allowLeadingDot) {
            $name = ltrim($name, '.');
        }
        $name = trim($name);

        if ($name === '' || $name === '.' || $name === '..') {
            $name = self::FALLBACK_STEM;
        }

        $parts = NameParts::split($name);
        $stem = $parts->stem;
        $ext = $parts->extension;

        if ($this->isReserved($stem)) {
            $stem = '_' . $stem;
        }

        $name = $this->truncate($stem, $ext, $rules->maxLength);

        // Последний рубеж: если какой-то экзотический случай не покрыт выше — не рискуем,
        // отдаём предсказуемое безопасное имя, сохранив расширение.
        if (!$this->validator->isValid($name)) {
            $name = $this->truncate(self::FALLBACK_STEM . '_' . bin2hex(random_bytes(4)), $ext, $rules->maxLength);
        }

        return $name;
    }

    private function isReserved(string $stem): bool
    {
        $upper = strtoupper($stem);
        foreach ($this->validator->rules()->forbiddenNames as $reserved) {
            if (strtoupper($reserved) === $upper) {
                return true;
            }
        }
        return false;
    }

    /** Обрезает основу по границе UTF-8-символов так, чтобы имя с расширением влезло в лимит байт. */
    private function truncate(string $stem, ?string $ext, int $maxBytes): string
    {
        $suffix = $ext === null ? '' : '.' . $ext;
        $budget = $maxBytes - strlen($suffix);
        if ($budget < 1) {
            // Расширение само длиннее лимита — экзотика; оставляем только обрезанную основу.
            return mb_strcut($stem, 0, $maxBytes, 'UTF-8');
        }
        if (strlen($stem) > $budget) {
            $stem = rtrim(mb_strcut($stem, 0, $budget, 'UTF-8'), '. ');
            if ($stem === '') {
                $stem = self::FALLBACK_STEM;
            }
        }
        return $stem . $suffix;
    }
}
