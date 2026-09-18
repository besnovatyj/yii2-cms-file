<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\path;

use Besnovatyj\File\fs\exception\PathInvalidException;

/**
 * Виртуальный путь — адрес узла в единой файловой системе, которую видит фронтенд.
 *
 * Формат (контракт bescms-fs §4):
 *   '/'                      — виртуальный корень (перечень точек монтирования);
 *   '/{mountId}'             — корень точки монтирования;
 *   '/{mountId}/a/b.txt'     — узел внутри точки монтирования.
 *
 * Класс — неизменяемый value object и ПЕРВЫЙ рубеж защиты от обхода каталога: {@see parse()}
 * принимает только канонический вид и отклоняет `..`, `.`, `\`, NUL и управляющие символы,
 * пустые сегменты, слишком длинные сегменты/пути. Ничего не «чинится» молча — некорректный путь
 * это ошибка клиента (PathInvalidException → HTTP 400). Второй рубеж — PathNormalizer самого
 * Flysystem (бросает PathTraversalDetected).
 *
 * Реальные корни хранилищ (каталог на диске, бакет, архив) в этом классе не фигурируют:
 * {@see relative()} — путь относительно корня точки монтирования, его и получает Flysystem.
 */
final class VirtualPath
{
    /** Шаблон идентификатора точки монтирования (контракт §4). */
    public const string MOUNT_ID_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,31}$/';

    /** Максимальная длина одного сегмента в БАЙТАХ (ограничение большинства ФС). */
    public const int MAX_SEGMENT_BYTES = 255;

    /** Максимальная длина всего пути в БАЙТАХ. */
    public const int MAX_PATH_BYTES = 4096;

    /**
     * @param string|null $mount Идентификатор точки монтирования; null — только у виртуального корня.
     * @param list<string> $segments Сегменты ВНУТРИ точки монтирования (без её id).
     */
    private function __construct(
        public readonly ?string $mount,
        public readonly array $segments,
    ) {
    }

    // ------------------------------------------------------------------ фабрики

    /** Виртуальный корень '/'. */
    public static function root(): self
    {
        return new self(null, []);
    }

    /** Корень точки монтирования '/{mountId}'. */
    public static function mountRoot(string $mountId): self
    {
        self::assertMountId($mountId);
        return new self($mountId, []);
    }

    /**
     * Строгий разбор канонического пути от клиента.
     *
     * @throws PathInvalidException путь не канонический или содержит запрещённые элементы.
     */
    public static function parse(string $raw): self
    {
        if (strlen($raw) > self::MAX_PATH_BYTES) {
            throw new PathInvalidException('Путь слишком длинный.', null, ['rule' => 'max_path_length']);
        }
        if ($raw === '' || $raw[0] !== '/') {
            throw new PathInvalidException('Путь должен начинаться с "/".', $raw, ['rule' => 'absolute']);
        }
        if ($raw === '/') {
            return self::root();
        }
        if (str_ends_with($raw, '/')) {
            throw new PathInvalidException('Путь не должен заканчиваться на "/".', $raw, ['rule' => 'trailing_slash']);
        }

        $parts = explode('/', substr($raw, 1));
        $mountId = array_shift($parts);
        self::assertMountId((string)$mountId, $raw);

        foreach ($parts as $segment) {
            self::assertSegment($segment, $raw);
        }

        return new self($mountId, array_values($parts));
    }

    /**
     * Мягкий разбор для внутреннего использования (пути из листингов Flysystem, конфигурации).
     * В отличие от {@see parse()} допускает и нормализует лишние/завершающие слеши, но сегменты
     * проверяет так же строго — из недоверенного источника этот метод вызывать нельзя.
     */
    public static function fromMountAndRelative(string $mountId, string $relative): self
    {
        self::assertMountId($mountId);
        $segments = [];
        foreach (explode('/', trim($relative, '/')) as $segment) {
            if ($segment === '') {
                continue;
            }
            self::assertSegment($segment, '/' . $mountId . '/' . $relative);
            $segments[] = $segment;
        }
        return new self($mountId, $segments);
    }

    // ------------------------------------------------------------------ свойства

    /** Виртуальный корень '/'? */
    public function isRoot(): bool
    {
        return $this->mount === null;
    }

    /** Корень точки монтирования '/{mountId}'? */
    public function isMountRoot(): bool
    {
        return $this->mount !== null && $this->segments === [];
    }

    /**
     * Путь ОТНОСИТЕЛЬНО корня точки монтирования в форме, ожидаемой Flysystem:
     * без ведущего слеша, '' — корень точки монтирования.
     */
    public function relative(): string
    {
        return implode('/', $this->segments);
    }

    /** Последний сегмент; для корня точки монтирования — её id; для '/' — ''. */
    public function name(): string
    {
        if ($this->mount === null) {
            return '';
        }
        return $this->segments === [] ? $this->mount : $this->segments[count($this->segments) - 1];
    }

    /** Родительский путь; null — у виртуального корня. Родитель корня точки монтирования — '/'. */
    public function parent(): ?self
    {
        if ($this->mount === null) {
            return null;
        }
        if ($this->segments === []) {
            return self::root();
        }
        return new self($this->mount, array_slice($this->segments, 0, -1));
    }

    /**
     * Дочерний путь с проверкой сегмента. Для виртуального корня дочерний элемент — точка
     * монтирования, поэтому $name должен быть корректным mountId.
     *
     * @throws PathInvalidException
     */
    public function child(string $name): self
    {
        if ($this->mount === null) {
            return self::mountRoot($name);
        }
        self::assertSegment($name, $this->toString() . '/' . $name);
        return new self($this->mount, [...$this->segments, $name]);
    }

    /** Глубина: '/' → 0, '/{mount}' → 1, '/{mount}/a' → 2. */
    public function depth(): int
    {
        return $this->mount === null ? 0 : 1 + count($this->segments);
    }

    /** Является ли этот путь строгим предком $other (сам себе предком не считается). */
    public function isAncestorOf(self $other): bool
    {
        if ($this->mount === null) {
            return $other->mount !== null;
        }
        if ($this->mount !== $other->mount || count($this->segments) >= count($other->segments)) {
            return false;
        }
        foreach ($this->segments as $i => $segment) {
            if ($other->segments[$i] !== $segment) {
                return false;
            }
        }
        return true;
    }

    public function equals(self $other): bool
    {
        return $this->mount === $other->mount && $this->segments === $other->segments;
    }

    /** Канонический строковый вид (контракт §4). */
    public function toString(): string
    {
        if ($this->mount === null) {
            return '/';
        }
        return '/' . $this->mount . ($this->segments === [] ? '' : '/' . implode('/', $this->segments));
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    // ------------------------------------------------------------------ валидация

    /** @throws PathInvalidException */
    private static function assertMountId(string $mountId, ?string $raw = null): void
    {
        if (preg_match(self::MOUNT_ID_PATTERN, $mountId) !== 1) {
            throw new PathInvalidException(
                'Некорректный идентификатор точки монтирования.',
                $raw,
                ['rule' => 'mount_id', 'mount' => $mountId],
            );
        }
    }

    /**
     * Проверка одного сегмента пути. Единая для {@see parse()}, {@see child()} и внутреннего
     * разбора — поэтому невозможно собрать «плохой» путь никаким способом.
     *
     * @throws PathInvalidException
     */
    public static function assertSegment(string $segment, ?string $raw = null): void
    {
        if ($segment === '') {
            throw new PathInvalidException('Пустой сегмент пути.', $raw, ['rule' => 'empty_segment']);
        }
        if ($segment === '.' || $segment === '..') {
            throw new PathInvalidException('Сегменты "." и ".." запрещены.', $raw, ['rule' => 'dot_segment']);
        }
        if (str_contains($segment, '/') || str_contains($segment, '\\')) {
            throw new PathInvalidException('Разделители пути внутри сегмента запрещены.', $raw, ['rule' => 'separator']);
        }
        // Управляющие символы (0x00–0x1F, 0x7F) — включая NUL: классический способ обрезать путь
        // в C-функциях и обмануть проверку расширения.
        if (preg_match('/[\x00-\x1F\x7F]/', $segment) === 1) {
            throw new PathInvalidException('Управляющие символы в пути запрещены.', $raw, ['rule' => 'control_chars']);
        }
        if (strlen($segment) > self::MAX_SEGMENT_BYTES) {
            throw new PathInvalidException('Сегмент пути слишком длинный.', $raw, ['rule' => 'max_segment_length']);
        }
        if (!mb_check_encoding($segment, 'UTF-8')) {
            throw new PathInvalidException('Путь должен быть в кодировке UTF-8.', $raw, ['rule' => 'utf8']);
        }
    }
}
