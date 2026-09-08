<?php

declare(strict_types=1);

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Local\CrmAssignCascade\Orm\JobCompanyTable;
use Local\CrmAssignCascade\Orm\JobTable;
use Local\CrmAssignCascade\Orm\LogTable;
use Local\CrmAssignCascade\Service\CrmService;
use Local\CrmAssignCascade\Service\JobService;

$_SERVER['DOCUMENT_ROOT'] = (string)($_SERVER['DOCUMENT_ROOT'] ?? '') ?: dirname(__DIR__, 4);
$root = rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/');
if (!is_file($root . '/bitrix/modules/main/include/prolog_before.php')) {
    fwrite(STDERR, "Bitrix document root not found. Set DOCUMENT_ROOT.\n");
    exit(2);
}

define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
require $root . '/bitrix/modules/main/include/prolog_before.php';

$failed = false;
$check = static function (string $name, bool $ok, string $detail = '') use (&$failed): void {
    echo ($ok ? '[OK]   ' : '[FAIL] ') . $name . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
    if (!$ok) {
        $failed = true;
    }
};

$companyId = 0;
foreach ($argv ?? [] as $arg) {
    if (strpos((string)$arg, '--company=') === 0) {
        $companyId = max(0, (int)substr((string)$arg, strlen('--company=')));
    }
}

$check('php.version', PHP_VERSION_ID >= 70400, PHP_VERSION);
$check('module.crm', Loader::includeModule('crm'));
$check('module.ui', Loader::includeModule('ui'));
$check('module.local.crmassigncascade', Loader::includeModule('local.crmassigncascade'));

