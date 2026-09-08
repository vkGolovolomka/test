<?php

declare(strict_types=1);

namespace Local\CrmAssignCascade\Service;

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Local\CrmAssignCascade\Orm\JobCompanyTable;
use Local\CrmAssignCascade\Orm\JobTable;
use Local\CrmAssignCascade\Orm\LogTable;

/**
 * Формирует итоговый отчет из очереди/журнала и отправляет его инициатору.
 *
 * Если уведомление не было принято IM, NOTIFIED_AT остается пустым и следующий
 * cron-tick повторит доставку. Realtime Pull дополняет запись в штатном колокольчике.
 */
final class NotificationService
{
    public static function flushPending(): int
    {
        if (!Loader::includeModule('im') || !class_exists('CIMNotify') || !defined('IM_NOTIFY_SYSTEM')) {
            return 0;
        }

        $sent = 0;
        $jobs = JobTable::getList([
            'filter' => [
                '@STATUS' => [JobTable::STATUS_DONE, JobTable::STATUS_DONE_WITH_ERRORS],
                '=NOTIFIED_AT' => null,
            ],
            'order' => ['ID' => 'ASC'],
            'limit' => 20,
        ]);

        while ($job = $jobs->fetch()) {
            if (self::send($job)) {
                $sent++;
            }
        }

        return $sent;
    }

    public static function buildReport(array $job): array
    {
        $jobId = (int)$job['ID'];
        $stats = [];
        foreach ([
            LogTable::KIND_COMPANY,
            LogTable::KIND_CONTACT,
            LogTable::KIND_DEAL,
            LogTable::KIND_DYNAMIC,
            LogTable::KIND_INVOICE,
        ] as $kind) {
            $stats[$kind] = ['total' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];
        }

        $connection = Application::getConnection();
        $table = LogTable::getTableName();
        $result = $connection->query(
            "SELECT ENTITY_KIND, STATUS, COUNT(*) CNT FROM {$table} WHERE JOB_ID={$jobId} GROUP BY ENTITY_KIND, STATUS"
        );
        while ($row = $result->fetch()) {
            $kind = (string)$row['ENTITY_KIND'];
            if (!isset($stats[$kind])) {
                continue;
            }
            $count = (int)$row['CNT'];
            $stats[$kind]['total'] += $count;
            if ((string)$row['STATUS'] === LogTable::STATUS_SUCCESS) {
                $stats[$kind]['updated'] += $count;
            } elseif ((string)$row['STATUS'] === LogTable::STATUS_SKIPPED) {
                $stats[$kind]['skipped'] += $count;
            } elseif ((string)$row['STATUS'] === LogTable::STATUS_ERROR) {
                $stats[$kind]['errors'] += $count;
            }
        }

        $stats[LogTable::KIND_COMPANY]['total'] = max(
            (int)$job['TOTAL_COMPANIES'],
            $stats[LogTable::KIND_COMPANY]['total']
        );

        $failedCompanies = JobCompanyTable::getCount([
            '=JOB_ID' => $jobId,
            '=STATUS' => JobCompanyTable::STATUS_FAILED,
        ]);

        $applyTypes = JobService::normalizeApplyTypes(JobService::jsonDecode((string)$job['APPLY_TYPES']));
        $selectedKinds = [];
        $map = [
            'contacts' => LogTable::KIND_CONTACT,
            'deals' => LogTable::KIND_DEAL,
            'dynamics' => LogTable::KIND_DYNAMIC,
            'invoices' => LogTable::KIND_INVOICE,
        ];
        foreach ($applyTypes as $type) {
            if (isset($map[$type])) {
                $selectedKinds[] = $map[$type];
            }
        }

        return [
            'companies' => $stats[LogTable::KIND_COMPANY],
            'failedCompanyTasks' => $failedCompanies,
            'relatedSelected' => $selectedKinds !== [],
            'related' => array_intersect_key($stats, array_fill_keys($selectedKinds, true)),
        ];
    }

