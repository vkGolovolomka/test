<?php

declare(strict_types=1);

namespace Local\CrmAssignCascade\Service;

use Bitrix\Crm\Item;
use Bitrix\Crm\PhaseSemantics;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Context;
use Bitrix\Crm\Service\Factory;

/**
 * Единая точка работы со штатным CRM D7 API.
 *
 * Здесь собраны Factory/Item/UpdateOperation и совместимость разных коробочных
 * сборок. Кэш публикуется только после успешного discovery, чтобы временная ошибка
 * одного СП не превратила весь cron-tick в «пустой» список смарт-процессов.
 */
final class CrmService
{
    private Container $container;
    /** @var array<int, Factory> */
    private array $factories = [];
    /** @var array<int, Factory>|null */
    private ?array $dynamicFactories = null;
    /** @var array<int, array<string,mixed>>|null */
    private ?array $dynamicDiagnostics = null;

    public function __construct()
    {
        $this->container = Container::getInstance();
    }

    public static function assignedFieldName(): string
    {
        return defined(Item::class . '::FIELD_NAME_ASSIGNED')
            ? (string)constant(Item::class . '::FIELD_NAME_ASSIGNED')
            : 'ASSIGNED_BY_ID';
    }

    public static function observersFieldName(): string
    {
        return defined(Item::class . '::FIELD_NAME_OBSERVERS')
            ? (string)constant(Item::class . '::FIELD_NAME_OBSERVERS')
            : 'OBSERVER_IDS';
    }

    public static function titleFieldName(): string
    {
        return defined(Item::class . '::FIELD_NAME_TITLE')
            ? (string)constant(Item::class . '::FIELD_NAME_TITLE')
            : 'TITLE';
    }

    public static function stageFieldName(): string
    {
        return defined(Item::class . '::FIELD_NAME_STAGE_ID')
            ? (string)constant(Item::class . '::FIELD_NAME_STAGE_ID')
            : 'STAGE_ID';
    }

    public function factory(int $entityTypeId): ?Factory
    {
        if (isset($this->factories[$entityTypeId])) {
            return $this->factories[$entityTypeId];
        }

        // null не кешируем: Factory может стать доступна позже в том же CLI-процессе.
        $factory = $this->container->getFactory($entityTypeId);
        if ($factory instanceof Factory) {
            $this->factories[$entityTypeId] = $factory;
            return $factory;
        }

        return null;
    }

    /**
     * Возвращает реальные типы смарт-процессов текущей коробки.
     *
     * Сначала используется DynamicTypesMap, затем D7-таблица типов как fallback:
     * на некоторых коробках карта в CLI возвращает пустой список.
     *
     * @return array<int, array{entityTypeId:int,title:string,source:string}>
     */
    public function dynamicTypes(): array
    {
        if ($this->dynamicDiagnostics !== null) {
            return $this->dynamicDiagnostics;
        }

        $types = [];
        $mapCount = 0;
        $tableCount = 0;

        if (method_exists($this->container, 'getDynamicTypesMap')) {
            try {
                $map = $this->container->getDynamicTypesMap();
                if (is_object($map) && method_exists($map, 'load') && method_exists($map, 'getTypes')) {
                    foreach ($map->load(['isLoadStages' => false, 'isLoadCategories' => false])->getTypes() as $type) {
                        if (!is_object($type) || !method_exists($type, 'getEntityTypeId')) {
                            continue;
                        }
                        $entityTypeId = (int)$type->getEntityTypeId();
                        if ($entityTypeId <= 0 || (defined('CCrmOwnerType::SmartInvoice') && $entityTypeId === \CCrmOwnerType::SmartInvoice)) {
                            continue;
                        }
                        $title = method_exists($type, 'getTitle') ? trim((string)$type->getTitle()) : '';
                        $types[$entityTypeId] = [
                            'entityTypeId' => $entityTypeId,
                            'title' => $title !== '' ? $title : ('Dynamic ' . $entityTypeId),
                            'source' => 'map',
                        ];
                        $mapCount++;
                    }
                }
            } catch (\Throwable $ignored) {
                // Ошибка карты не мешает независимому fallback через D7-таблицу типов.
            }
        }

        try {
            $dataClass = null;
            if (method_exists($this->container, 'getDynamicTypeDataClass')) {
                $candidate = $this->container->getDynamicTypeDataClass();
                if (is_string($candidate) && $candidate !== '' && class_exists($candidate) && method_exists($candidate, 'getList')) {
                    $dataClass = $candidate;
                }
            }
            if ($dataClass === null && class_exists('\Bitrix\Crm\Model\Dynamic\TypeTable')) {
                $dataClass = '\Bitrix\Crm\Model\Dynamic\TypeTable';
            }

            if ($dataClass !== null) {
                $rows = $dataClass::getList([
                    'select' => ['ENTITY_TYPE_ID', 'TITLE'],
                    'order' => ['ENTITY_TYPE_ID' => 'ASC'],
                ]);
                while ($row = $rows->fetch()) {
                    $entityTypeId = (int)($row['ENTITY_TYPE_ID'] ?? 0);
                    if ($entityTypeId <= 0 || (defined('CCrmOwnerType::SmartInvoice') && $entityTypeId === \CCrmOwnerType::SmartInvoice)) {
                        continue;
                    }
                    $title = trim((string)($row['TITLE'] ?? ''));
                    if (!isset($types[$entityTypeId])) {
                        $types[$entityTypeId] = [
                            'entityTypeId' => $entityTypeId,
                            'title' => $title !== '' ? $title : ('Dynamic ' . $entityTypeId),
                            'source' => 'table',
                        ];
                    } elseif ($title !== '' && strpos((string)$types[$entityTypeId]['title'], 'Dynamic ') === 0) {
                        $types[$entityTypeId]['title'] = $title;
                    }
                    $tableCount++;
                }
            }
        } catch (\Throwable $e) {
            if ($types === []) {
                throw new \RuntimeException('Не удалось прочитать список смарт-процессов через D7: ' . $e->getMessage(), 0, $e);
            }
        }

        ksort($types, SORT_NUMERIC);
        foreach ($types as &$type) {
            $type['mapCount'] = $mapCount;
            $type['tableCount'] = $tableCount;
        }
        unset($type);

        $this->dynamicDiagnostics = $types;
        return $types;
    }

