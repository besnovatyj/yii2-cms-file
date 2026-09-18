<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\api;

/**
 * Одна операция контракта bescms-fs.
 *
 * Операция — это «разобрать вход → вызвать домен → собрать `data`». Она не знает ни про HTTP,
 * ни про Yii: адаптер (контроллёр) строит {@see Payload} и {@see ApiContext}, а результат
 * заворачивает в конверт. Добавить операцию = реализовать интерфейс + зарегистрировать в
 * {@see OperationRegistry}; `describe` подхватит её автоматически.
 */
interface OperationInterface
{
    /** Имя операции = сегмент URL (`list`, `mkdir`, …). */
    public function name(): string;

    /** HTTP-метод: 'POST' (JSON/multipart) или 'GET' (потоковые операции). */
    public function method(): string;

    /**
     * Дополнительные сведения для `describe.operations[name]` (`batch`, `async`).
     *
     * @return array<string, mixed>
     */
    public function info(): array;

    /**
     * Выполняет операцию.
     *
     * @return array<string, mixed>|StreamResult Тело `data` конверта либо поток.
     * @throws ApiException ошибка входа.
     * @throws \Besnovatyj\File\fs\exception\FsException доменная ошибка.
     */
    public function execute(Payload $input, ApiContext $context): array|StreamResult;
}
