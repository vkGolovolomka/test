<?php

declare(strict_types=1);

namespace Local\CrmAssignCascade\Service;

use Bitrix\Crm\Binding\ContactCompanyTable;
use Bitrix\Crm\Item;
use Bitrix\Crm\Service\Factory;
use Local\CrmAssignCascade\Exception\JobCancelledException;
use Local\CrmAssignCascade\Exception\RetryableCompanyException;
use Local\CrmAssignCascade\Exception\YieldException;
use Local\CrmAssignCascade\Orm\LogTable;

/**
 * Каскадная бизнес-логика одной компании.
 *
 * Компания изменяется всегда, а контакты/сделки/СП/счета — только если тип явно
 * выбран пользователем. Класс не знает ничего о HTTP и механике захвата очереди.
 */
final class CascadeService
{
    private int $jobId;
    private int $jobCompanyId;
    private int $userId;
    private int $assignedById;
    private array $observerIds;
    private string $observerMode;
    private array $applyTypes;
    private CrmService $crm;
    private AuditService $audit;
    /** @var callable */
    private $checkpoint;
    private bool $hasRetryableEntityErrors = false;
    private array $sectionErrors = [];

    public function __construct(
        array $job,
        array $jobCompany,
        CrmService $crm,
        AuditService $audit,
        callable $checkpoint
    ) {
        $this->jobId = (int)$job['ID'];
        $this->jobCompanyId = (int)$jobCompany['ID'];
        $this->userId = (int)$job['CREATED_BY'];
        $this->assignedById = (int)$job['ASSIGNED_BY_ID'];
        $this->observerIds = JobService::normalizeIds(JobService::jsonDecode((string)$job['OBSERVER_IDS']));
        $this->observerMode = (string)$job['OBSERVER_MODE'];
        $this->applyTypes = JobService::normalizeApplyTypes(JobService::jsonDecode((string)$job['APPLY_TYPES']));
        $this->crm = $crm;
        $this->audit = $audit;
        $this->checkpoint = $checkpoint;
    }

    /** Обрабатывает компанию и только отмеченные пользователем ветки связей. */
    public function processCompany(int $companyId): void
    {
        $this->check();
        $this->updateById(LogTable::KIND_COMPANY, \CCrmOwnerType::Company, $companyId, $companyId, false);

        if (in_array('contacts', $this->applyTypes, true)) {
            $this->safeSection('Контакты', function () use ($companyId): void {
                foreach (ContactCompanyTable::getCompanyContactIDs($companyId) as $contactId) {
                    $this->check();
                    $this->updateById(LogTable::KIND_CONTACT, \CCrmOwnerType::Contact, (int)$contactId, $companyId, false);
                }
            });
        }

        if (in_array('deals', $this->applyTypes, true)) {
            $this->safeSection('Сделки', function () use ($companyId): void {
                $this->processByCompany(LogTable::KIND_DEAL, \CCrmOwnerType::Deal, $companyId, true);
            });
        }

        if (in_array('dynamics', $this->applyTypes, true)) {
            $this->safeSection('Смарт-процессы', function () use ($companyId): void {
                foreach ($this->crm->relatedDynamicItems($companyId) as $related) {
                    $this->check();
                    $this->updateById(
                        LogTable::KIND_DYNAMIC,
                        (int)$related['entityTypeId'],
                        (int)$related['entityId'],
                        $companyId,
                        true
                    );
                }

                $discoveryErrors = $this->crm->dynamicDiscoveryErrors();
                if ($discoveryErrors !== []) {
                    $messages = [];
                    foreach ($discoveryErrors as $entityTypeId => $message) {
                        $messages[] = '#' . $entityTypeId . ': ' . $message;
                    }
                    throw new \RuntimeException('Не удалось инициализировать типы СП: ' . implode('; ', $messages));
                }
            });
        }

        if (in_array('invoices', $this->applyTypes, true)) {
            $this->safeSection('Счета', function () use ($companyId): void {
                if (!defined('CCrmOwnerType::SmartInvoice')) {
                    throw new \RuntimeException('Новые счета недоступны в этой версии CRM.');
                }
                $this->processByCompany(LogTable::KIND_INVOICE, \CCrmOwnerType::SmartInvoice, $companyId, false);
            });
        }

        if ($this->sectionErrors !== []) {
            throw new RetryableCompanyException(implode(' | ', $this->sectionErrors));
        }
        if ($this->hasRetryableEntityErrors) {
            throw new RetryableCompanyException('Есть CRM-элементы с временными ошибками; компания будет повторена.');
        }
    }

