<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\File\upload;

use DomainException;

/**
 * Загрузка отклонена политикой {@see UploadPolicy} (запрещённое расширение, превышение лимита
 * размера и т.п.). Сообщение адресовано пользователю и безопасно — контроллёр отдаёт его
 * клиенту как 422 (см. error mapping в FileManagerController::execute()).
 */
class UploadRejectedException extends DomainException
{
}