    /** @return array<int, Factory> */
    public function dynamicFactories(): array
    {
        if ($this->dynamicFactories !== null) {
            return $this->dynamicFactories;
        }

        $factories = [];
        foreach ($this->dynamicTypes() as $entityTypeId => $type) {
            $factory = $this->factory((int)$entityTypeId);
            if ($factory instanceof Factory) {
                $factories[(int)$entityTypeId] = $factory;
            }
        }
        $this->dynamicFactories = $factories;
        return $factories;
    }

    /** @return array<int, array<string,mixed>> */
    public function dynamicDiagnostics(): array
    {
        $diagnostics = [];
        foreach ($this->dynamicTypes() as $entityTypeId => $type) {
            $factory = $this->factory((int)$entityTypeId);
            $diagnostics[(int)$entityTypeId] = [
                'entityTypeId' => (int)$entityTypeId,
                'title' => (string)($type['title'] ?? ('Dynamic ' . $entityTypeId)),
                'source' => (string)($type['source'] ?? ''),
                'factory' => $factory ? get_class($factory) : '',
                'clientEnabled' => $factory ? $this->clientEnabled($factory) : false,
                'companyField' => $factory ? $this->companyFieldName($factory) : '',
                'eligible' => $factory instanceof Factory,
                'problem' => !$factory instanceof Factory,
                'error' => $factory instanceof Factory ? '' : 'Factory недоступна',
                'mapCount' => (int)($type['mapCount'] ?? 0),
                'tableCount' => (int)($type['tableCount'] ?? 0),
            ];
        }
        return $diagnostics;
    }

    /** @return array<int, string> */
    public function dynamicDiscoveryErrors(): array
    {
        $errors = [];
        foreach ($this->dynamicDiagnostics() as $entityTypeId => $diag) {
            if (!empty($diag['problem']) && trim((string)($diag['error'] ?? '')) !== '') {
                $errors[(int)$entityTypeId] = (string)$diag['error'];
            }
        }
        return $errors;
    }

