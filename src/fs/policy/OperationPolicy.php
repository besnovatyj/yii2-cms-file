<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\fs\policy;

/**
 * Конвейер правил операций: прогоняет контекст через все правила по порядку; первое нарушение
 * останавливает операцию. Состав правил задаётся в DI (config/container.php).
 *
 * Одна политика применяется ко ВСЕМ каналам мутаций (mkdir, rename, move/copy — целевое имя,
 * upload — имя и содержимое), поэтому обойти ограничение «через другую операцию» нельзя
 * (классическая дыра: загрузить `x.jpg`, переименовать в `x.php`).
 */
final class OperationPolicy
{
    /** @var list<PolicyRuleInterface> */
    private readonly array $rules;

    /**
     * @param iterable<PolicyRuleInterface> $rules
     */
    public function __construct(iterable $rules = [])
    {
        $list = [];
        foreach ($rules as $rule) {
            $list[] = $rule;
        }
        $this->rules = $list;
    }

    public function check(PolicyContext $context): void
    {
        foreach ($this->rules as $rule) {
            $rule->check($context);
        }
    }

    /**
     * Сводный вклад правил в `describe`, сгруппированный по секциям. Поздние правила перекрывают
     * одноимённые ключи ранних — порядок регистрации имеет значение.
     *
     * @return array<string, array<string, mixed>>
     */
    public function describe(): array
    {
        $sections = [];
        foreach ($this->rules as $rule) {
            foreach ($rule->describe() as $section => $values) {
                $sections[$section] = array_merge($sections[$section] ?? [], $values);
            }
        }
        return $sections;
    }

    /** @return list<PolicyRuleInterface> */
    public function rules(): array
    {
        return $this->rules;
    }
}
