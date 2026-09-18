<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\api;

/**
 * Идентификация реализуемого контракта и сервера (контракт bescms-fs §6, §11).
 *
 * `VERSION` — `major.minor`: minor растёт при аддитивных изменениях (новые опциональные поля,
 * новые операции), major — при несовместимых. Меняется ТОЛЬКО вместе с docs/API-CONTRACT.md
 * пакета filemanager-core2 и его TS-типами.
 */
final class Contract
{
    public const string NAME = 'bescms-fs';
    public const string VERSION = '1.0';

    public const string SERVER_NAME = 'yii2-cms-file';
    public const string SERVER_VERSION = '2.0.0';

    private function __construct()
    {
    }
}
