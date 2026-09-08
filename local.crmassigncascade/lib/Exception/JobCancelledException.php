<?php

declare(strict_types=1);

namespace Local\CrmAssignCascade\Exception;

/** Штатный сигнал остановки job администратором; не считается технической ошибкой CRM. */
final class JobCancelledException extends \RuntimeException
{
}
