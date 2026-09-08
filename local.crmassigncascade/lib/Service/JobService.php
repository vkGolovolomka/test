<?php

declare(strict_types=1);

namespace Local\CrmAssignCascade\Service;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Factory;
use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserTable;
use Local\CrmAssignCascade\Orm\JobCompanyTable;
use Local\CrmAssignCascade\Orm\JobTable;
use Local\CrmAssignCascade\Orm\LogTable;

/**
 * Создание и управление массовым заданием.
 *
 * Ключевой принцип: HTTP только проверяет параметры и в одной транзакции
 * фиксирует точный список компаний. Само изменение CRM выполняет cron-worker.
 */
final class JobService
{
    public const CLIENT_CONTRACT_VERSION = 3;
    private const MATERIALIZE_BATCH = 500;
    private const ALLOWED_APPLY_TYPES = ['contacts', 'deals', 'dynamics', 'invoices'];

    /**
     * Валидирует команду пользователя и атомарно создает job + snapshot компаний.
     * Если фактическое число компаний не совпало с подтвержденным в UI, транзакция откатывается.
     */
    public static function create(
        int $createdBy,
        int $contractVersion,
        string $requestUid,
        array $companyIds,
        string $selectionMode,
        int $expectedCompanyCount,
        int $assignedById,
        array $observerIds,
        string $observerMode,
        array $applyTypes,
        array $rawFilter,
        string $filterId
    ): int {
        self::assertClientContract($contractVersion);
        self::assertActiveUser($createdBy);
        self::assertActiveUser($assignedById);

        $requestUid = self::normalizeRequestUid($requestUid);
        $existing = self::findJobByRequestUid($requestUid);
        if ($existing > 0) {
            return $existing;
        }

        $selectionMode = strtolower(trim($selectionMode));
        if (!in_array($selectionMode, [JobTable::SELECTION_SELECTED, JobTable::SELECTION_ALL], true)) {
            throw new \InvalidArgumentException('Некорректный режим выбора компаний.');
        }

        $companyIds = self::normalizeIds($companyIds);
        $observerIds = self::normalizeIds($observerIds);
        foreach ($observerIds as $observerId) {
            self::assertActiveUser($observerId);
        }
        if ($observerIds === []) {
            throw new \InvalidArgumentException('Выберите хотя бы одного наблюдателя.');
        }
        if (!in_array($observerMode, ['replace', 'add'], true)) {
            throw new \InvalidArgumentException('Некорректный режим наблюдателей.');
        }

        $applyTypes = self::normalizeApplyTypes($applyTypes);
        $isAll = $selectionMode === JobTable::SELECTION_ALL;
        if (!$isAll && $companyIds === []) {
            throw new \InvalidArgumentException('Не выбраны компании.');
        }
        if ($expectedCompanyCount <= 0) {
            throw new \InvalidArgumentException('Не подтверждено количество компаний для операции.');
        }
        if (!$isAll && $expectedCompanyCount !== count($companyIds)) {
            throw new \InvalidArgumentException('Выбор компаний изменился перед запуском. Повторите действие.');
        }

        $crm = new CrmService();
        $companyFactory = $crm->factory(\CCrmOwnerType::Company);
        if (!$companyFactory || !$crm->hasField($companyFactory, CrmService::assignedFieldName())) {
            throw new \RuntimeException('D7 Factory компаний недоступна.');
        }
        if (!$crm->observersEnabled($companyFactory)) {
            throw new \RuntimeException('Эта конфигурация CRM не поддерживает наблюдателей у компаний.');
        }
        if (!method_exists($companyFactory, 'getUpdateOperation')) {
            throw new \RuntimeException('Эта версия CRM не поддерживает D7 UpdateOperation.');
        }
        if (in_array('dynamics', $applyTypes, true)) {
            // Не привязываемся только к DynamicTypesMap: на части коробок он пуст в CLI,
            // поэтому CrmService умеет дополнительно читать D7-таблицу типов СП.
            try {
                $crm->dynamicTypes();
            } catch (\Throwable $e) {
                throw new \RuntimeException('Смарт-процессы недоступны через D7: ' . $e->getMessage(), 0, $e);
            }
        }
        if (in_array('invoices', $applyTypes, true)) {
            if (!defined('CCrmOwnerType::SmartInvoice') || !$crm->factory(\CCrmOwnerType::SmartInvoice)) {
                throw new \RuntimeException('Новые счета (Smart Invoice) недоступны через D7.');
            }
        }

        $now = new DateTime();
        $connection = Application::getConnection();
        $connection->startTransaction();

        try {
            $result = JobTable::add([
                'REQUEST_UID' => $requestUid,
                'STATUS' => JobTable::STATUS_PREPARING,
                'CREATED_BY' => $createdBy,
                'ASSIGNED_BY_ID' => $assignedById,
                'OBSERVER_IDS' => self::jsonEncode($observerIds),
                'OBSERVER_MODE' => $observerMode,
                'APPLY_TYPES' => self::jsonEncode($applyTypes),
                'SELECTION_MODE' => $selectionMode,
                'FILTER_ID' => trim($filterId),
                'FILTER_JSON' => self::jsonEncode($isAll ? $rawFilter : []),
                'TOTAL_COMPANIES' => 0,
                'LAST_ERROR' => '',
                'CREATED_AT' => $now,
                'UPDATED_AT' => $now,
            ]);
            if (!$result->isSuccess()) {
                throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
            }

            $jobId = (int)$result->getId();
            $total = $isAll
                ? self::materializeAllCompanies($jobId, $companyFactory, $rawFilter, trim($filterId))
                : self::materializeSelectedCompanies($jobId, $companyFactory, $companyIds);

            if ($total <= 0) {
                throw new \InvalidArgumentException($isAll
                    ? 'По текущему фильтру нет компаний.'
                    : 'Выбранные компании не найдены.');
            }
            if ($total !== $expectedCompanyCount) {
                throw new \InvalidArgumentException(
                    'Количество компаний изменилось: ожидалось ' . $expectedCompanyCount . ', получено ' . $total . '. Повторите действие.'
                );
            }

            $update = JobTable::update($jobId, [
                'STATUS' => JobTable::STATUS_QUEUED,
                'TOTAL_COMPANIES' => $total,
                'UPDATED_AT' => new DateTime(),
            ]);
            if (!$update->isSuccess()) {
                throw new \RuntimeException(implode('; ', $update->getErrorMessages()));
            }

            $connection->commitTransaction();
            return $jobId;
        } catch (\Throwable $e) {
            $connection->rollbackTransaction();
            $existing = self::findJobByRequestUid($requestUid);
            if ($existing > 0) {
                return $existing;
            }
            throw $e;
        }
    }

