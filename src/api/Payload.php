<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\api;

use Besnovatyj\File\fs\operation\UploadSource;
use Besnovatyj\File\fs\path\PathScope;
use Besnovatyj\File\fs\path\VirtualPath;

/**
 * Типизированное чтение входных параметров операции.
 *
 * Операции не лезут в `$_POST`/`Yii::$app->request` — они получают этот объект от адаптера
 * (контроллёра). Каждый метод либо возвращает значение нужного типа, либо бросает
 * `bad_request` с указанием поля: «неверный вход» всегда диагностируется ДО обращения к домену.
 *
 * Все пути проходят через {@see path()}/{@see pathList()}, где применяется область видимости
 * запроса ({@see PathScope}): путь вне области — `forbidden` до вызова домена. Поэтому операция не
 * может «забыть» проверку области.
 */
final class Payload
{
    /**
     * @param array<string, mixed> $params Параметры (JSON-тело, form-data или query).
     * @param array<string, UploadSource> $files Загруженные файлы по имени поля.
     * @param PathScope|null $scope Область видимости запроса; null — без ограничений.
     */
    public function __construct(
        private readonly array $params,
        private readonly array $files = [],
        private readonly ?PathScope $scope = null,
    ) {
    }

    public function scope(): PathScope
    {
        return $this->scope ?? PathScope::unrestricted();
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->params) && $this->params[$key] !== null;
    }

    public function string(string $key): string
    {
        $value = $this->optString($key);
        if ($value === null) {
            throw self::missing($key);
        }
        return $value;
    }

    public function optString(string $key): ?string
    {
        if (!$this->has($key)) {
            return null;
        }
        $value = $this->params[$key];
        if (!is_string($value)) {
            throw self::wrongType($key, 'string');
        }
        return $value;
    }

    /** Непустая строка. */
    public function nonEmptyString(string $key): string
    {
        $value = $this->string($key);
        if ($value === '') {
            throw ApiException::badRequest("Поле '{$key}' не может быть пустым.", ['field' => $key]);
        }
        return $value;
    }

    public function optInt(string $key): ?int
    {
        if (!$this->has($key)) {
            return null;
        }
        $value = $this->params[$key];
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d{1,18}$/', $value) === 1) {
            return (int)$value;
        }
        throw self::wrongType($key, 'integer');
    }

    public function optBool(string $key): ?bool
    {
        if (!$this->has($key)) {
            return null;
        }
        $value = $this->params[$key];
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === '1' || $value === 'true') {
            return true;
        }
        if ($value === 0 || $value === '0' || $value === 'false') {
            return false;
        }
        throw self::wrongType($key, 'boolean');
    }

    /**
     * Список строк ограниченной длины (пакетные операции).
     *
     * @return list<string>
     */
    public function stringList(string $key, int $max): array
    {
        if (!$this->has($key)) {
            throw self::missing($key);
        }
        $value = $this->params[$key];
        if (!is_array($value) || array_is_list($value) === false) {
            throw self::wrongType($key, 'array of strings');
        }
        if ($value === []) {
            throw ApiException::badRequest("Список '{$key}' пуст.", ['field' => $key]);
        }
        if (count($value) > $max) {
            throw new ApiException(
                ErrorCode::TooLarge,
                sprintf("Список '%s' содержит %d элементов (лимит %d).", $key, count($value), $max),
                null,
                ['field' => $key, 'count' => count($value), 'limit' => $max],
            );
        }
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw self::wrongType($key, 'array of strings');
            }
        }
        return array_values($value);
    }

    /**
     * @return list<string>|null
     */
    public function optStringList(string $key, int $max = 100): ?array
    {
        return $this->has($key) ? $this->stringList($key, $max) : null;
    }

    /**
     * Вложенный объект как Payload (например, `sort`, `filter`).
     */
    public function optObject(string $key): ?self
    {
        if (!$this->has($key)) {
            return null;
        }
        $value = $this->params[$key];
        if (!is_array($value) || array_is_list($value) && $value !== []) {
            throw self::wrongType($key, 'object');
        }
        return new self($value);
    }

    /**
     * Виртуальный путь: строка обязательна; корректность проверяет {@see VirtualPath::parse()},
     * принадлежность области — {@see PathScope::assertContains()}.
     */
    public function path(string $key): VirtualPath
    {
        $path = VirtualPath::parse($this->nonEmptyString($key));
        $this->scope?->assertContains($path);
        return $path;
    }

    /**
     * Список виртуальных путей.
     *
     * @return list<VirtualPath>
     */
    public function pathList(string $key, int $max): array
    {
        return array_map(function (string $raw): VirtualPath {
            $path = VirtualPath::parse($raw);
            $this->scope?->assertContains($path);
            return $path;
        }, $this->stringList($key, $max));
    }

    public function file(string $key): UploadSource
    {
        return $this->files[$key] ?? throw ApiException::badRequest("Отсутствует файл в поле '{$key}'.", ['field' => $key]);
    }

    private static function missing(string $key): ApiException
    {
        return ApiException::badRequest("Отсутствует обязательное поле '{$key}'.", ['field' => $key]);
    }

    private static function wrongType(string $key, string $expected): ApiException
    {
        return ApiException::badRequest("Поле '{$key}' должно иметь тип {$expected}.", ['field' => $key, 'expected' => $expected]);
    }
}
