<?php

declare(strict_types=1);

use Bitrix\Main\Application;
use Bitrix\Main\EventManager;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use Local\CrmAssignCascade\Orm\JobCompanyTable;
use Local\CrmAssignCascade\Orm\JobTable;
use Local\CrmAssignCascade\Orm\LogTable;
use Local\CrmAssignCascade\Ui\CompanyGrid;

/**
 * Установщик локального модуля.
 *
 */
class local_crmassigncascade extends CModule
{
    public $MODULE_ID = 'local.crmassigncascade';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME = 'CRM: массовая смена ответственного и наблюдателей';
    public $MODULE_DESCRIPTION = 'Массовое обновление CRM в фоне: фиксированный список компаний, очередь cron, журнал изменений и итоговое уведомление.';
    public $PARTNER_NAME = 'Vladislav';
    public $PARTNER_URI = '';

    public function __construct()
    {
        $arModuleVersion = [];
        include __DIR__ . '/version.php';
        $this->MODULE_VERSION = $arModuleVersion['VERSION'] ?? '2.0.4';
        $this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'] ?? '2026-09-08 09:30:00';
    }

    public function DoInstall(): void
    {
        global $APPLICATION;

        if (!Loader::includeModule('crm')) {
            $APPLICATION->ThrowException('Для установки требуется модуль CRM.');
            return;
        }

        $wasInstalled = ModuleManager::isModuleInstalled($this->MODULE_ID);
        try {
            if (!$wasInstalled) {
                ModuleManager::registerModule($this->MODULE_ID);
            }
            if (!Loader::includeModule($this->MODULE_ID)) {
                throw new RuntimeException('Не удалось загрузить модуль после регистрации.');
            }

            $this->prepareModuleDatabase();
            $this->installRuntimeAssets();
            $this->registerRuntimeEvents();
        } catch (Throwable $e) {
            if (!$wasInstalled && ModuleManager::isModuleInstalled($this->MODULE_ID)) {
                try {
                    $this->unregisterRuntimeEvents();
                    $this->removeRuntimeAssets();
                } catch (Throwable $ignored) {
                }
                ModuleManager::unRegisterModule($this->MODULE_ID);
            }
            $APPLICATION->ThrowException($e->getMessage());
        }
    }

    public function DoUninstall(): void
    {
        Loader::includeModule($this->MODULE_ID);
        $this->unregisterRuntimeEvents();
        $this->removeRuntimeAssets();
        $this->dropModuleDatabase();
        ModuleManager::unRegisterModule($this->MODULE_ID);
    }

    /** Создает согласованную схему и индексы модуля, не затрагивая таблицы CRM. */
    private function prepareModuleDatabase(): void
    {
        $connection = Application::getConnection();
        if ($this->hasIncompatibleSchema()) {
            $this->backupCurrentTables();
        }

        foreach ([JobTable::class, JobCompanyTable::class, LogTable::class] as $tableClass) {
            if (!$connection->isTableExists($tableClass::getTableName())) {
                $tableClass::getEntity()->createDbTable();
            }
        }

        $this->createUniqueIndex(JobTable::getTableName(), 'ux_lca_job_request', ['REQUEST_UID']);
        $this->createIndex(JobTable::getTableName(), 'ix_lca_job_notify', ['STATUS', 'NOTIFIED_AT', 'ID']);

        $this->createUniqueIndex(JobCompanyTable::getTableName(), 'ux_lca_job_company', ['JOB_ID', 'COMPANY_ID']);
        $this->createIndex(JobCompanyTable::getTableName(), 'ix_lca_queue_claim', ['STATUS', 'AVAILABLE_AT', 'JOB_ID', 'POSITION', 'ID']);
        $this->createIndex(JobCompanyTable::getTableName(), 'ix_lca_queue_job', ['JOB_ID', 'STATUS', 'ID']);

        $this->createUniqueIndex(LogTable::getTableName(), 'ux_lca_log_entity', ['JOB_ID', 'ENTITY_TYPE_ID', 'ENTITY_ID']);
        $this->createIndex(LogTable::getTableName(), 'ix_lca_log_report', ['JOB_ID', 'ENTITY_KIND', 'STATUS', 'ID']);
        $this->createIndex(LogTable::getTableName(), 'ix_lca_log_company', ['JOB_ID', 'JOB_COMPANY_ID', 'STATUS']);
    }

