<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\upload\rules;

use Besnovatyj\File\storage\StorageMount;
use Besnovatyj\File\upload\FileNameRuleInterface;
use Besnovatyj\File\upload\UploadRejectedException;
use Besnovatyj\File\upload\UploadRuleInterface;
use yii\web\UploadedFile;

/**
 * Блок-лист опасных (исполняемых на сервере) расширений.
 *
 * Проверяются ВСЕ dot-сегменты имени, а не только последний: `shell.php.jpg` отклоняется —
 * классический вектор исполнения через Apache AddHandler/mod_mime, где обрабатывается каждое
 * расширение в имени. Это защита от RCE при загрузке на домен, способный исполнять PHP;
 * хранимый XSS через .svg/.html сюда намеренно не входит (см. решение по контентной валидации).
 *
 * Реализует и {@see FileNameRuleInterface}: то же ограничение применяется к rename — иначе
 * блок-лист обходится переименованием уже загруженного файла.
 */
final class ExtensionBlocklistRule implements UploadRuleInterface, FileNameRuleInterface
{
    /**
     * Дефолтный блок-лист: серверные исполняемые форматы и служебные файлы Apache.
     * Переопределяется параметром модуля `upload.blockedExtensions` (см. config/container.php).
     */
    public const array DEFAULT_BLOCKED = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht', 'pgif',
        'shtml', 'cgi', 'pl', 'py', 'asp', 'aspx', 'jsp',
        'htaccess', 'htpasswd',
    ];

    /** @var string[] нормализованный (lowercase) блок-лист */
    private readonly array $blocked;

    /**
     * @param string[] $blocked Расширения без точки, регистронезависимо.
     */
    public function __construct(array $blocked = self::DEFAULT_BLOCKED)
    {
        $this->blocked = array_map(strtolower(...), $blocked);
    }

    public function validate(UploadedFile $file, string $targetName, StorageMount $mount): void
    {
        $this->validateName($targetName, $mount);
    }

    public function validateName(string $name, StorageMount $mount): void
    {
        // Все сегменты после первой точки: 'shell.php.jpg' → ['php', 'jpg'].
        $segments = explode('.', strtolower($name));
        array_shift($segments); // basename — не расширение

        foreach ($segments as $segment) {
            if (in_array($segment, $this->blocked, true)) {
                throw new UploadRejectedException(
                    sprintf('Имя "%s" отклонено: расширение ".%s" запрещено политикой безопасности.', $name, $segment)
                );
            }
        }
    }

    public function capabilities(): array
    {
        return ['blockedExtensions' => $this->blocked];
    }
}
