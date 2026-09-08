<?php

declare(strict_types=1);

// Единая HTTP-точка модуля: только короткие create/preview/status/cancel.
// Тяжелая CRM-обработка здесь принципиально не выполняется — ее делает cron.
define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('PUBLIC_AJAX_MODE', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;
use Local\CrmAssignCascade\Service\JobService;

global $APPLICATION;
if (is_object($APPLICATION) && method_exists($APPLICATION, 'RestartBuffer')) {
    $APPLICATION->RestartBuffer();
}

header('Content-Type: application/json; charset=UTF-8');

$respond = static function (string $status, $data = null, array $errors = []): void {
    echo json_encode(
        ['status' => $status, 'data' => $data, 'errors' => $errors],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    die();
};

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        $respond('error', null, [['message' => 'Разрешён только POST.', 'code' => 'method_not_allowed']]);
    }
    if (!function_exists('check_bitrix_sessid') || !check_bitrix_sessid()) {
        $respond('error', null, [['message' => 'Некорректная сессия.', 'code' => 'invalid_csrf']]);
    }

    global $USER;
    if (!is_object($USER) || !$USER->IsAuthorized() || !$USER->IsAdmin()) {
        $respond('error', null, [['message' => 'Действие доступно только администраторам портала.', 'code' => 'access_denied']]);
    }
    if (!Loader::includeModule('local.crmassigncascade')) {
        $respond('error', null, [['message' => 'Модуль local.crmassigncascade не установлен.', 'code' => 'module_not_loaded']]);
    }

    $action = trim((string)($_POST['action'] ?? ''));
    $payload = json_decode((string)($_POST['payload'] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);
    $payload = is_array($payload) ? $payload : [];
    $userId = (int)$USER->GetID();

    switch ($action) {
        case 'create':
            $jobId = JobService::create(
                $userId,
                (int)($payload['contractVersion'] ?? 0),
                (string)($payload['requestUid'] ?? ''),
                is_array($payload['companyIds'] ?? null) ? $payload['companyIds'] : [],
                (string)($payload['selectionMode'] ?? ''),
                (int)($payload['expectedCompanyCount'] ?? 0),
                (int)($payload['assignedById'] ?? 0),
                is_array($payload['observerIds'] ?? null) ? $payload['observerIds'] : [],
                (string)($payload['observerMode'] ?? 'replace'),
                is_array($payload['applyTypes'] ?? null) ? $payload['applyTypes'] : [],
                is_array($payload['filter'] ?? null) ? $payload['filter'] : [],
                (string)($payload['filterId'] ?? '')
            );
            $status = JobService::getStatus($jobId);
            $respond('success', [
                'jobId' => $jobId,
                'totalCompanies' => (int)$status['totalCompanies'],
                'selectionMode' => (string)$status['selectionMode'],
                'applyTypes' => (array)$status['applyTypes'],
            ]);
            break;

        case 'preview':
            $respond('success', JobService::previewSelection(
                (int)($payload['contractVersion'] ?? 0),
                is_array($payload['companyIds'] ?? null) ? $payload['companyIds'] : [],
                (string)($payload['selectionMode'] ?? ''),
                is_array($payload['filter'] ?? null) ? $payload['filter'] : [],
                (string)($payload['filterId'] ?? '')
            ));
            break;

        case 'status':
            $respond('success', JobService::getStatus((int)($payload['jobId'] ?? 0)));
            break;

        case 'cancel':
            $respond('success', JobService::cancel((int)($payload['jobId'] ?? 0)));
            break;

        default:
            $respond('error', null, [['message' => 'Неизвестное действие.', 'code' => 'unknown_action']]);
    }
} catch (Throwable $e) {
    $respond('error', null, [['message' => $e->getMessage(), 'code' => 'execution_error']]);
}
