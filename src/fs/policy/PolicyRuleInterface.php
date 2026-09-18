<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\policy;

use Besnovatyj\File\fs\exception\PolicyRejectedException;
use Besnovatyj\File\fs\exception\TooLargeException;

/**
 * Одно правило серверной политики операций.
 *
 * Точка расширения безопасности: антивирус, проверка размеров изображений, санитизация SVG,
 * квоты на mount, запрет определённых имён — всё это новые классы-правила, регистрируемые в
 * {@see OperationPolicy} через DI. Сервисы операций, API и контракт при этом не меняются.
 */
interface PolicyRuleInterface
{
    /** Стабильный идентификатор правила — попадает в `details.rule` при отклонении. */
    public function id(): string;

    /**
     * Проверяет операцию; при нарушении бросает исключение (наружу — код `policy_rejected`
     * или `too_large`). Неприменимые к операции правила просто возвращают управление.
     *
     * @throws PolicyRejectedException
     * @throws TooLargeException
     */
    public function check(PolicyContext $context): void;

    /**
     * Вклад правила в `describe`: массив секций (`['naming' => [...], 'upload' => [...]]`),
     * чтобы фронтенд мог проверять ограничение до отправки. Пустой массив — правило ничего
     * не сообщает. Enforcement остаётся в {@see check()}.
     *
     * @return array<string, array<string, mixed>>
     */
    public function describe(): array;
}