    /**
     * Возвращает элементы смарт-процессов, реально связанные с компанией.
     *
     * Основной путь — RelationManager, то есть те связи, которые сама CRM показывает
     * во вкладке «Связи». Скан по COMPANY_ID оставлен только как fallback для старых коробок.
     *
     * @return array<int, array{entityTypeId:int,entityId:int,source:string}>
     */
    public function relatedDynamicItems(int $companyId): array
    {
        if ($companyId <= 0) {
            return [];
        }

        $result = [];
        $knownTypes = array_fill_keys(array_keys($this->dynamicTypes()), true);

        if (method_exists($this->container, 'getRelationManager') && class_exists('\Bitrix\Crm\ItemIdentifier')) {
            try {
                $manager = $this->container->getRelationManager();
                if (is_object($manager) && method_exists($manager, 'getElements')) {
                    $company = new \Bitrix\Crm\ItemIdentifier(\CCrmOwnerType::Company, $companyId);
                    foreach ($manager->getElements($company) as $identifier) {
                        if (!is_object($identifier) || !method_exists($identifier, 'getEntityTypeId') || !method_exists($identifier, 'getEntityId')) {
                            continue;
                        }
                        $entityTypeId = (int)$identifier->getEntityTypeId();
                        $entityId = (int)$identifier->getEntityId();
                        if ($entityId <= 0 || !$this->isRealDynamicType($entityTypeId, true, $knownTypes)) {
                            continue;
                        }
                        $result[$entityTypeId . ':' . $entityId] = [
                            'entityTypeId' => $entityTypeId,
                            'entityId' => $entityId,
                            'source' => 'relation',
                        ];
                    }
                }
            } catch (\Throwable $ignored) {
                // При ошибке RelationManager пробуем совместимый поиск по полю компании ниже.
            }
        }

        // Fallback для старых сборок: в CLI RelationManager может отдавать не все связи,
        // тогда используем стандартное поле компании самого СП.
        foreach ($this->dynamicFactories() as $entityTypeId => $factory) {
            if (!$this->clientEnabled($factory)) {
                continue;
            }
            try {
                $field = $this->companyFieldName($factory);
                $lastId = 0;
                do {
                    $items = $factory->getItems([
                        'select' => ['ID'],
                        'filter' => ['=' . $field => $companyId, '>ID' => $lastId],
                        'order' => ['ID' => 'ASC'],
                        'limit' => 100,
                    ]);
                    foreach ($items as $item) {
                        $entityId = (int)$item->getId();
                        $lastId = max($lastId, $entityId);
                        if ($entityId > 0) {
                            $key = (int)$entityTypeId . ':' . $entityId;
                            if (!isset($result[$key])) {
                                $result[$key] = [
                                    'entityTypeId' => (int)$entityTypeId,
                                    'entityId' => $entityId,
                                    'source' => 'company-field',
                                ];
                            }
                        }
                    }
                } while (count($items) === 100);
            } catch (\Throwable $ignored) {
            }
        }

        uasort($result, static function (array $a, array $b): int {
            return [$a['entityTypeId'], $a['entityId']] <=> [$b['entityTypeId'], $b['entityId']];
        });
        return array_values($result);
    }

    /** @param array<int,bool> $knownTypes */
    private function isRealDynamicType(int $entityTypeId, bool $allowKnownFallback = true, array $knownTypes = []): bool
    {
        if ($entityTypeId <= 0) {
            return false;
        }
        if (defined('CCrmOwnerType::SmartInvoice') && $entityTypeId === \CCrmOwnerType::SmartInvoice) {
            return false;
        }
        if (method_exists('\CCrmOwnerType', 'isPossibleDynamicTypeId')) {
            try {
                if ((bool)\CCrmOwnerType::isPossibleDynamicTypeId($entityTypeId)) {
                    return true;
                }
            } catch (\Throwable $ignored) {
            }
        }
        return $allowKnownFallback && isset($knownTypes[$entityTypeId]);
    }

    public function hasField(Factory $factory, string $fieldName): bool
    {
        if (method_exists($factory, 'isFieldExists')) {
            try {
                if ((bool)$factory->isFieldExists($fieldName)) {
                    return true;
                }
            } catch (\Throwable $ignored) {
            }
        }

        if (method_exists($factory, 'getFieldsCollection')) {
            try {
                foreach ($factory->getFieldsCollection() as $field) {
                    if (is_object($field) && method_exists($field, 'getName')
                        && strcasecmp((string)$field->getName(), $fieldName) === 0) {
                        return true;
                    }
                }
            } catch (\Throwable $ignored) {
            }
        }

        if (method_exists($factory, 'getFieldsInfo')) {
            try {
                foreach (array_keys((array)$factory->getFieldsInfo()) as $name) {
                    if (strcasecmp((string)$name, $fieldName) === 0) {
                        return true;
                    }
                }
            } catch (\Throwable $ignored) {
            }
        }

        return false;
    }

    public function observersEnabled(Factory $factory, ?Item $item = null): bool
    {
        if (method_exists($factory, 'isObserversEnabled')) {
            try {
                if ((bool)$factory->isObserversEnabled()) {
                    return true;
                }
            } catch (\Throwable $ignored) {
            }
        }
        if ($item !== null && method_exists($item, 'hasField') && $item->hasField(self::observersFieldName())) {
            return true;
        }

        return $this->hasField($factory, self::observersFieldName());
    }

