<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\commands;

use Besnovatyj\File\fs\thumbnail\ThumbnailCache;
use Besnovatyj\File\fs\thumbnail\ThumbnailConfig;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Обслуживание кэша миниатюр.
 *
 * `php yii File/thumbnail/purge` — удалить миниатюры старше `params.fs.thumbnails.ttl`
 * (сироты после переименований/удалений и просто давно не запрашивавшиеся). Раз в сутки по cron.
 */
final class ThumbnailController extends Controller
{
    public function __construct(
        $id,
        $module,
        private readonly ThumbnailCache $cache,
        private readonly ThumbnailConfig $thumbnails,
        $config = [],
    ) {
        parent::__construct($id, $module, $config);
    }

    public function actionPurge(): int
    {
        $count = $this->cache->purge($this->thumbnails->ttl);
        $this->stdout("Удалено миниатюр: {$count}\n");
        return ExitCode::OK;
    }
}
