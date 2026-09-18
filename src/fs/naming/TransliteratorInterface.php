<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\naming;

/**
 * Транслитерация имени файла при загрузке (опционально, `params.fs.naming.transliterate`).
 *
 * Отдельный интерфейс, а не метод санитайзера: правила транслитерации — предмет вкуса
 * (ГОСТ / BGN / «как у Яндекса»), их удобно подменять в DI, не трогая санитайзер.
 * Применяется ТОЛЬКО в {@see NameSanitizer} (upload); при mkdir/rename имя пользователь
 * набирает сам и получает его без изменений (ADR-5).
 */
interface TransliteratorInterface
{
    /** Возвращает имя латиницей; символы, не имеющие соответствия, оставляет как есть. */
    public function transliterate(string $name): string;
}