    private function hasIncompatibleSchema(): bool
    {
        $connection = Application::getConnection();
        $requirements = [
            JobTable::getTableName() => ['REQUEST_UID', 'APPLY_TYPES', 'NOTIFIED_AT'],
            JobCompanyTable::getTableName() => ['JOB_ID', 'COMPANY_ID', 'AVAILABLE_AT'],
            LogTable::getTableName() => ['JOB_COMPANY_ID', 'ENTITY_KIND', 'RECOVERED'],
        ];

        $existing = false;
        foreach ($requirements as $table => $columns) {
            if (!$connection->isTableExists($table)) {
                continue;
            }
            $existing = true;
            $fields = $connection->getTableFields($table);
            foreach ($columns as $column) {
                if (!isset($fields[$column])) {
                    return true;
                }
            }
        }

        // Частично установленную схему безопаснее архивировать и пересоздать целиком.
        if ($existing) {
            foreach (array_keys($requirements) as $table) {
                if (!$connection->isTableExists($table)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function backupCurrentTables(): void
    {
        $connection = Application::getConnection();
        $suffix = date('YmdHis');
        foreach ([LogTable::getTableName(), JobCompanyTable::getTableName(), JobTable::getTableName()] as $table) {
            if (!$connection->isTableExists($table)) {
                continue;
            }

            $base = $table . '_legacy_' . $suffix;
            $backup = $base;
            $i = 0;
            while ($connection->isTableExists($backup)) {
                $backup = $base . '_' . (++$i);
            }
            $connection->renameTable($table, $backup);
        }
    }

    private function dropModuleDatabase(): void
    {
        $connection = Application::getConnection();
        foreach ([LogTable::class, JobCompanyTable::class, JobTable::class] as $tableClass) {
            if ($connection->isTableExists($tableClass::getTableName())) {
                $connection->dropTable($tableClass::getTableName());
            }
        }
        // *_legacy_* backups are intentionally preserved.
    }

    /** Публикует только runtime endpoint; JS загружается непосредственно из каталога модуля. */
    private function installRuntimeAssets(): void
    {
        $root = Application::getDocumentRoot();
        $toolsDir = $root . '/local/tools';
        if (!is_dir($toolsDir) && !mkdir($toolsDir, 0775, true) && !is_dir($toolsDir)) {
            throw new RuntimeException('Не удалось создать каталог ' . $toolsDir);
        }

        if (!copy(__DIR__ . '/tools/local.crmassigncascade.ajax.php', $toolsDir . '/local.crmassigncascade.ajax.php')) {
            throw new RuntimeException('Не удалось установить AJAX endpoint.');
        }

        // Удаляем runtime-копии JS от 1.x: текущая версия загружает один source прямо из модуля.
        foreach ([
            $root . '/local/js/local/crmassigncascade/company-list/company-list.js',
            $root . '/local/js/local/crmassigncascade/company-list/config.php',
            $root . '/local/js/local.crmassigncascade/company-list/company-list.js',
            $root . '/local/js/local.crmassigncascade/company-list/config.php',
            $root . '/local/js/local.crmassigncascade/company-grid.js',
        ] as $legacyFile) {
            if (is_file($legacyFile)) {
                @unlink($legacyFile);
            }
        }
    }

    private function removeRuntimeAssets(): void
    {
        $tool = Application::getDocumentRoot() . '/local/tools/local.crmassigncascade.ajax.php';
        if (is_file($tool)) {
            @unlink($tool);
        }
    }

    /** Подключает UI-адаптер только через штатное событие main.OnBeforeProlog. */
    private function registerRuntimeEvents(): void
    {
        $this->unregisterRuntimeEvents();
        EventManager::getInstance()->registerEventHandler(
            'main',
            'OnBeforeProlog',
            $this->MODULE_ID,
            CompanyGrid::class,
            'onBeforeProlog'
        );
    }

    private function unregisterRuntimeEvents(): void
    {
        EventManager::getInstance()->unRegisterEventHandler(
            'main',
            'OnBeforeProlog',
            $this->MODULE_ID,
            CompanyGrid::class,
            'onBeforeProlog'
        );
    }

    private function createIndex(string $table, string $name, array $columns): void
    {
        $connection = Application::getConnection();
        if (!$connection->isIndexExists($table, $columns)) {
            $connection->createIndex($table, $name, $columns);
        }
    }

    private function createUniqueIndex(string $table, string $name, array $columns): void
    {
        $connection = Application::getConnection();
        if ($connection->isIndexExists($table, $columns)) {
            return;
        }

        $quoted = array_map(
            static fn(string $column): string => $connection->getSqlHelper()->quote($column),
            $columns
        );
        $connection->queryExecute(
            'CREATE UNIQUE INDEX ' . $connection->getSqlHelper()->quote($name)
            . ' ON ' . $connection->getSqlHelper()->quote($table)
            . ' (' . implode(', ', $quoted) . ')'
        );
    }
}
