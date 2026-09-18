<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\policy;

use Besnovatyj\File\fs\exception\PolicyRejectedException;
use Besnovatyj\File\fs\naming\NameParts;
use Besnovatyj\File\fs\operation\FsOperation;
use League\MimeTypeDetection\ExtensionMimeTypeDetector;
use League\MimeTypeDetection\FinfoMimeTypeDetector;

/**
 * Проверка содержимого загружаемого файла по «магическим байтам» (libmagic через finfo).
 *
 * Два уровня:
 *  - всегда: содержимое, распознанное как серверный скрипт/исполняемый файл, отклоняется, каким бы
 *    ни было расширение (`avatar.jpg` с `<?php` внутри);
 *  - `strict`: верхнеуровневый тип по содержимому должен совпадать с типом по расширению
 *    (`image/*` ≠ `text/*`) — ловит подмену расширения, но даёт ложные отказы у файлов с битыми
 *    заголовками, поэтому по умолчанию выключен (ADR-10).
 *
 * Правило по умолчанию НЕ зарегистрировано; включается настройкой `params.fs.policy.contentSniff`.
 */
final class ContentSniffRule implements PolicyRuleInterface
{
    public const string ID = 'content_sniff';

    /** MIME по содержимому, которые нельзя класть в хранилище ни под каким именем. */
    public const array DANGEROUS_MIMES = [
        'text/x-php', 'application/x-php', 'application/x-httpd-php', 'application/x-httpd-php-source',
        'application/x-executable', 'application/x-sharedlib', 'application/x-mach-binary',
        'application/x-dosexec', 'application/x-msdownload', 'application/x-elf',
        'text/x-shellscript', 'text/x-perl', 'text/x-python',
    ];

    private readonly FinfoMimeTypeDetector $byContent;
    private readonly ExtensionMimeTypeDetector $byExtension;

    public function __construct(
        private readonly bool $strict = false,
        ?FinfoMimeTypeDetector $byContent = null,
        ?ExtensionMimeTypeDetector $byExtension = null,
    ) {
        $this->byContent = $byContent ?? new FinfoMimeTypeDetector();
        $this->byExtension = $byExtension ?? new ExtensionMimeTypeDetector();
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

        $actual = $this->byContent->detectMimeTypeFromFile($context->upload->tmpPath);
        if ($actual === null) {
            return; // содержимое не распознано — не повод отказывать (пустые/экзотические файлы)
        }
        $actual = strtolower($actual);

        if (in_array($actual, self::DANGEROUS_MIMES, true)) {
            throw PolicyRejectedException::byRule(
                self::ID,
                'Содержимое файла распознано как исполняемый код и отклонено.',
                $context->targetPath,
                ['detected' => $actual],
            );
        }

        if (!$this->strict || $context->targetName === null) {
            return;
        }

        $extension = NameParts::split($context->targetName)->extension;
        $expected = $extension === null ? null : $this->byExtension->detectMimeTypeFromPath('x.' . $extension);
        if ($expected === null || $actual === 'application/octet-stream') {
            return; // расширение неизвестно или содержимое «просто байты» — сравнивать не с чем
        }
        if (self::topLevel($expected) !== self::topLevel($actual)) {
            throw PolicyRejectedException::byRule(
                self::ID,
                sprintf('Содержимое файла (%s) не соответствует расширению ".%s".', $actual, $extension),
                $context->targetPath,
                ['detected' => $actual, 'expected' => $expected],
            );
        }
    }

    public function describe(): array
    {
        return []; // ограничение по содержимому клиент заранее проверить не может
    }

    private static function topLevel(string $mime): string
    {
        return strtolower((string)strtok($mime, '/'));
    }
}
