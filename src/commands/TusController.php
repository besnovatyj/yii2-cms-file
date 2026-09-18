<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\commands;

use Besnovatyj\File\fs\tus\TusServer;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Обслуживание докачиваемых загрузок (tus).
 *
 * `php yii File/tus/purge` — удалить просроченные незавершённые загрузки (срок — `params.fs.tus.ttl`).
 * Ставится в cron рядом с остальными задачами обслуживания (раз в час достаточно).
 */
final class TusController extends Controller
{
    public function __construct($id, $module, private readonly TusServer $tus, $config = [])
    {
        parent::__construct($id, $module, $config);
    }

    public function actionPurge(): int
    {
        $count = $this->tus->purgeExpired();
        $this->stdout("Удалено просроченных загрузок: {$count}\n");
        return ExitCode::OK;
    }
}