    private function processByCompany(
        string $kind,
        int $entityTypeId,
        int $companyId,
        bool $activeOnly,
        ?Factory $factory = null
    ): void {
        $factory ??= $this->crm->factory($entityTypeId);
        if (!$factory || !$this->crm->clientEnabled($factory)) {
            return;
        }

        $companyField = $this->crm->companyFieldName($factory);
        $lastId = 0;
        do {
            $this->check();
            $items = $factory->getItems([
                'filter' => ['=' . $companyField => $companyId, '>ID' => $lastId],
                'order' => ['ID' => 'ASC'],
                'limit' => 50,
            ]);

            foreach ($items as $item) {
                $this->check();
                $lastId = max($lastId, (int)$item->getId());

                $existing = $this->audit->find($this->jobId, $entityTypeId, (int)$item->getId());
                if ($this->audit->isTerminal($existing)) {
                    continue;
                }

                $fresh = $factory->getItem((int)$item->getId());
                if (!$fresh) {
                    $this->recordError($kind, $companyId, $entityTypeId, (int)$item->getId(), 'CRM-элемент не найден после выборки.');
                    continue;
                }

                if ($activeOnly && $this->crm->isFinal($factory, $fresh)) {
                    $this->recordSkipped($kind, $factory, $fresh, $companyId, 'Финальная стадия — элемент не изменён.');
                    continue;
                }

                $this->updateItem($kind, $factory, $fresh, $companyId);
            }
        } while (count($items) === 50);
    }

    private function updateById(
        string $kind,
        int $entityTypeId,
        int $entityId,
        int $companyId,
        bool $activeOnly
    ): void {
        $this->check();
        $existing = $this->audit->find($this->jobId, $entityTypeId, $entityId);
        if ($this->audit->isTerminal($existing)) {
            return;
        }

        $factory = $this->crm->factory($entityTypeId);
        if (!$factory) {
            $this->recordError($kind, $companyId, $entityTypeId, $entityId, 'Factory CRM-типа недоступна.');
            return;
        }

        $item = $factory->getItem($entityId);
        if (!$item) {
            $this->recordError($kind, $companyId, $entityTypeId, $entityId, 'CRM-элемент не найден.');
            return;
        }

        if ($activeOnly && $this->crm->isFinal($factory, $item)) {
            $this->recordSkipped($kind, $factory, $item, $companyId, 'Финальная стадия — элемент не изменён.');
            return;
        }

        $this->updateItem($kind, $factory, $item, $companyId);
    }