    /** Возвращает количество компаний до запуска, особенно важно для штатного режима «Для всех». */
    public static function previewSelection(
        int $contractVersion,
        array $companyIds,
        string $selectionMode,
        array $rawFilter,
        string $filterId
    ): array {
        self::assertClientContract($contractVersion);
        $selectionMode = strtolower(trim($selectionMode));
        if (!in_array($selectionMode, [JobTable::SELECTION_SELECTED, JobTable::SELECTION_ALL], true)) {
            throw new \InvalidArgumentException('Некорректный режим выбора компаний.');
        }

        $companyIds = self::normalizeIds($companyIds);
        if ($selectionMode === JobTable::SELECTION_SELECTED) {
            return ['selectionMode' => JobTable::SELECTION_SELECTED, 'totalCompanies' => count($companyIds)];
        }

        $factory = (new CrmService())->factory(\CCrmOwnerType::Company);
        if (!$factory || !method_exists($factory, 'getItemsCount')) {
            throw new \RuntimeException('Не удалось определить количество компаний по фильтру.');
        }

        return [
            'selectionMode' => JobTable::SELECTION_ALL,
            'totalCompanies' => (int)$factory->getItemsCount(self::prepareCompanyFilter($rawFilter, $filterId)),
        ];
    }

    public static function getStatus(int $jobId): array
    {
        $job = self::getJob($jobId);
        $done = JobCompanyTable::getCount(['=JOB_ID' => $jobId, '=STATUS' => JobCompanyTable::STATUS_DONE]);
        $failed = JobCompanyTable::getCount(['=JOB_ID' => $jobId, '=STATUS' => JobCompanyTable::STATUS_FAILED]);
        $cancelled = JobCompanyTable::getCount(['=JOB_ID' => $jobId, '=STATUS' => JobCompanyTable::STATUS_CANCELLED]);
        $updated = LogTable::getCount(['=JOB_ID' => $jobId, '=STATUS' => LogTable::STATUS_SUCCESS]);
        $skipped = LogTable::getCount(['=JOB_ID' => $jobId, '=STATUS' => LogTable::STATUS_SKIPPED]);
        $errors = LogTable::getCount(['=JOB_ID' => $jobId, '=STATUS' => LogTable::STATUS_ERROR]);

        return [
            'id' => $jobId,
            'status' => (string)$job['STATUS'],
            'selectionMode' => (string)$job['SELECTION_MODE'],
            'applyTypes' => self::normalizeApplyTypes(self::jsonDecode((string)$job['APPLY_TYPES'])),
            'totalCompanies' => (int)$job['TOTAL_COMPANIES'],
            'processedCompanies' => $done + $failed,
            'successCompanies' => $done,
            'failedCompanies' => $failed,
            'cancelledCompanies' => $cancelled,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
            'lastError' => (string)$job['LAST_ERROR'],
        ];
    }

