<?php

declare(strict_types=1);

namespace Local\CrmAssignCascade\Exception;

/**
 * Не ошибка: worker исчерпал лимит времени и продолжит компанию в следующем cron-tick.
 */
final class YieldException extends \RuntimeException
{
}
