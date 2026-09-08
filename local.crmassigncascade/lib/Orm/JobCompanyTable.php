<?php

declare(strict_types=1);

namespace Local\CrmAssignCascade\Orm;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields;

/**
 * Зафиксированный список компаний и одновременно очередь cron.
 * Одна строка — одна компания конкретного job; после создания состав списка не меняется.
 */
final class JobCompanyTable extends DataManager
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public static function getTableName(): string
    {
        return 'b_local_crm_assign_job_company';
    }

    public static function getMap(): array
    {
        return [
            new Fields\IntegerField('ID', ['primary' => true, 'autocomplete' => true]),
            new Fields\IntegerField('JOB_ID', ['required' => true]),
            new Fields\IntegerField('COMPANY_ID', ['required' => true]),
            new Fields\IntegerField('POSITION', ['required' => true]),
            new Fields\StringField('STATUS', ['required' => true, 'size' => 24]),
            new Fields\IntegerField('ATTEMPTS', ['default_value' => 0]),
            new Fields\DatetimeField('AVAILABLE_AT', ['required' => true]),
            new Fields\TextField('LAST_ERROR'),
            new Fields\DatetimeField('CREATED_AT', ['required' => true]),
            new Fields\DatetimeField('UPDATED_AT', ['required' => true]),
            new Fields\DatetimeField('STARTED_AT'),
            new Fields\DatetimeField('FINISHED_AT'),
        ];
    }
}
