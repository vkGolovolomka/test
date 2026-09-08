<?php

declare(strict_types=1);

namespace Local\CrmAssignCascade\Service;

use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;
use Local\CrmAssignCascade\Exception\JobCancelledException;
use Local\CrmAssignCascade\Exception\RetryableCompanyException;
use Local\CrmAssignCascade\Exception\YieldException;
use Local\CrmAssignCascade\Orm\JobCompanyTable;
use Local\CrmAssignCascade\Orm\JobTable;
use Local\CrmAssignCascade\Orm\LogTable;

/**
 * Короткоживущий обработчик очереди для cron.
 *
 * flock не дает обычным cron-запускам пересекаться, а conditional UPDATE при
 * захвате строки остается дополнительной защитой целостности очереди.
 */
final class WorkerService
{
    private const MAX_COMPANY_ATTEMPTS = 3;
    private const STALE_PROCESSING_SECONDS = 300;

    /** Делает ограниченную порцию работы и завершает процесс до следующего cron-tick. */
    public static function run(int $companyLimit = 25, int $timeBudgetSeconds = 50): array
    {
        $companyLimit = max(1, min($companyLimit, 100));
        $timeBudgetSeconds = max(2, min($timeBudgetSeconds, 55));
        $deadline = microtime(true) + $timeBudgetSeconds;
        $handled = 0;
        $doneCount = 0;
        $retryCount = 0;
        $failedCount = 0;
        $yieldedCount = 0;
        $errors = [];

        self::recoverStaleCompanies();
        NotificationService::flushPending();

        $crm = new CrmService();
        $audit = new AuditService();

        while ($handled < $companyLimit && microtime(true) < $deadline) {
            $queueItem = self::claimNextCompany();
            if (!$queueItem) {
                break;
            }

            $handled++;
            $job = JobService::getJob((int)$queueItem['JOB_ID']);
            if ((string)$job['STATUS'] === JobTable::STATUS_CANCELLED) {
                self::cancelProcessingCompany((int)$queueItem['ID']);
                continue;
            }

            self::markJobRunning($job);
            $lastCancelCheck = 0.0;
            $checkpoint = static function () use ($deadline, &$lastCancelCheck, $job): void {
                if (microtime(true) >= $deadline) {
                    throw new YieldException('Достигнут лимит времени cron worker-а.');
                }

                $now = microtime(true);
                if ($now - $lastCancelCheck >= 2.0) {
                    $lastCancelCheck = $now;
                    $row = JobTable::getByPrimary((int)$job['ID'], ['select' => ['STATUS']])->fetch();
                    if ($row && (string)$row['STATUS'] === JobTable::STATUS_CANCELLED) {
                        throw new JobCancelledException('Задание остановлено администратором.');
                    }
                }
            };

            try {
                (new CascadeService($job, $queueItem, $crm, $audit, $checkpoint))
                    ->processCompany((int)$queueItem['COMPANY_ID']);
                self::completeCompany((int)$queueItem['ID']);
                $doneCount++;
            } catch (JobCancelledException $e) {
                self::cancelProcessingCompany((int)$queueItem['ID']);
                break;
            } catch (YieldException $e) {
                self::yieldCompany((int)$queueItem['ID']);
                $yieldedCount++;
                break;
            } catch (RetryableCompanyException $e) {
                $state = self::retryOrFailCompany($queueItem, $e->getMessage());
                $state === 'failed' ? $failedCount++ : $retryCount++;
                $errors[] = 'company=' . (int)$queueItem['COMPANY_ID'] . ': ' . $e->getMessage();
            } catch (\Throwable $e) {
                $state = self::retryOrFailCompany($queueItem, $e->getMessage());
                $state === 'failed' ? $failedCount++ : $retryCount++;
                $errors[] = 'company=' . (int)$queueItem['COMPANY_ID'] . ': ' . $e->getMessage();
            }
        }

        self::finishReadyJobs();
        NotificationService::flushPending();

        return [
            'handledCompanies' => $handled,
            'doneCompanies' => $doneCount,
            'retriedCompanies' => $retryCount,
            'failedCompanies' => $failedCount,
            'yieldedCompanies' => $yieldedCount,
            'errors' => array_slice($errors, -10),
        ];
    }

