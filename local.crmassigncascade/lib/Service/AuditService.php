<?php

declare(strict_types=1);

namespace Local\CrmAssignCascade\Service;

use Bitrix\Main\Type\DateTime;
use Local\CrmAssignCascade\Orm\LogTable;

/**
 * Журнал изменения каждого CRM-элемента и защита от повторной обработки.
 *
 * UNIQUE по job/type/id оставляет одну логическую запись на элемент. Если worker
 * оборвался после CRM Operation, журнал позволяет определить, нужно ли повторять действие.
 */
final class AuditService
{
    public const MAX_ATTEMPTS = 3;

    public function find(int $jobId, int $entityTypeId, int $entityId): ?array
    {
        $row = LogTable::getList([
            'filter' => [
                '=JOB_ID' => $jobId,
                '=ENTITY_TYPE_ID' => $entityTypeId,
                '=ENTITY_ID' => $entityId,
            ],
            'limit' => 1,
        ])->fetch();

        return $row ?: null;
    }

    public function isTerminal(?array $row): bool
    {
        if (!$row) {
            return false;
        }

        $status = (string)$row['STATUS'];
        if (in_array($status, [LogTable::STATUS_SUCCESS, LogTable::STATUS_SKIPPED], true)) {
            return true;
        }

        return $status === LogTable::STATUS_ERROR && (int)$row['ATTEMPTS'] >= self::MAX_ATTEMPTS;
    }

    /**
     * Создает или повторно открывает единственную запись элемента в журнале.
     * terminal означает, что элемент уже окончательно обработан и трогать его повторно не надо.
     */
    public function begin(
        int $jobId,
        int $jobCompanyId,
        int $userId,
        int $companyId,
        string $entityKind,
        int $entityTypeId,
        int $entityId,
        string $title,
        ?int $oldAssigned,
        ?int $newAssigned,
        array $oldObservers,
        array $newObservers
    ): array {
        $existing = $this->find($jobId, $entityTypeId, $entityId);
        if ($this->isTerminal($existing)) {
            return ['state' => 'terminal', 'row' => $existing];
        }

        $now = new DateTime();
        if ($existing) {
            $attempts = (int)$existing['ATTEMPTS'] + 1;
            $result = LogTable::update((int)$existing['ID'], [
                'ENTITY_KIND' => $entityKind,
                'STATUS' => LogTable::STATUS_PROCESSING,
                'ATTEMPTS' => $attempts,
                'MESSAGE' => '',
                'UPDATED_AT' => $now,
                'FINISHED_AT' => null,
            ]);
            if (!$result->isSuccess()) {
                throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
            }

            $row = LogTable::getByPrimary((int)$existing['ID'])->fetch();
            return ['state' => 'acquired', 'row' => $row ?: array_merge($existing, ['ATTEMPTS' => $attempts])];
        }

        $result = LogTable::add([
            'JOB_ID' => $jobId,
            'JOB_COMPANY_ID' => $jobCompanyId,
            'USER_ID' => $userId,
            'COMPANY_ID' => $companyId,
            'ENTITY_KIND' => $entityKind,
            'ENTITY_TYPE_ID' => $entityTypeId,
            'ENTITY_ID' => $entityId,
            'ENTITY_TITLE' => $title,
            'OLD_ASSIGNED_BY_ID' => $oldAssigned,
            'NEW_ASSIGNED_BY_ID' => $newAssigned,
            'OLD_OBSERVERS' => JobService::jsonEncode($oldObservers),
            'NEW_OBSERVERS' => JobService::jsonEncode($newObservers),
            'STATUS' => LogTable::STATUS_PROCESSING,
            'ATTEMPTS' => 1,
            'RECOVERED' => 'N',
            'MESSAGE' => '',
            'CREATED_AT' => $now,
            'UPDATED_AT' => $now,
        ]);

        if (!$result->isSuccess()) {
            // При гонке UNIQUE оставит одну запись; перечитываем фактически созданную строку.
            $existing = $this->find($jobId, $entityTypeId, $entityId);
            if ($existing) {
                return $this->isTerminal($existing)
                    ? ['state' => 'terminal', 'row' => $existing]
                    : $this->begin(
                        $jobId,
                        $jobCompanyId,
                        $userId,
                        $companyId,
                        $entityKind,
                        $entityTypeId,
                        $entityId,
                        $title,
                        $oldAssigned,
                        $newAssigned,
                        $oldObservers,
                        $newObservers
                    );
            }
            throw new \RuntimeException(implode('; ', $result->getErrorMessages()) ?: 'Не удалось создать запись журнала.');
        }

        $row = LogTable::getByPrimary((int)$result->getId())->fetch();
        if (!$row) {
            throw new \RuntimeException('Не удалось прочитать созданную запись журнала.');
        }

        return ['state' => 'acquired', 'row' => $row];
    }

    public function finish(int $logId, string $status, string $message = ''): void
    {
        if (!in_array($status, [LogTable::STATUS_SUCCESS, LogTable::STATUS_SKIPPED, LogTable::STATUS_ERROR], true)) {
            throw new \InvalidArgumentException('Некорректный статус журнала.');
        }

        $result = LogTable::update($logId, [
            'STATUS' => $status,
            'MESSAGE' => $message,
            'UPDATED_AT' => new DateTime(),
            'FINISHED_AT' => new DateTime(),
        ]);
        if (!$result->isSuccess()) {
            throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
        }
    }

    public function recoverSuccess(array $row, string $message): void
    {
        $result = LogTable::update((int)$row['ID'], [
            'STATUS' => LogTable::STATUS_SUCCESS,
            'RECOVERED' => 'Y',
            'MESSAGE' => $message,
            'UPDATED_AT' => new DateTime(),
            'FINISHED_AT' => new DateTime(),
        ]);
        if (!$result->isSuccess()) {
            throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
        }
    }
}
