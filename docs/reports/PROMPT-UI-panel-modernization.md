# Refactor: UI modernization, Referent Card, Directories, Autocomplete & Dashboard

**Date:** 2026-09-28  
**Branch:** `feature/panel-ui-modernization`  
**Prototype:** `Карточка референта — прототип UI.html`

## Summary

Comprehensive UI/UX overhaul of the web administration panel based on the `Карточка референта — прототип UI.html` specification. Unifies navigation, isolates entity CRUD into the Referent Card, introduces read-only directories, contextual autocomplete, and a clean system dashboard.

## Key Changes

- **Referent Card UX (PROMPT 1):** Tabbed interface (`overview`, `local`, `external`, `clients`) with native `<dialog>` modals, dirty-checking, hotkeys (`Esc`, `Ctrl/Cmd+Enter`), and strict return-path preservation (`return_to` / `tab`).
- **Global Navigation & Read-Only Directories (PROMPT 2):** Standardized left sidebar. Converted Internet accounts, Local accounts, and Clients into read-only directory tables with search/filter and explicit transition confirmation before jumping to referent cards.
- **Contextual Autocomplete (PROMPT 3):** Added native HTML `<datalist>` auto-suggestions for email fields sourced from existing DB records without introducing JS frameworks or extra endpoints.
- **System Dashboard (PROMPT 4):** Removed legacy referent CRUD tables from the landing page. Replaced with system summary stats, daemon status/PID/uptime health checks (`panel_daemon_status.php`), soft-failing system resource usage (RAM/Disk), and quick-navigation cards.
- **UX Polish & QA Fixes (PROMPT 5 & 6):** Keyboard row selection (`↑`/`↓` + `Enter`), table column sorting, delete confirm modals, protection for `master` role users, legacy wording cleanup, and strict error-handling redirects back to referent card tabs.

## Testing & QA

- **PHP Lint:** Clean.
- **Security:** Verified CSRF token validation across all mutating POST handlers.
- **Regressions Handled:** Fixed BOM markers, modal initial focus restoration, and flash/toast message persistence across directory searches.

---

## Web Panel Architecture & Structure

### Key Files & Components

| File | Role |
|------|------|
| `web/index.php` | Primary panel controller and route dispatcher |
| `web/includes/panel_nav.php` | Unified left navigation sidebar with collapsible layout state |
| `web/includes/panel_modals.php` | PHP helpers for modal dialogs and redirects with parameter preservation (`return_to`, `tab`) |
| `web/includes/referent_card_ui.php` | Referent Card interface (tabs: Overview, Local inbox, External accounts, Clients/Relationships) |
| `web/includes/directory_pages.php` | Read-only directory table views for Internet Accounts, Local Accounts, and Clients |
| `web/includes/dashboard_ui.php` | Dashboard control panel: service metrics and quick navigation |
| `web/includes/panel_daemon_status.php` | Shared helpers for daemon PID, uptime, and process health |
| `web/assets/panel-modal.js` | Lightweight controller for `<dialog>` modals, keyboard navigation (`↑`/`↓`/`Enter`), tab hotkeys, dirty checks |
| `web/assets/panel-modal.css` | Calm design palette, form layouts, modal backdrops, table highlight styles |

### UI Guidelines & Constraints

1. **CRUD Isolation:** Account and relationship management must happen inside the Referent Card modals (`referent_view`). Directories are strictly read-only.
2. **Context Preservation:** Form submissions and validation redirects must pass `return_to` and `tab` parameters to prevent losing the operator's workspace context.
3. **Modal Mechanics:** All modals use native HTML `<dialog>` elements backed by `panel-modal.js`. Destructive actions require confirmation modals (`data-pm-nodirty`).

### Prompt Map

| Prompt | Deliverable |
|--------|-------------|
| 1 | Referent card + modal shell |
| 2 | Global nav + read-only directories |
| 3 | Contextual `<datalist>` autocomplete |
| 4 | System dashboard + daemon status helpers |
| 5 | UX polish (keyboard, sort, confirms, legacy cleanup) |
| 6 | QA audit and regression fixes |

### Related operator guide

See [guide/05-web-panel.md](../guide/05-web-panel.md) for operator-facing panel documentation (updated for this refactor).
