<?php

declare(strict_types=1);

use Bitrix\Main\Loader;

if (!Loader::includeModule('crm')) {
    return false;
}

Loader::registerNamespace('Local\\CrmAssignCascade', __DIR__ . '/lib');

return true;