    public static function cancel(int $jobId): array
    {
        $job = self::getJob($jobId);
        if (!in_array((string)$job['STATUS'], [JobTable::STATUS_DONE, JobTable::STATUS_DONE_WITH_ERRORS, JobTable::STATUS_CANCELLED], true)) {
            JobTable::update($jobId, [
                'STATUS' => JobTable::STATUS_CANCELLED,
                'UPDATED_AT' => new DateTime(),
                'FINISHED_AT' => new DateTime(),
            ]);

            $rows = JobCompanyTable::getList([
                'select' => ['ID'],
                'filter' => ['=JOB_ID' => $jobId, '=STATUS' => JobCompanyTable::STATUS_QUEUED],
            ]);
            while ($row = $rows->fetch()) {
                JobCompanyTable::update((int)$row['ID'], [
                    'STATUS' => JobCompanyTable::STATUS_CANCELLED,
                    'UPDATED_AT' => new DateTime(),
                    'FINISHED_AT' => new DateTime(),
                ]);
            }
        }

        return self::getStatus($jobId);
    }

    public static function getJob(int $jobId): array
    {
        if ($jobId <= 0) {
            throw new \InvalidArgumentException('Некорректный ID задания.');
        }

        $job = JobTable::getByPrimary($jobId)->fetch();
        if (!$job) {
            throw new \RuntimeException('Задание не найдено.');
        }

        return $job;
    }

    public static function normalizeApplyTypes(array $types): array
    {
        $result = [];
        foreach ($types as $type) {
            if (!is_scalar($type)) {
                throw new \InvalidArgumentException('Некорректный список связанных сущностей.');
            }
            $type = strtolower(trim((string)$type));
            if (!in_array($type, self::ALLOWED_APPLY_TYPES, true)) {
                throw new \InvalidArgumentException('Неизвестный тип связанной сущности: ' . $type);
            }
            if (!in_array($type, $result, true)) {
                $result[] = $type;
            }
        }
        return $result;
    }

