<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\policy;

use Besnovatyj\File\fs\exception\PolicyRejectedException;
use Besnovatyj\File\fs\naming\NameParts;

/**
 * Блок-лист исполняемых на сервере расширений.
 *
 * Проверяются ВСЕ dot-сегменты имени (`shell.php.jpg` → `php`, `jpg`): Apache с AddHandler/mod_mime
 * обрабатывает каждое расширение в имени. Правило применяется к любой операции, задающей имя
 * ({@see \Besnovatyj\File\fs\operation\FsOperation::assignsName()}), — иначе блок-лист обходился бы
 * переименованием или копированием с новым именем.
 *
 * Хранимый XSS через `.svg`/`.html` сюда намеренно не входит: такие файлы легитимны для сайта,
 * а защита — в способе отдачи (attachment + nosniff с домена админки, отдельный статик-домен).
 */
final class ExtensionBlocklistRule implements PolicyRuleInterface
{
    public const string ID = 'extension_blocklist';

    public const array DEFAULT_BLOCKED = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht', 'pgif', 'phps',
        'shtml', 'shtm', 'stm', 'cgi', 'pl', 'py', 'rb', 'asp', 'aspx', 'asa', 'jsp', 'jspx',
        'htaccess', 'htpasswd', 'ini', 'sh', 'bash',
    ];

    /** @var list<string> lowercase */
    private readonly array $blocked;

    /**
     * @param list<string> $blocked Расширения без точки; регистр не важен.
     */
    public function __construct(array $blocked = self::DEFAULT_BLOCKED)
    {
        $this->blocked = array_values(array_unique(array_map(strtolower(...), $blocked)));
    }

    public function id(): string
    {
        return self::ID;
    }

    public function check(PolicyContext $context): void
    {
        if (!$context->operation->assignsName() || $context->targetName === null) {
            return;
        }
        foreach (NameParts::allExtensions($context->targetName) as $extension) {
            if (in_array($extension, $this->blocked, true)) {
                throw PolicyRejectedException::byRule(
                    self::ID,
                    sprintf('Имя "%s" отклонено: расширение ".%s" запрещено политикой безопасности.', $context->targetName, $extension),
                    $context->targetPath,
                    ['extension' => $extension],
                );
            }
        }
    }

    public function describe(): array
    {
        return ['naming' => ['blockedExtensions' => $this->blocked]];
    }
}