    private function updateItem(string $kind, Factory $factory, Item $item, int $companyId): void
    {
        $this->check();
        $entityTypeId = (int)$item->getEntityTypeId();
        $entityId = (int)$item->getId();
        $state = $this->crm->targetState(
            $factory,
            $item,
            $this->assignedById,
            $this->observerIds,
            $this->observerMode
        );

        $existing = $this->audit->find($this->jobId, $entityTypeId, $entityId);
        if ($this->audit->isTerminal($existing)) {
            return;
        }

        // Recovery после аварии: CRM уже могла сохраниться, а журнал остаться в processing.
        if ($existing && (string)$existing['STATUS'] === LogTable::STATUS_PROCESSING && !empty($state['matches'])) {
            $this->audit->recoverSuccess(
                $existing,
                'Восстановлено после прерванного worker-а: CRM уже находится в целевом состоянии.'
            );
            return;
        }

        if (empty($state['hasAssigned']) && empty($state['hasObservers'])) {
            $this->recordTerminal($kind, $companyId, $entityTypeId, $entityId, (string)$state['title'], $state, LogTable::STATUS_SKIPPED,
                'Тип не поддерживает ни ответственного, ни наблюдателей.');
            return;
        }

        if (!empty($state['matches'])) {
            $this->recordTerminal($kind, $companyId, $entityTypeId, $entityId, (string)$state['title'], $state, LogTable::STATUS_SKIPPED,
                'Изменения не требуются.');
            return;
        }

        $claim = $this->audit->begin(
            $this->jobId,
            $this->jobCompanyId,
            $this->userId,
            $companyId,
            $kind,
            $entityTypeId,
            $entityId,
            (string)$state['title'],
            $state['oldAssigned'],
            $state['newAssigned'],
            (array)$state['oldObservers'],
            (array)$state['newObservers']
        );

        if ($claim['state'] === 'terminal') {
            return;
        }

        $log = $claim['row'];
        try {
            $result = $this->crm->update($factory, $item, $state, $this->userId);
            if (!$result->isSuccess()) {
                $message = implode('; ', $result->getErrorMessages());
                $this->audit->finish((int)$log['ID'], LogTable::STATUS_ERROR, $message);
                if ((int)$log['ATTEMPTS'] < AuditService::MAX_ATTEMPTS) {
                    $this->hasRetryableEntityErrors = true;
                }
                return;
            }

            $message = !empty($state['hasObservers'])
                ? ''
                : 'Наблюдатели для этого CRM-типа не поддерживаются; изменён только ответственный.';
            $this->audit->finish((int)$log['ID'], LogTable::STATUS_SUCCESS, $message);
        } catch (YieldException | JobCancelledException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->audit->finish((int)$log['ID'], LogTable::STATUS_ERROR, $e->getMessage());
            if ((int)$log['ATTEMPTS'] < AuditService::MAX_ATTEMPTS) {
                $this->hasRetryableEntityErrors = true;
            }
        }
    }

    private function recordSkipped(string $kind, Factory $factory, Item $item, int $companyId, string $message): void
    {
        $state = $this->crm->targetState($factory, $item, 0, [], 'replace');
        // Для финального элемента сохраняем в аудите фактическое состояние без попытки изменения.
        $state['newAssigned'] = $state['oldAssigned'];
        $state['newObservers'] = $state['oldObservers'];

        $this->recordTerminal(
            $kind,
            $companyId,
            (int)$item->getEntityTypeId(),
            (int)$item->getId(),
            (string)$state['title'],
            $state,
            LogTable::STATUS_SKIPPED,
            $message
        );
    }

    private function recordError(string $kind, int $companyId, int $entityTypeId, int $entityId, string $message): void
    {
        $claim = $this->audit->begin(
            $this->jobId,
            $this->jobCompanyId,
            $this->userId,
            $companyId,
            $kind,
            $entityTypeId,
            $entityId,
            '',
            null,
            null,
            [],
            []
        );
        if ($claim['state'] === 'terminal') {
            return;
        }

        $this->audit->finish((int)$claim['row']['ID'], LogTable::STATUS_ERROR, $message);
        if ((int)$claim['row']['ATTEMPTS'] < AuditService::MAX_ATTEMPTS) {
            $this->hasRetryableEntityErrors = true;
        }
    }

    private function recordTerminal(
        string $kind,
        int $companyId,
        int $entityTypeId,
        int $entityId,
        string $title,
        array $state,
        string $status,
        string $message
    ): void {
        $claim = $this->audit->begin(
            $this->jobId,
            $this->jobCompanyId,
            $this->userId,
            $companyId,
            $kind,
            $entityTypeId,
            $entityId,
            $title,
            $state['oldAssigned'] ?? null,
            $state['newAssigned'] ?? null,
            (array)($state['oldObservers'] ?? []),
            (array)($state['newObservers'] ?? [])
        );
        if ($claim['state'] === 'terminal') {
            return;
        }

        $this->audit->finish((int)$claim['row']['ID'], $status, $message);
    }

    private function safeSection(string $section, callable $callback): void
    {
        try {
            $callback();
        } catch (YieldException | JobCancelledException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->sectionErrors[] = $section . ': ' . $e->getMessage();
        }
    }

    private function check(): void
    {
        ($this->checkpoint)();
    }
}