    private static function claimNextCompany(): ?array
    {
        $candidates = JobCompanyTable::getList([
            'filter' => [
                '=STATUS' => JobCompanyTable::STATUS_QUEUED,
                '<=AVAILABLE_AT' => new DateTime(),
            ],
            'order' => ['AVAILABLE_AT' => 'ASC', 'JOB_ID' => 'ASC', 'POSITION' => 'ASC', 'ID' => 'ASC'],
            'limit' => 25,
        ]);

        while ($candidate = $candidates->fetch()) {
            $job = JobTable::getByPrimary((int)$candidate['JOB_ID'], ['select' => ['STATUS']])->fetch();
            if (!$job || !in_array((string)$job['STATUS'], [JobTable::STATUS_QUEUED, JobTable::STATUS_RUNNING], true)) {
                if ($job && (string)$job['STATUS'] === JobTable::STATUS_CANCELLED) {
                    JobCompanyTable::update((int)$candidate['ID'], [
                        'STATUS' => JobCompanyTable::STATUS_CANCELLED,
                        'UPDATED_AT' => new DateTime(),
                        'FINISHED_AT' => new DateTime(),
                    ]);
                }
                continue;
            }

            if (!self::atomicClaim((int)$candidate['ID'])) {
                continue;
            }

            $row = JobCompanyTable::getByPrimary((int)$candidate['ID'])->fetch();
            if ($row) {
                return $row;
            }
        }

        return null;
    }

    /** Атомарно переводит одну строку queued -> processing только если ее еще никто не забрал. */
    private static function atomicClaim(int $id): bool
    {
        $connection = Application::getConnection();
        $table = JobCompanyTable::getTableName();
        $now = (new DateTime())->format('Y-m-d H:i:s');
        $safeNow = $connection->getSqlHelper()->forSql($now);

        $connection->queryExecute(
            "UPDATE {$table} SET STATUS='" . JobCompanyTable::STATUS_PROCESSING . "', "
            . "UPDATED_AT='{$safeNow}', STARTED_AT=COALESCE(STARTED_AT, '{$safeNow}') "
            . "WHERE ID={$id} AND STATUS='" . JobCompanyTable::STATUS_QUEUED . "' AND AVAILABLE_AT<='{$safeNow}'"
        );

        return $connection->getAffectedRowsCount() === 1;
    }

    private static function completeCompany(int $id): void
    {
        $result = JobCompanyTable::update($id, [
            'STATUS' => JobCompanyTable::STATUS_DONE,
            'LAST_ERROR' => '',
            'UPDATED_AT' => new DateTime(),
            'FINISHED_AT' => new DateTime(),
        ]);
        if (!$result->isSuccess()) {
            throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
        }
    }

    private static function yieldCompany(int $id): void
    {
        $result = JobCompanyTable::update($id, [
            'STATUS' => JobCompanyTable::STATUS_QUEUED,
            'AVAILABLE_AT' => new DateTime(),
            'UPDATED_AT' => new DateTime(),
        ]);
        if (!$result->isSuccess()) {
            throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
        }
    }

    private static function cancelProcessingCompany(int $id): void
    {
        JobCompanyTable::update($id, [
            'STATUS' => JobCompanyTable::STATUS_CANCELLED,
            'UPDATED_AT' => new DateTime(),
            'FINISHED_AT' => new DateTime(),
        ]);
    }

    private static function retryOrFailCompany(array $queueItem, string $message): string
    {
        $attempts = (int)$queueItem['ATTEMPTS'] + 1;
        $terminal = $attempts >= self::MAX_COMPANY_ATTEMPTS;
        $delay = min(300, 30 * (2 ** max(0, $attempts - 1)));

        $fields = [
            'STATUS' => $terminal ? JobCompanyTable::STATUS_FAILED : JobCompanyTable::STATUS_QUEUED,
            'ATTEMPTS' => $attempts,
            'AVAILABLE_AT' => $terminal ? new DateTime() : DateTime::createFromTimestamp(time() + $delay),
            'LAST_ERROR' => mb_substr($message, 0, 4000),
            'UPDATED_AT' => new DateTime(),
        ];
        if ($terminal) {
            $fields['FINISHED_AT'] = new DateTime();
        }

        $result = JobCompanyTable::update((int)$queueItem['ID'], $fields);
        if (!$result->isSuccess()) {
            throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
        }

        return $terminal ? 'failed' : 'retry';
    }

