<?php

declare(strict_types=1);

use Bitrix\Main\Loader;
use Local\CrmAssignCascade\Service\WorkerService;

$_SERVER['DOCUMENT_ROOT'] = (string)($_SERVER['DOCUMENT_ROOT'] ?? '') ?: dirname(__DIR__, 4);
$documentRoot = rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/');

if ($documentRoot === '' || !is_file($documentRoot . '/bitrix/modules/main/include/prolog_before.php')) {
    fwrite(STDERR, "Bitrix document root not found. Set DOCUMENT_ROOT for cron.\n");
    exit(2);
}

define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('BX_CRONTAB', true);

require $documentRoot . '/bitrix/modules/main/include/prolog_before.php';

if (!Loader::includeModule('local.crmassigncascade')) {
    fwrite(STDERR, "Module local.crmassigncascade is not installed.\n");
    exit(3);
}

// Один короткий CLI consumer на портал; при аварии ОС сама освобождает flock.
$lockPath = sys_get_temp_dir() . '/local.crmassigncascade.' . md5($documentRoot) . '.lock';
$lockHandle = fopen($lockPath, 'c');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    exit(0);
}

try {
    $result = WorkerService::run(25, 50);
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
} finally {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}
