<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\naming;

/**
 * Транслитерация кириллицы (русский + украинский/белорусский буквы) в латиницу по таблице,
 * близкой к правилам загранпаспорта РФ (ICAO): 'ё'→'e', 'ж'→'zh', 'й'→'i', 'ц'→'ts', 'щ'→'shch',
 * 'ъ'/'ь' опускаются, 'ю'→'iu', 'я'→'ia'. Регистр сохраняется по первой букве («Щука» → «Shchuka»).
 *
 * Собственная таблица, а не ext-intl `Transliterator`: результат детерминирован и не зависит от
 * версии ICU на сервере (важно для S3-ключей и CDN-ссылок, которые нельзя «переименовать потом»).
 * Не-кириллические символы (латиница, цифры, диакритика других языков) не трогаются.
 */
final class CyrillicTransliterator implements TransliteratorInterface
{
    private const array TABLE = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e', 'ж' => 'zh',
        'з' => 'z', 'и' => 'i', 'й' => 'i', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o',
        'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'kh', 'ц' => 'ts',
        'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch', 'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'iu',
        'я' => 'ia',
        // украинский / белорусский
        'є' => 'ie', 'і' => 'i', 'ї' => 'i', 'ґ' => 'g', 'ў' => 'u',
    ];

    public function transliterate(string $name): string
    {
        $chars = mb_str_split($name, 1, 'UTF-8');
        $out = '';
        foreach ($chars as $char) {
            $lower = mb_strtolower($char, 'UTF-8');
            $replacement = self::TABLE[$lower] ?? null;
            if ($replacement === null) {
                $out .= $char;
                continue;
            }
            // Заглавная кириллическая буква → латиница с заглавной первой буквой.
            $out .= $char !== $lower && $replacement !== '' ? mb_strtoupper(mb_substr($replacement, 0, 1)) . mb_substr($replacement, 1) : $replacement;
        }
        return $out;
    }
}
