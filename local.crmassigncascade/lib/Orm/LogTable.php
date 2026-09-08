<?php

declare(strict_types=1);

namespace Local\CrmAssignCascade\Orm;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields;

/**
 * Журнал изменений и состояние идемпотентности по CRM-элементам.
 * UNIQUE(JOB_ID, ENTITY_TYPE_ID, ENTITY_ID) оставляет одну логическую запись на элемент внутри job.
 */
final class LogTable extends DataManager
{
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_ERROR = 'error';

    public const KIND_COMPANY = 'company';
    public const KIND_CONTACT = 'contact';
    public const KIND_DEAL = 'deal';
    public const KIND_DYNAMIC = 'dynamic';
    public const KIND_INVOICE = 'invoice';

    public static function getTableName(): string
    {
        return 'b_local_crm_assign_log';
    }

    public static function getMap(): array
    {
        return [
            new Fields\IntegerField('ID', ['primary' => true, 'autocomplete' => true]),
            new Fields\IntegerField('JOB_ID', ['required' => true]),
            new Fields\IntegerField('JOB_COMPANY_ID', ['required' => true]),
            new Fields\IntegerField('USER_ID', ['required' => true]),
            new Fields\IntegerField('COMPANY_ID', ['required' => true]),
            new Fields\StringField('ENTITY_KIND', ['required' => true, 'size' => 24]),
            new Fields\IntegerField('ENTITY_TYPE_ID', ['required' => true]),
            new Fields\IntegerField('ENTITY_ID', ['required' => true]),
            new Fields\StringField('ENTITY_TITLE', ['size' => 255]),

            new Fields\IntegerField('OLD_ASSIGNED_BY_ID'),
            new Fields\IntegerField('NEW_ASSIGNED_BY_ID'),
            new Fields\TextField('OLD_OBSERVERS'),
            new Fields\TextField('NEW_OBSERVERS'),

            new Fields\StringField('STATUS', ['required' => true, 'size' => 24]),
            new Fields\IntegerField('ATTEMPTS', ['default_value' => 0]),
            new Fields\StringField('RECOVERED', ['required' => true, 'size' => 1, 'default_value' => 'N']),
            new Fields\TextField('MESSAGE'),
            new Fields\DatetimeField('CREATED_AT', ['required' => true]),
            new Fields\DatetimeField('UPDATED_AT', ['required' => true]),
            new Fields\DatetimeField('FINISHED_AT'),
        ];
    }
}