    public static function normalizeIds(array $ids): array
    {
        $ids = array_map('intval', $ids);
        $ids = array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    public static function jsonEncode(array $value): string
    {
        return (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function jsonDecode(string $value): array
    {
        if ($value === '') {
            return [];
        }
        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    }

    /** Фиксирует в очереди только явно выбранные ID компаний. */
    private static function materializeSelectedCompanies(int $jobId, Factory $factory, array $companyIds): int
    {
        $existing = [];
        foreach (array_chunk($companyIds, self::MATERIALIZE_BATCH) as $chunk) {
            $items = $factory->getItems([
                'select' => ['ID'],
                'filter' => ['@ID' => $chunk],
                'order' => ['ID' => 'ASC'],
                'limit' => count($chunk),
            ]);
            foreach ($items as $item) {
                $existing[(int)$item->getId()] = true;
            }
        }

        $missing = array_values(array_filter($companyIds, static fn(int $id): bool => !isset($existing[$id])));
        if ($missing !== []) {
            throw new \InvalidArgumentException('Не найдены или недоступны компании: ' . implode(', ', array_slice($missing, 0, 20)));
        }

        $position = 0;
        foreach ($companyIds as $companyId) {
            self::insertCompany($jobId, $companyId, ++$position);
        }
        return $position;
    }

    /** Материализует текущую выборку Grid по CRM-фильтру; после commit фильтр worker-у больше не нужен. */
    private static function materializeAllCompanies(int $jobId, Factory $factory, array $rawFilter, string $filterId): int
    {
        $prepared = self::prepareCompanyFilter($rawFilter, $filterId);
        $lastId = 0;
        $position = 0;

        do {
            $filter = $prepared;
            if ($lastId > 0) {
                $filter['>ID'] = $lastId;
            }

            $items = $factory->getItems([
                'select' => ['ID'],
                'filter' => $filter,
                'order' => ['ID' => 'ASC'],
                'limit' => self::MATERIALIZE_BATCH,
            ]);
            foreach ($items as $item) {
                $companyId = (int)$item->getId();
                if ($companyId <= $lastId) {
                    continue;
                }
                self::insertCompany($jobId, $companyId, ++$position);
                $lastId = $companyId;
            }
        } while (count($items) === self::MATERIALIZE_BATCH);

        return $position;
    }

    private static function insertCompany(int $jobId, int $companyId, int $position): void
    {
        $now = new DateTime();
        $result = JobCompanyTable::add([
            'JOB_ID' => $jobId,
            'COMPANY_ID' => $companyId,
            'POSITION' => $position,
            'STATUS' => JobCompanyTable::STATUS_QUEUED,
            'ATTEMPTS' => 0,
            'AVAILABLE_AT' => $now,
            'LAST_ERROR' => '',
            'CREATED_AT' => $now,
            'UPDATED_AT' => $now,
        ]);
        if (!$result->isSuccess()) {
            throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
        }
    }

    private static function prepareCompanyFilter(array $rawFilter, string $filterId): array
    {
        $filterId = trim($filterId) !== '' ? trim($filterId) : 'CRM_COMPANY_LIST_V12';
        $container = Container::getInstance();
        if (!method_exists($container, 'getFilterFactory')) {
            throw new \RuntimeException('Режим «Для всех» недоступен: D7 FilterFactory отсутствует.');
        }

        $filterFactory = $container->getFilterFactory();
        $settings = $filterFactory->getSettings(\CCrmOwnerType::Company, $filterId, []);
        $filter = $filterFactory->getFilter($settings);
        if (!$filter) {
            throw new \RuntimeException('Не удалось создать штатный CRM-фильтр компаний.');
        }

        return $filter->getValue($rawFilter);
    }

    private static function assertClientContract(int $version): void
    {
        if ($version !== self::CLIENT_CONTRACT_VERSION) {
            throw new \RuntimeException('Интерфейс модуля устарел. Обновите страницу без кеша и повторите операцию.');
        }
    }

    private static function normalizeRequestUid(string $uid): string
    {
        $uid = trim($uid);
        if ($uid === '') {
            return bin2hex(random_bytes(16));
        }
        if (strlen($uid) > 64 || !preg_match('/^[A-Za-z0-9._:-]+$/', $uid)) {
            throw new \InvalidArgumentException('Некорректный идентификатор запроса.');
        }
        return $uid;
    }

    private static function findJobByRequestUid(string $uid): int
    {
        $row = JobTable::getList([
            'select' => ['ID'],
            'filter' => ['=REQUEST_UID' => $uid],
            'limit' => 1,
        ])->fetch();
        return $row ? (int)$row['ID'] : 0;
    }

    private static function assertActiveUser(int $userId): void
    {
        if ($userId <= 0) {
            throw new \InvalidArgumentException('Некорректный пользователь.');
        }
        $user = UserTable::getList([
            'select' => ['ID', 'ACTIVE'],
            'filter' => ['=ID' => $userId],
            'limit' => 1,
        ])->fetch();
        if (!$user || $user['ACTIVE'] !== 'Y') {
            throw new \InvalidArgumentException(sprintf('Пользователь %d не найден или неактивен.', $userId));
        }
    }
}
