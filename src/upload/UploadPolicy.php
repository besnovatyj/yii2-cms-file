<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\upload;

use Besnovatyj\File\storage\StorageMount;
use yii\web\UploadedFile;

/**
 * Серверная политика загрузки файлов — конвейер правил {@see UploadRuleInterface}.
 *
 * Состав правил собирается в config/container.php (DI): добавление новой валидации —
 * ещё один объект-правило в списке, без правок сервиса/контроллёров/контракта.
 * Сегодня включены: блок-лист исполняемых расширений, лимит размера. Контентная/MIME-валидация
 * намеренно НЕ включена (много легитимных изображений с битой MIME-типизацией ложно
 * отвергаются) — когда понадобится, это отдельное правило в этом же конвейере.
 *
 * Та же политика обязана применяться всеми каналами загрузки (file-manager upload,
 * будущий SUA-коннектор CKEditor).
 */
final class UploadPolicy
{
    /** @var UploadRuleInterface[] */
    private readonly array $rules;

    /**
     * @param UploadRuleInterface[] $rules Правила в порядке применения.
     */
    public function __construct(array $rules = [])
    {
        $this->rules = $rules;
    }

    /**
     * Прогоняет файл через все правила; первое нарушение — {@see UploadRejectedException} (422).
     *
     * @param string $targetName Фактическое имя записи (после санитизации).
     * @throws UploadRejectedException
     */
    public function validate(UploadedFile $file, string $targetName, StorageMount $mount): void
    {
        foreach ($this->rules as $rule) {
            $rule->validate($file, $targetName, $mount);
        }
    }

    /**
     * Проверка имени вне контекста загрузки (rename): применяются только правила,
     * реализующие {@see FileNameRuleInterface} — иначе ограничение по имени (блок-лист
     * расширений) обходилось бы переименованием уже загруженного файла.
     *
     * @param string $name Фактическое имя (после санитизации).
     * @throws UploadRejectedException
     */
    public function validateName(string $name, StorageMount $mount): void
    {
        foreach ($this->rules as $rule) {
            if ($rule instanceof FileNameRuleInterface) {
                $rule->validateName($name, $mount);
            }
        }
    }

    /**
     * Сводный вклад всех правил в `BackendCapabilities.upload` (UX-подсказки фронтенду,
     * контракт GetConfigResponse). Поздние правила перекрывают одноимённые ключи ранних.
     */
    public function capabilities(): array
    {
        $capabilities = [];
        foreach ($this->rules as $rule) {
            $capabilities = array_merge($capabilities, $rule->capabilities());
        }
        return $capabilities;
    }
}
