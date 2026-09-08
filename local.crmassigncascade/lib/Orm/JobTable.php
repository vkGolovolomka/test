<?php

declare(strict_types=1);

namespace Local\CrmAssignCascade\Orm;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields;

/**
 * Заголовок массовой операции: кто запустил, что назначить, какой scope выбран и lifecycle job.
 * Список компаний и поэлементный аудит вынесены в отдельные таблицы.
 */
final class JobTable extends DataManager
{
    public const STATUS_PREPARING = 'preparing';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE = 'done';
    public const STATUS_DONE_WITH_ERRORS = 'done_with_errors';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const SELECTION_SELECTED = 'selected';
    public const SELECTION_ALL = 'all';

    public static function getTableName(): string
    {
        return 'b_local_crm_assign_job';
    }

    public static function getMap(): array
    {
        return [
            new Fields\IntegerField('ID', ['primary' => true, 'autocomplete' => true]),
            new Fields\StringField('REQUEST_UID', ['required' => true, 'size' => 64]),
            new Fields\StringField('STATUS', ['required' => true, 'size' => 32]),
            new Fields\IntegerField('CREATED_BY', ['required' => true]),

            new Fields\IntegerField('ASSIGNED_BY_ID', ['required' => true]),
            new Fields\TextField('OBSERVER_IDS'),
            new Fields\StringField('OBSERVER_MODE', ['required' => true, 'size' => 16]),
            new Fields\TextField('APPLY_TYPES'),

            new Fields\StringField('SELECTION_MODE', ['required' => true, 'size' => 16]),
            new Fields\StringField('FILTER_ID', ['size' => 120]),
            new Fields\TextField('FILTER_JSON'),
            new Fields\IntegerField('TOTAL_COMPANIES', ['default_value' => 0]),

            new Fields\TextField('LAST_ERROR'),
            new Fields\DatetimeField('CREATED_AT', ['required' => true]),
            new Fields\DatetimeField('UPDATED_AT', ['required' => true]),
            new Fields\DatetimeField('STARTED_AT'),
            new Fields\DatetimeField('FINISHED_AT'),
            new Fields\DatetimeField('NOTIFIED_AT'),
        ];
    }
}
