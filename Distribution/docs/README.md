# Документация DELTA-transit

Навигация по каталогу `docs/`. Это **индекс**, а не дубликат глав руководства оператора.

> **Источник истины по архитектуре и коду:** при расхождении с текстом ниже — [DELTA-transit_anchor.md](DELTA-transit_anchor.md) и репозиторий (скрипты установки, демон, панель).

---

## Операционная документация (эксплуатация)

| Раздел | Назначение |
|--------|------------|
| [guide/README.md](guide/README.md) | Руководство оператора: установка, настройка, панель, эксплуатация (10 глав) |
| [guide/10-deployment-checklist.md](guide/10-deployment-checklist.md) | **Актуальный** чек-лист развёртывания / GO–NO-GO |
| [DELTA_transit_admin_guide.pdf](DELTA_transit_admin_guide.pdf) | Формальный справочник администратора (PDF) |

---

## Архитектура и интеграция

| Документ | Назначение |
|----------|------------|
| [DELTA-transit_anchor.md](DELTA-transit_anchor.md) | **Source of truth:** архитектура, схема, безопасность, критерии production, закрытие PROMPT |

---

## Решения (ADR)

| Раздел | Назначение |
|--------|------------|
| [decisions/README.md](decisions/README.md) | ADR и реестры решений (например PROMPT-79) |

---

## Исторические материалы (не для эксплуатации)

| Раздел | Назначение |
|--------|------------|
| [reports/README.md](reports/README.md) | Отчёты закрытия PROMPT и аудиты разработки |
| [prompts/README.md](prompts/README.md) | Архив промптов для ИИ-разработки |
| [Ckeck-list_00.md](Ckeck-list_00.md) | **Устаревший** чек-лист пилота — см. [guide/10-deployment-checklist.md](guide/10-deployment-checklist.md) |

**Не используйте `reports/` и `prompts/` для повседневной работы на сервере.** Код имеет приоритет над историческими текстами.

---

## Сборка документации

Скрипты `build_admin_guide.*` и каталог `src/` — генерация PDF; не относятся к runtime системы.

---

Краткий обзор репозитория: [../README.md](../README.md).