    private static function send(array $job): bool
    {
        $userId = (int)$job['CREATED_BY'];
        if ($userId <= 0) {
            return false;
        }

        try {
            $message = self::buildMessage($job, true);
            $plain = self::buildMessage($job, false);
            $id = \CIMNotify::Add([
                'TO_USER_ID' => $userId,
                'FROM_USER_ID' => 0,
                'NOTIFY_TYPE' => IM_NOTIFY_SYSTEM,
                'NOTIFY_MODULE' => 'local.crmassigncascade',
                'NOTIFY_EVENT' => 'job_finished',
                'NOTIFY_TAG' => 'LOCAL_CRM_ASSIGN_CASCADE|JOB|' . (int)$job['ID'],
                'NOTIFY_TITLE' => 'Смена ответственного завершена',
                'NOTIFY_MESSAGE' => $message,
                // PUSH=Y нужен для realtime Pull: без него запись останется в колокольчике, но balloon может не появиться сразу.
                'PUSH' => 'Y',
                'PUSH_MESSAGE' => $plain,
                'PUSH_IMPORTANT' => 'N',
            ]);

            if (!$id) {
                return false;
            }

            self::flushRealtimeDelivery();

            JobTable::update((int)$job['ID'], [
                'NOTIFIED_AT' => new DateTime(),
                'UPDATED_AT' => new DateTime(),
            ]);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * В web Pull обычно отправляется в epilogue страницы. Cron работает из CLI,
     * поэтому после CIMNotify::Add() принудительно отправляем уже подготовленное Pull-событие.
     */
    private static function flushRealtimeDelivery(): void
    {
        try {
            if (
                Loader::includeModule('pull')
                && class_exists('\Bitrix\Pull\Event')
                && method_exists('\Bitrix\Pull\Event', 'send')
            ) {
                \Bitrix\Pull\Event::send();
            }
        } catch (\Throwable $e) {
            // Запись уже сохранена в IM; временная ошибка Pull не должна создавать дубль уведомления.
        }
    }

    private static function buildMessage(array $job, bool $bbCode): string
    {
        $report = self::buildReport($job);
        $hasErrors = (string)$job['STATUS'] === JobTable::STATUS_DONE_WITH_ERRORS;
        $lines = [];
        $lines[] = $hasErrors ? 'Смена ответственного завершена с ошибками' : 'Смена ответственного завершена';
        $lines[] = self::formatLine('Компании', $report['companies']);

        if ((int)$report['failedCompanyTasks'] > 0) {
            $lines[] = 'Ошибок обработки компаний: ' . (int)$report['failedCompanyTasks'] . '.';
        }

        if (!$report['relatedSelected']) {
            $lines[] = 'Связанные элементы не выбраны.';
        } else {
            $lines[] = 'Связанные элементы:';
            foreach ($report['related'] as $kind => $stats) {
                $lines[] = self::formatLine(self::label($kind), $stats);
            }
        }

        if (!$bbCode) {
            return implode("\n", $lines);
        }

        $lines[0] = '[b]' . $lines[0] . '[/b]';
        if ($report['relatedSelected']) {
            foreach ($lines as $i => $line) {
                if ($line === 'Связанные элементы:') {
                    $lines[$i] = '[b]Связанные элементы[/b]';
                }
            }
        }
        return implode('[br]', $lines);
    }

    private static function formatLine(string $label, array $stats): string
    {
        $total = (int)$stats['total'];
        if ($total <= 0) {
            return $label . ': не найдено.';
        }

        $line = $label . ': обновлено ' . (int)$stats['updated'] . ' из ' . $total;
        $tail = [];
        if ((int)$stats['skipped'] > 0) {
            $tail[] = 'пропущено ' . (int)$stats['skipped'];
        }
        if ((int)$stats['errors'] > 0) {
            $tail[] = 'ошибок ' . (int)$stats['errors'];
        }
        if ($tail !== []) {
            $line .= ', ' . implode(', ', $tail);
        }
        return $line . '.';
    }

    private static function label(string $kind): string
    {
        switch ($kind) {
            case LogTable::KIND_CONTACT:
                return 'Контакты';
            case LogTable::KIND_DEAL:
                return 'Сделки';
            case LogTable::KIND_DYNAMIC:
                return 'Смарт-процессы';
            case LogTable::KIND_INVOICE:
                return 'Счета';
            default:
                return 'Элементы CRM';
        }
    }
}