    /** Возвращает зависшие processing-строки в очередь после аварийного завершения PHP. */
    private static function recoverStaleCompanies(): void
    {
        $before = DateTime::createFromTimestamp(time() - self::STALE_PROCESSING_SECONDS);
        $rows = JobCompanyTable::getList([
            'select' => ['ID'],
            'filter' => [
                '=STATUS' => JobCompanyTable::STATUS_PROCESSING,
                '<UPDATED_AT' => $before,
            ],
            'limit' => 100,
        ]);

        while ($row = $rows->fetch()) {
            JobCompanyTable::update((int)$row['ID'], [
                'STATUS' => JobCompanyTable::STATUS_QUEUED,
                'AVAILABLE_AT' => new DateTime(),
                'UPDATED_AT' => new DateTime(),
                'LAST_ERROR' => 'Восстановлено после прерванного worker-а.',
            ]);
        }
    }

    private static function markJobRunning(array $job): void
    {
        if ((string)$job['STATUS'] !== JobTable::STATUS_QUEUED) {
            return;
        }

        $fields = [
            'STATUS' => JobTable::STATUS_RUNNING,
            'UPDATED_AT' => new DateTime(),
        ];
        if (empty($job['STARTED_AT'])) {
            $fields['STARTED_AT'] = new DateTime();
        }
        JobTable::update((int)$job['ID'], $fields);
    }

    /**
     * Финальный статус job вычисляется один раз за cron-tick, а не после каждой компании.
     */
    private static function finishReadyJobs(): void
    {
        $jobs = JobTable::getList([
            'filter' => ['@STATUS' => [JobTable::STATUS_QUEUED, JobTable::STATUS_RUNNING]],
            'order' => ['ID' => 'ASC'],
            'limit' => 50,
        ]);

        while ($job = $jobs->fetch()) {
            $jobId = (int)$job['ID'];
            $queued = JobCompanyTable::getCount(['=JOB_ID' => $jobId, '=STATUS' => JobCompanyTable::STATUS_QUEUED]);
            $processing = JobCompanyTable::getCount(['=JOB_ID' => $jobId, '=STATUS' => JobCompanyTable::STATUS_PROCESSING]);
            if ($queued > 0 || $processing > 0) {
                continue;
            }

            $done = JobCompanyTable::getCount(['=JOB_ID' => $jobId, '=STATUS' => JobCompanyTable::STATUS_DONE]);
            $failed = JobCompanyTable::getCount(['=JOB_ID' => $jobId, '=STATUS' => JobCompanyTable::STATUS_FAILED]);
            $cancelled = JobCompanyTable::getCount(['=JOB_ID' => $jobId, '=STATUS' => JobCompanyTable::STATUS_CANCELLED]);
            $total = (int)$job['TOTAL_COMPANIES'];
            if ($total <= 0 || ($done + $failed + $cancelled) < $total) {
                continue;
            }

            $logErrors = LogTable::getCount(['=JOB_ID' => $jobId, '=STATUS' => LogTable::STATUS_ERROR]);
            $status = ($failed > 0 || $logErrors > 0)
                ? JobTable::STATUS_DONE_WITH_ERRORS
                : JobTable::STATUS_DONE;

            JobTable::update($jobId, [
                'STATUS' => $status,
                'LAST_ERROR' => self::findLastError($jobId),
                'UPDATED_AT' => new DateTime(),
                'FINISHED_AT' => new DateTime(),
            ]);
        }
    }

    private static function findLastError(int $jobId): string
    {
        $row = JobCompanyTable::getList([
            'select' => ['LAST_ERROR'],
            'filter' => ['=JOB_ID' => $jobId, '!=LAST_ERROR' => ''],
            'order' => ['UPDATED_AT' => 'DESC', 'ID' => 'DESC'],
            'limit' => 1,
        ])->fetch();
        if ($row && trim((string)$row['LAST_ERROR']) !== '') {
            return (string)$row['LAST_ERROR'];
        }

        $row = LogTable::getList([
            'select' => ['MESSAGE'],
            'filter' => ['=JOB_ID' => $jobId, '=STATUS' => LogTable::STATUS_ERROR],
            'order' => ['UPDATED_AT' => 'DESC', 'ID' => 'DESC'],
            'limit' => 1,
        ])->fetch();

        return $row ? (string)$row['MESSAGE'] : '';
    }
}
