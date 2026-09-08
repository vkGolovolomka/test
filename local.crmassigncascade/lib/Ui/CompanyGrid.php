<?php

declare(strict_types=1);

namespace Local\CrmAssignCascade\Ui;

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\Page\Asset;
use Bitrix\Main\UI\Extension;

/**
 * Точка подключения к штатному списку компаний CRM.
 *
 * Компонент crm.company.list не копируется и не переопределяется. Для администратора
 * подключается только JS-адаптер, который расширяет существующий main.ui.grid ActionsPanel.
 */
final class CompanyGrid
{
    public static function onBeforeProlog(): void
    {
        global $USER;

        if (!is_object($USER) || !$USER->IsAdmin()) {
            return;
        }

        if (!self::isCompanyListRequest()) {
            return;
        }

        if (!Loader::includeModule('ui')) {
            return;
        }

        Extension::load([
            'main.core',
            'main.popup',
            'main.ui.grid',
            'main.ui.filter',
            'ui.notification',
            'ui.entity-selector',
        ]);

        // Загружаем JS прямо из модуля и добавляем filemtime в URL. Это исключает
        // рассинхронизацию между /local/modules и ранее скопированным /local/js после upgrade.
        $absoluteJs = dirname(__DIR__, 2) . '/js/company-list.js';
        if (!is_file($absoluteJs)) {
            return;
        }
        $version = (string)filemtime($absoluteJs);
        Asset::getInstance()->addJs(
            '/local/modules/local.crmassigncascade/js/company-list.js?v=' . rawurlencode($version)
        );
    }

    private static function isCompanyListRequest(): bool
    {
        $request = Application::getInstance()->getContext()->getRequest();
        $uri = (string)$request->getRequestUri();
        $path = (string)(parse_url($uri, PHP_URL_PATH) ?: '');

        $path = '/' . ltrim($path, '/');
        $siteDir = defined('SITE_DIR') ? '/' . trim((string)constant('SITE_DIR'), '/') : '';
        $base = rtrim($siteDir, '/') . '/crm/company';

        $allowed = [
            $base,
            $base . '/',
            $base . '/index.php',
            $base . '/list',
            $base . '/list/',
        ];
        if (in_array($path, $allowed, true)) {
            return true;
        }

        return (bool)preg_match('~/(?:crm/company)/(?:index\.php|list/?)?$~i', $path);
    }
}
