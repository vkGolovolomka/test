<?php

declare(strict_types=1);

namespace Local\CrmAssignCascade\Exception;

/**
 * Компания обработана до конца, но остались CRM-элементы с временными ошибками.
 * Строка очереди возвращается с задержкой, уже успешные элементы при повторе пропускаются по журналу.
 */
final class RetryableCompanyException extends \RuntimeException
{
}
