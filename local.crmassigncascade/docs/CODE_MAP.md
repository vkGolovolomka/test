# Карта кода

**Исполнитель:** Vladislav

- `install/index.php` — установка/удаление, три таблицы, индексы, endpoint и событие UI.
- `install/version.php` — версия модуля.
- `include.php` — минимальный bootstrap и namespace.
- `lib/Ui/CompanyGrid.php` — подключает JS только администраторам на странице списка компаний.
- `js/company-list.js` — штатный ActionsPanel, EntitySelector, выбор строк/«Для всех» и frontend contract.
- `install/tools/local.crmassigncascade.ajax.php` — единственная короткая HTTP-точка.
- `lib/Service/JobService.php` — preview/create/cancel/status и фиксация точного списка компаний.
- `lib/Service/WorkerService.php` — cron-очередь, retry, stale recovery и завершение job.
- `lib/Service/CascadeService.php` — последовательный обход компании и выбранных связанных типов.
- `lib/Service/CrmService.php` — Factory capabilities, смарт-процессы, target state, UpdateOperation и CRM lifecycle.
- `lib/Service/AuditService.php` — журнал, идемпотентность и recovery CRM-элемента.
- `lib/Service/NotificationService.php` — итоговый отчёт и системное IM/Pull уведомление.
- `lib/Orm/JobTable.php` — ORM заголовка задания.
- `lib/Orm/JobCompanyTable.php` — ORM фиксированного списка компаний/очереди.
- `lib/Orm/LogTable.php` — ORM журнала изменений.
- `lib/Exception/*` — различает остановку, retry и штатный выход по time budget.
- `bin/worker.php` — короткий CLI runner с `flock`.
- `bin/diagnose.php` — read-only проверка возможностей конкретной коробки.
