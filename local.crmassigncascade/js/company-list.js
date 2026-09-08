(function () {
    'use strict';

    // UI-адаптер не меняет CRM сам: он только читает штатный Grid, собирает параметры
    // операции и передает их backend-у для фиксации задания в очереди.

    BX.namespace('Local.CrmAssignCascade');

    const AJAX_URL = '/local/tools/local.crmassigncascade.ajax.php';
    const ACTION_VALUE = 'local_crmassigncascade_assign';
    const CONTRACT_VERSION = 3;
    const forAllIntent = Object.create(null);
    let requestUid = '';

    function runServerAction(actionName, data) {
        return new Promise((resolve, reject) => {
            BX.ajax({
                url: AJAX_URL,
                method: 'POST',
                dataType: 'json',
                timeout: 60,
                data: {
                    sessid: BX.bitrix_sessid(),
                    action: actionName,
                    payload: JSON.stringify(data || {})
                },
                onsuccess: response => {
                    if (response && response.status === 'success') {
                        resolve(response);
                        return;
                    }
                    reject(response || {errors: [{message: 'Ошибка AJAX endpoint.'}]});
                },
                onfailure: () => reject({errors: [{message: 'Не удалось обратиться к модулю.'}]})
            });
        });
    }

    function getInstanceById(id) {
        if (!BX.Main || !BX.Main.gridManager || !id) {
            return null;
        }

        if (typeof BX.Main.gridManager.getInstanceById === 'function') {
            const instance = BX.Main.gridManager.getInstanceById(id);
            if (instance) {
                return instance;
            }
        }

        if (typeof BX.Main.gridManager.getById === 'function') {
            const item = BX.Main.gridManager.getById(id);
            if (item && item.instance) {
                return item.instance;
            }
        }

        return null;
    }

    function findCompanyGrid() {
        const preferred = ['CRM_COMPANY_LIST_V12', 'CRM_COMPANY_LIST'];
        for (const id of preferred) {
            const grid = getInstanceById(id);
            if (grid) {
                return grid;
            }
        }

        const manager = BX.Main && BX.Main.gridManager;
        const data = manager && Array.isArray(manager.data) ? manager.data : [];
        for (const entry of data) {
            const grid = entry && entry.instance ? entry.instance : entry;
            if (isCompanyGrid(grid)) {
                return grid;
            }
        }

        return null;
    }

    function getGridIdentifiers(grid) {
        if (!grid) {
            return [];
        }

        const ids = [];
        ['getId', 'getContainerId'].forEach(method => {
            if (typeof grid[method] !== 'function') {
                return;
            }

            const value = String(grid[method]() || '');
            if (value && !ids.includes(value)) {
                ids.push(value);
            }
        });

        return ids;
    }

    function isCompanyGrid(grid) {
        return getGridIdentifiers(grid).some(id => /^CRM_(?:MY)?COMPANY_LIST(?:_V\d+)?$/i.test(id));
    }

    function getGridId(grid) {
        const ids = getGridIdentifiers(grid);
        const preferred = ids.find(id => /^CRM_(?:MY)?COMPANY_LIST(?:_V\d+)?$/i.test(id));
        return preferred || ids[0] || '';
    }

    function getSelectedIds(grid) {
        if (!grid || typeof grid.getRows !== 'function') {
            return [];
        }

        return (grid.getRows().getSelectedIds() || [])
            .map(Number)
            .filter(id => Number.isInteger(id) && id > 0)
            .filter((id, index, list) => list.indexOf(id) === index);
    }

    function getActionsPanelContext(grid) {
        if (!grid || typeof grid.getActionsPanel !== 'function') {
            return {panel: null, dropdown: null, container: null};
        }

        const panel = grid.getActionsPanel();
        if (!panel) {
            return {panel: null, dropdown: null, container: null};
        }

        const dropdowns = typeof panel.getDropdowns === 'function' ? (panel.getDropdowns() || []) : [];
        const dropdown = dropdowns[0] || null;
        let container = null;

        if (typeof panel.getContainer === 'function') {
            container = panel.getContainer();
        }
        if (!container && dropdown && typeof dropdown.closest === 'function') {
            container = dropdown.closest('.main-grid-control-panel');
        }

        return {panel: panel, dropdown: dropdown, container: container};
    }

    function findForAllCheckbox(grid) {
        // Важно: не ищем checkbox глобально по document. После AJAX main.ui.grid может
        // оставить старые/скрытые controls в DOM. Берём только control из текущей ActionsPanel.
        const context = getActionsPanelContext(grid);
        if (!context.container) {
            return null;
        }

        for (const gridId of getGridIdentifiers(grid)) {
            const name = 'action_all_rows_' + gridId;
            const nodes = context.container.querySelectorAll('input[type="checkbox"][name="' + name.replace(/"/g, '\\"') + '"]');
            for (let i = 0; i < nodes.length; i++) {
                const node = nodes[i];
                if (node && node.isConnected !== false) {
                    return node;
                }
            }
        }

        const candidates = context.container.querySelectorAll('input.main-grid-for-all-checkbox[type="checkbox"]');
        return candidates.length === 1 ? candidates[0] : null;
    }

    function bindForAllTracker(grid) {
        const gridId = getGridId(grid);
        const checkbox = findForAllCheckbox(grid);
        if (!gridId || !checkbox) {
            return null;
        }

        if (!Object.prototype.hasOwnProperty.call(forAllIntent, gridId)) {
            // Fail-safe: само наличие checked в DOM недостаточно. Режим all включается
            // только после реального change-события, которое видел текущий adapter.
            forAllIntent[gridId] = false;
        }

        if (checkbox.dataset.localCrmAssignCascadeBound !== 'Y') {
            checkbox.dataset.localCrmAssignCascadeBound = 'Y';
            checkbox.addEventListener('change', function () {
                forAllIntent[gridId] = checkbox.checked === true;
            });
        }

        return checkbox;
    }

    function resolveSelection(grid) {
        const selectedIds = getSelectedIds(grid);
        const gridId = getGridId(grid);
        const checkbox = bindForAllTracker(grid);

        // Режим all разрешается только при двух одновременно выполненных условиях:
        // 1) пользовательский intent был зафиксирован change-событием текущего checkbox;
        // 2) текущий checkbox реально checked.
        // Если состояние не удаётся определить однозначно, безопасно работаем только с ID.
        const allRows = !!(
            gridId
            && checkbox
            && checkbox.checked === true
            && forAllIntent[gridId] === true
        );

        if (allRows) {
            return {mode: 'all', companyIds: selectedIds};
        }

        return {mode: 'selected', companyIds: selectedIds};
    }

    function getCurrentFilter(gridId) {
        try {
            const filter = BX.Main.filterManager && BX.Main.filterManager.getById(gridId);
            if (filter && typeof filter.getFilterFieldsValues === 'function') {
                return filter.getFilterFieldsValues() || {};
            }
        } catch (e) {
            // Backend сам преобразует raw-фильтр через CRM FilterFactory.
        }

        return {};
    }

    function createRequestUid() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }

        return 'lca-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2) + '-' + Math.random().toString(36).slice(2);
    }

    function createUserSelector(multiple) {
        return new BX.UI.EntitySelector.TagSelector({
            multiple: multiple,
            showAddButton: true,
            showCreateButton: false,
            showTextBox: false,
            addButtonCaption: 'Добавить',
            addButtonCaptionMore: 'Добавить',
            maxHeight: multiple ? 110 : 72,
            tagMaxWidth: 320,
            dialogOptions: {
                context: 'LOCAL_CRM_ASSIGN_CASCADE_USERS',
                enableSearch: true,
                multiple: multiple,
                preload: true,
                entities: [
                    {
                        id: 'user',
                        options: {
                            inviteEmployeeLink: false
                        }
                    },
                    {
                        id: 'department',
                        options: {
                            selectMode: 'usersOnly'
                        }
                    }
                ]
            }
        });
    }

    function getUserIds(selector) {
        const dialog = selector && typeof selector.getDialog === 'function' ? selector.getDialog() : null;
        if (!dialog || typeof dialog.getSelectedItems !== 'function') {
            return [];
        }

        return dialog.getSelectedItems()
            .filter(item => !item.getEntityId || item.getEntityId() === 'user')
            .map(item => Number(item.getId()))
            .filter(id => Number.isInteger(id) && id > 0)
            .filter((id, index, list) => list.indexOf(id) === index);
    }

    function field(label, node) {
        return BX.create('div', {
            style: {marginBottom: '16px'},
            children: [
                BX.create('div', {
                    text: label,
                    style: {fontSize: '13px', fontWeight: '600', marginBottom: '6px', color: '#333'}
                }),
                node
            ]
        });
    }

    function createSelect(name, options) {
        const select = BX.create('select', {
            attrs: {name: name, className: 'ui-ctl-element'},
            children: options.map(option => BX.create('option', {
                attrs: {value: option.value},
                text: option.text
            }))
        });

        return BX.create('div', {
            attrs: {className: 'ui-ctl ui-ctl-after-icon ui-ctl-dropdown ui-ctl-w100'},
            children: [select]
        });
    }

    function createCheckbox(name, text) {
        return BX.create('label', {
            attrs: {className: 'ui-ctl ui-ctl-checkbox'},
            style: {display: 'flex', marginBottom: '7px'},
            children: [
                BX.create('input', {
                    attrs: {
                        type: 'checkbox',
                        name: name,
                        className: 'ui-ctl-element'
                    }
                }),
                BX.create('span', {
                    attrs: {className: 'ui-ctl-label-text'},
                    text: text
                })
            ]
        });
    }

    // Форма использует штатный EntitySelector и перед запуском фиксирует scope выбранных компаний.
    function open() {
        const grid = findCompanyGrid();
        if (!grid) {
            notify('Список компаний не найден.');
            return;
        }

        const selection = resolveSelection(grid);
        const selectedIds = selection.companyIds;
        const selectionMode = selection.mode;
        if (selectionMode === 'selected' && selectedIds.length === 0) {
            notify('Выберите компании.');
            return;
        }

        requestUid = createRequestUid();

        const responsibleHost = BX.create('div');
        const observersHost = BX.create('div');
        const responsibleSelector = createUserSelector(false);
        const observersSelector = createUserSelector(true);
        responsibleSelector.renderTo(responsibleHost);
        observersSelector.renderTo(observersHost);

        const observerMode = createSelect('observerMode', [
            {value: 'replace', text: 'Заменить'},
            {value: 'add', text: 'Добавить'}
        ]);

        const gridId = getGridId(grid);
        let selectionReady = selectionMode === 'selected';
        let expectedCompanyCount = selectionMode === 'selected' ? selectedIds.length : 0;
        const selectionSummary = BX.create('div', {
            text: selectionMode === 'all' ? 'Компаний: …' : 'Компаний: ' + selectedIds.length,
            style: {fontSize: '12px', color: '#828b95', marginBottom: '14px'}
        });

        const form = BX.create('div', {
            style: {padding: '18px 20px 8px', width: '500px'},
            children: [
                selectionSummary,
                field('Ответственный', responsibleHost),
                field('Наблюдатели', observersHost),
                field('Режим наблюдателей', observerMode),
                BX.create('div', {
                    text: 'Связанные элементы',
                    style: {fontSize: '13px', fontWeight: '600', marginBottom: '8px', color: '#333'}
                }),
                createCheckbox('contacts', 'Контакты'),
                createCheckbox('deals', 'Сделки'),
                createCheckbox('dynamics', 'Смарт-процессы'),
                createCheckbox('invoices', 'Счета')
            ]
        });

        let submitting = false;

        const popup = new BX.PopupWindow('local-crmassigncascade-form', null, {
            content: form,
            titleBar: 'Ответственный и наблюдатели',
            closeIcon: true,
            overlay: true,
            autoHide: false,
            buttons: [
                new BX.PopupWindowButton({
                    text: 'Применить',
                    className: 'popup-window-button-accept',
                    events: {
                        click: function () {
                            if (submitting) {
                                return;
                            }
                            if (!selectionReady) {
                                notify('Подождите определения количества компаний.');
                                return;
                            }

                            const assigned = getUserIds(responsibleSelector);
                            const observers = getUserIds(observersSelector);
                            if (assigned.length !== 1) {
                                notify('Выберите ответственного.');
                                return;
                            }
                            if (observers.length === 0) {
                                notify('Выберите наблюдателей.');
                                return;
                            }

                            const applyTypes = ['contacts', 'deals', 'dynamics', 'invoices'].filter(type => {
                                const input = form.querySelector('[name="' + type + '"]');
                                return !!(input && input.checked === true);
                            });

                            submitting = true;

                            runServerAction('create', {
                                    contractVersion: CONTRACT_VERSION,
                                    companyIds: selectedIds,
                                    requestUid: requestUid,
                                    selectionMode: selectionMode,
                                    expectedCompanyCount: expectedCompanyCount,
                                    assignedById: assigned[0],
                                    observerIds: observers,
                                    observerMode: form.querySelector('[name="observerMode"]').value,
                                    applyTypes: applyTypes,
                                    filter: getCurrentFilter(gridId),
                                    filterId: gridId
                            }).then(response => {
                                const data = response.data || {};
                                const actualTypes = Array.isArray(data.applyTypes) ? data.applyTypes.slice().sort() : [];
                                const expectedTypes = applyTypes.slice().sort();
                                const scopeMatches = String(data.selectionMode || '') === selectionMode
                                    && Number(data.totalCompanies || 0) === expectedCompanyCount
                                    && JSON.stringify(actualTypes) === JSON.stringify(expectedTypes);

                                if (!scopeMatches) {
                                    const jobId = Number(data.jobId || 0);
                                    if (jobId > 0) {
                                        runServerAction('cancel', {jobId: jobId});
                                    }
                                    throw {errors: [{message: 'Параметры задания не совпали с выбранными. Операция остановлена.'}]};
                                }

                                popup.close();
                                notify('Задача по смене поставлена в очередь.');
                            }).catch(error => {
                                submitting = false;
                                showError(error);
                            });
                        }
                    }
                }),
                new BX.PopupWindowButtonLink({
                    text: 'Отмена',
                    events: {click: () => popup.close()}
                })
            ]
        });

        popup.show();

        if (selectionMode === 'all') {
            runServerAction('preview', {
                contractVersion: CONTRACT_VERSION,
                companyIds: selectedIds,
                selectionMode: 'all',
                filter: getCurrentFilter(gridId),
                filterId: gridId
            }).then(response => {
                const total = Number(response.data.totalCompanies || 0);
                expectedCompanyCount = total;
                selectionSummary.textContent = 'Компаний: ' + total + ' (все по фильтру)';
                selectionReady = total > 0;
            }).catch(error => {
                selectionSummary.textContent = 'Не удалось определить выборку';
                showError(error);
            });
        }
    }

    /**
     * Расширяет уже отрисованный штатный ActionsPanel через API main.ui.grid.
     * Компонент crm.company.list не копируется, CSS-селекторы для размещения кнопки не используются.
     */
    // Добавляем действие в уже существующий штатный dropdown ActionsPanel, не копируя crm.company.list.
    function mountGroupAction(grid) {
        grid = isCompanyGrid(grid) ? grid : findCompanyGrid();
        if (!grid || typeof grid.getActionsPanel !== 'function') {
            return false;
        }

        const panel = grid.getActionsPanel();
        if (!panel || typeof panel.getDropdowns !== 'function') {
            return false;
        }

        const dropdown = (panel.getDropdowns() || [])[0];
        if (!dropdown) {
            return false;
        }

        bindForAllTracker(grid);

        let items;
        try {
            items = BX.parseJSON(BX.data(dropdown, 'items'));
        } catch (e) {
            return false;
        }

        if (!BX.type.isArray(items)) {
            return false;
        }

        if (items.some(item => item && item.VALUE === ACTION_VALUE)) {
            return true;
        }

        items.push({
            NAME: 'Сменить ответственного и наблюдателей',
            VALUE: ACTION_VALUE,
            ONCHANGE: [
                {
                    ACTION: 'CALLBACK',
                    DATA: [
                        {JS: 'BX.Local.CrmAssignCascade.CompanyList.open()'}
                    ]
                }
            ]
        });

        dropdown.dataset.items = JSON.stringify(items);
        return true;
    }

    function onGridReadyOrUpdated(grid) {
        mountGroupAction(grid);
    }

    function esc(value) {
        return BX.util.htmlspecialchars(String(value == null ? '' : value));
    }

    function notify(message) {
        BX.UI.Notification.Center.notify({content: esc(message)});
    }

    function showError(error) {
        const message = error && error.errors
            ? error.errors.map(item => item.message).join('; ')
            : 'Ошибка выполнения.';
        notify(message);
    }

    BX.Local.CrmAssignCascade.CompanyList = {
        contractVersion: CONTRACT_VERSION,
        open: open,
        mount: mountGroupAction
    };

    BX.addCustomEvent('Grid::ready', onGridReadyOrUpdated);
    BX.addCustomEvent('Grid::updated', onGridReadyOrUpdated);
    BX.ready(function () {
        mountGroupAction(findCompanyGrid());
    });
})();