    public function clientEnabled(Factory $factory): bool
    {
        if (method_exists($factory, 'isClientEnabled')) {
            try {
                if ((bool)$factory->isClientEnabled()) {
                    return true;
                }
            } catch (\Throwable $ignored) {
            }
        }

        // Некоторые коробки отдают реальное COMPANY_ID, даже если capability API
        // convenience capability helper is unreliable. The field is sufficient
        // Для этого модуля достаточно проверить возможность стандартной связи с компанией.
        return $this->hasField($factory, 'COMPANY_ID');
    }

    /** Разрешает фактическое имя поля компании через map Factory, если тип его переопределяет. */
    public function companyFieldName(Factory $factory): string
    {
        if (method_exists($factory, 'getEntityFieldNameByMap')) {
            try {
                $name = trim((string)$factory->getEntityFieldNameByMap('COMPANY_ID'));
                if ($name !== '') {
                    return $name;
                }
            } catch (\Throwable $ignored) {
            }
        }

        return 'COMPANY_ID';
    }

    public function stagesSupported(Factory $factory): bool
    {
        if (method_exists($factory, 'isStagesEnabled')) {
            try {
                return (bool)$factory->isStagesEnabled();
            } catch (\Throwable $ignored) {
            }
        }
        if (method_exists($factory, 'isStagesSupported')) {
            try {
                return (bool)$factory->isStagesSupported();
            } catch (\Throwable $ignored) {
            }
        }

        return $this->hasField($factory, self::stageFieldName());
    }

    public function isFinal(Factory $factory, Item $item): bool
    {
        if (!$this->stagesSupported($factory) || !$item->hasField(self::stageFieldName())) {
            return false;
        }
        if (!method_exists($factory, 'getStage')) {
            throw new \RuntimeException('Безопасно определить финальную стадию на этой версии CRM нельзя.');
        }

        $stageId = (string)$item->getStageId();
        if ($stageId === '') {
            return false;
        }

        $stage = $factory->getStage($stageId);
        return $stage ? PhaseSemantics::isFinal((string)$stage->getSemantics()) : false;
    }

    public function targetState(
        Factory $factory,
        Item $item,
        int $assignedById,
        array $observerIds,
        string $observerMode
    ): array {
        $hasAssigned = $item->hasField(self::assignedFieldName());
        $hasObservers = $this->observersEnabled($factory, $item)
            && $item->hasField(self::observersFieldName());

        $oldAssigned = $hasAssigned ? (int)$item->getAssignedById() : null;
        $newAssigned = $hasAssigned ? $assignedById : $oldAssigned;
        $oldObservers = $hasObservers ? self::normalizeIds((array)$item->getObservers()) : [];
        $newObservers = $oldObservers;

        if ($hasObservers) {
            $newObservers = $observerMode === 'add'
                ? self::normalizeIds(array_merge($oldObservers, $observerIds))
                : self::normalizeIds($observerIds);
        }

        return [
            'hasAssigned' => $hasAssigned,
            'hasObservers' => $hasObservers,
            'oldAssigned' => $oldAssigned,
            'newAssigned' => $newAssigned,
            'oldObservers' => $oldObservers,
            'newObservers' => $newObservers,
            'matches' => (!$hasAssigned || $oldAssigned === $newAssigned)
                && (!$hasObservers || $oldObservers === $newObservers),
            'title' => $item->hasField(self::titleFieldName()) ? (string)$item->getTitle() : '',
        ];
    }

    /**
     * Применяет изменение через штатный CRM lifecycle.
     * При наличии API явно включаются история, after-save, роботы и автозапуск БП.
     */
    public function update(Factory $factory, Item $item, array $state, int $userId)
    {
        if (!method_exists($factory, 'getUpdateOperation')) {
            throw new \RuntimeException('Эта версия CRM не поддерживает D7 Factory::getUpdateOperation().');
        }

        if (!empty($state['hasAssigned'])) {
            $item->setAssignedById((int)$state['newAssigned']);
        }
        if (!empty($state['hasObservers'])) {
            $item->setObservers((array)$state['newObservers']);
        }

        $context = (new Context())->setUserId($userId);
        if (method_exists($context, 'setScope') && defined(Context::class . '::SCOPE_MANUAL')) {
            $context->setScope((string)constant(Context::class . '::SCOPE_MANUAL'));
        }

        $operation = $factory->getUpdateOperation($item, $context);
        foreach ([
            'enableFieldProcession',
            'enableSaveToHistory',
            'enableBeforeSaveActions',
            'enableAfterSaveActions',
            'enableAutomation',
            'enableBizProc',
            'enableCheckWorkflows',
        ] as $method) {
            if (method_exists($operation, $method)) {
                $operation->{$method}();
            }
        }

        return $operation->launch();
    }

    private static function normalizeIds(array $ids): array
    {
        $ids = array_map('intval', $ids);
        $ids = array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }
}
