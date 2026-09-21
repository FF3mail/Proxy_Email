# DELTA-transit — руководство оператора

**Версия:** 4.1 (PROMPT-37)  
**Аудитория:** системные администраторы — установка, настройка и ежедневная эксплуатация на реальном хосте.

Этот каталог — **операторское** руководство в формате Markdown (10 глав). Оно дополняет, но не заменяет:

| Документ | Назначение |
|----------|------------|
| [../DELTA_transit_admin_guide.pdf](../DELTA_transit_admin_guide.pdf) | Формальный справочник администратора (PDF, WeasyPrint) |
| [../DELTA-transit_anchor.md](../DELTA-transit_anchor.md) | Внутренний архитектурный якорь для разработки и агентов |
| [../Ckeck-list_00.md](../Ckeck-list_00.md) | Исторический чек-лист пилота (может отставать от кода) |

> **Источник истины при расхождении:** код и скрипты (`delta-transit-install.sh`, `configure_limits.sh`).

---

## Карта документов

| № | Документ | Когда читать |
|---|----------|--------------|
| 1 | [01-overview.md](01-overview.md) | Первое знакомство: что делает система, из чего состоит |
| 2 | [02-requirements.md](02-requirements.md) | Перед подготовкой сервера |
| 3 | [03-installation.md](03-installation.md) | Пошаговая установка |
| 4 | [04-configuration.md](04-configuration.md) | Файлы конфигурации, БД, лимиты |
| 5 | [05-web-panel.md](05-web-panel.md) | Панель, вход оператора, OAuth2 |
| 6 | [06-operations.md](06-operations.md) | Логи, перезапуск, обновления |
| 7 | [07-troubleshooting.md](07-troubleshooting.md) | Типичные ошибки |
| 8 | [08-security.md](08-security.md) | Безопасность, allow-list + аутентификация |
| 9 | [09-backup-restore.md](09-backup-restore.md) | Резервное копирование |
| 10 | [10-deployment-checklist.md](10-deployment-checklist.md) | Чек-лист перед пилотом |

---

## Быстрый старт

1. [02-requirements.md](02-requirements.md) — подготовьте iRedMail-хост.
2. [03-installation.md](03-installation.md) — `delta-transit-install.sh` (создаст master панели).
3. При необходимости [03-installation.md](03-installation.md) §3.5 — `configure_limits.sh` (интерактивно).
4. [05-web-panel.md](05-web-panel.md) — вход и настройка референтов.
5. [10-deployment-checklist.md](10-deployment-checklist.md) — GO/NO-GO перед пользователями.