if (Loader::includeModule('local.crmassigncascade')) {
    $connection = Application::getConnection();
    $requirements = [
        JobTable::getTableName() => ['REQUEST_UID', 'APPLY_TYPES', 'NOTIFIED_AT'],
        JobCompanyTable::getTableName() => ['JOB_ID', 'COMPANY_ID', 'AVAILABLE_AT'],
        LogTable::getTableName() => ['JOB_COMPANY_ID', 'ENTITY_KIND', 'RECOVERED'],
    ];
    foreach ($requirements as $table => $columns) {
        $exists = $connection->isTableExists($table);
        $check('db.' . $table, $exists);
        if ($exists) {
            $fields = $connection->getTableFields($table);
            foreach ($columns as $column) {
                $check('db.' . $table . '.' . $column, isset($fields[$column]));
            }
        }
    }

    $crm = new CrmService();
    $factory = $crm->factory(\CCrmOwnerType::Company);
    $check('crm.company.factory', $factory !== null);
    if ($factory) {
        $check('crm.company.assigned', $crm->hasField($factory, CrmService::assignedFieldName()));
        $check('crm.company.observers', $crm->observersEnabled($factory));
        $check('crm.company.updateOperation', method_exists($factory, 'getUpdateOperation'));

        try {
            $item = method_exists($factory, 'createItem') ? $factory->createItem() : null;
            if ($item && method_exists($factory, 'getUpdateOperation')) {
                $operation = $factory->getUpdateOperation($item);
                $check('crm.operation.automation', method_exists($operation, 'enableAutomation'));
                $check('crm.operation.bizproc', method_exists($operation, 'enableBizProc'));
                $check('crm.operation.afterSave', method_exists($operation, 'enableAfterSaveActions'));
            }
        } catch (Throwable $e) {
            echo '[INFO] crm.operation.features — ' . $e->getMessage() . PHP_EOL;
        }
    }

    $container = \Bitrix\Crm\Service\Container::getInstance();
    $check('crm.filterFactory', method_exists($container, 'getFilterFactory'));
    $check('crm.dynamicTypes.map', method_exists($container, 'getDynamicTypesMap'));
    $check('crm.dynamicTypes.table', method_exists($container, 'getDynamicTypeDataClass') || class_exists('\Bitrix\Crm\Model\Dynamic\TypeTable'));
    $check('crm.relationManager', method_exists($container, 'getRelationManager'));

    try {
        $dynamicFactories = $crm->dynamicFactories();
        $diagnostics = $crm->dynamicDiagnostics();
        $mapCount = 0;
        $tableCount = 0;
        foreach ($diagnostics as $diag) {
            $mapCount = max($mapCount, (int)($diag['mapCount'] ?? 0));
            $tableCount = max($tableCount, (int)($diag['tableCount'] ?? 0));
        }
        echo '[INFO] crm.dynamic.map — ' . $mapCount . PHP_EOL;
        echo '[INFO] crm.dynamic.table — ' . $tableCount . PHP_EOL;
        echo '[INFO] crm.dynamic.discovered — ' . count($diagnostics) . PHP_EOL;
        echo '[INFO] crm.dynamic.factories — ' . count($dynamicFactories) . PHP_EOL;

        foreach ($diagnostics as $entityTypeId => $diag) {
            $line = '#' . $entityTypeId . ' ' . (string)($diag['title'] ?? '')
                . '; source=' . ((string)($diag['source'] ?? '') ?: '-')
                . '; factory=' . (!empty($diag['factory']) ? 'Y' : 'N')
                . '; client=' . (!empty($diag['clientEnabled']) ? 'Y' : 'N')
                . '; field=' . ((string)($diag['companyField'] ?? '') ?: '-');
            if (!empty($diag['error'])) {
                $line .= '; ' . (string)$diag['error'];
            }
            echo '[INFO] crm.dynamic.type — ' . $line . PHP_EOL;
        }

        if ($companyId > 0) {
            $related = $crm->relatedDynamicItems($companyId);
            echo '[INFO] crm.dynamic.related(company=' . $companyId . ') — ' . count($related) . PHP_EOL;
            foreach ($related as $item) {
                echo '[INFO] crm.dynamic.related.item — type=' . (int)$item['entityTypeId']
                    . '; id=' . (int)$item['entityId']
                    . '; source=' . (string)$item['source'] . PHP_EOL;
            }
        }
    } catch (Throwable $e) {
        $check('crm.dynamic.discovery', false, $e->getMessage());
    }

    $check('module.im', Loader::includeModule('im'));
    $check('im.systemNotification', class_exists('CIMNotify') && defined('IM_NOTIFY_SYSTEM'));
    $pullLoaded = Loader::includeModule('pull');
    $check('module.pull', $pullLoaded);
    $check('pull.event.send', $pullLoaded && class_exists('\\Bitrix\\Pull\\Event') && method_exists('\\Bitrix\\Pull\\Event', 'send'));

    $endpoint = $root . '/local/tools/local.crmassigncascade.ajax.php';
    $check('ajax.endpoint', is_file($endpoint), $endpoint);

    $js = dirname(__DIR__) . '/js/company-list.js';
    $check('ui.module.js', is_file($js), $js);
    if (is_file($js)) {
        $source = (string)file_get_contents($js);
        $check('ui.contract.version', strpos($source, 'CONTRACT_VERSION = ' . JobService::CLIENT_CONTRACT_VERSION) !== false);
        $check('ui.single.ajax.transport', strpos($source, 'BX.ajax.runAction') === false);
    }

    $legacy = [
        $root . '/local/js/local/crmassigncascade/company-list/company-list.js',
        $root . '/local/js/local.crmassigncascade/company-list/company-list.js',
        $root . '/local/js/local.crmassigncascade/company-grid.js',
    ];
    $legacyPresent = array_values(array_filter($legacy, 'is_file'));
    $check('ui.legacy.runtime.absent', $legacyPresent === [], implode(', ', $legacyPresent));

    // Показываем реальные записи audit, а не только наличие таблицы.
    if ($connection->isTableExists(LogTable::getTableName())) {
        $latestJob = JobTable::getList(['select' => ['ID', 'STATUS', 'APPLY_TYPES'], 'order' => ['ID' => 'DESC'], 'limit' => 1])->fetch();
        if ($latestJob) {
            $jobId = (int)$latestJob['ID'];
            echo '[INFO] latest.job — #' . $jobId . '; status=' . (string)$latestJob['STATUS']
                . '; apply=' . (string)$latestJob['APPLY_TYPES'] . PHP_EOL;
            $result = $connection->query(
                'SELECT ENTITY_KIND, STATUS, COUNT(*) CNT FROM ' . LogTable::getTableName()
                . ' WHERE JOB_ID=' . $jobId . ' GROUP BY ENTITY_KIND, STATUS ORDER BY ENTITY_KIND, STATUS'
            );
            $rows = 0;
            while ($row = $result->fetch()) {
                $rows += (int)$row['CNT'];
                echo '[INFO] latest.log — ' . (string)$row['ENTITY_KIND'] . '/' . (string)$row['STATUS']
                    . '=' . (int)$row['CNT'] . PHP_EOL;
            }
            echo '[INFO] latest.log.total — ' . $rows . PHP_EOL;

            $errors = JobCompanyTable::getList([
                'select' => ['COMPANY_ID', 'STATUS', 'ATTEMPTS', 'LAST_ERROR'],
                'filter' => ['=JOB_ID' => $jobId, '!=LAST_ERROR' => ''],
                'order' => ['UPDATED_AT' => 'DESC', 'ID' => 'DESC'],
                'limit' => 10,
            ]);
            while ($row = $errors->fetch()) {
                echo '[INFO] latest.company.error — company=' . (int)$row['COMPANY_ID']
                    . '; status=' . (string)$row['STATUS']
                    . '; attempts=' . (int)$row['ATTEMPTS']
                    . '; ' . (string)$row['LAST_ERROR'] . PHP_EOL;
            }
        }
    }
}

exit($failed ? 1 : 0);
