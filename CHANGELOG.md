# Changelog

All notable changes to this project are documented in this file.

## [Unreleased] - 2026-09-28

### Added

- **Referent Card Module (`web/includes/referent_card_ui.php`):** Centralized CRUD interface for referents, external accounts, and relationships with tab-state preservation.
- **Shared Modal Stack (`web/includes/panel_modals.php`, `web/assets/panel-modal.js`, `web/assets/panel-modal.css`):** Native `<dialog>` handlers with dirty-check warnings, keyboard shortcuts, and standardized toast notifications.
- **Read-Only Directories (`web/includes/directory_pages.php`):** Directory views for Internet accounts, Local accounts, and Clients with confirmation prompts prior to navigating to cards.
- **Contextual Autocomplete:** Native `<datalist>` integration for inbox and relationship email fields.
- **System Dashboard (`web/includes/dashboard_ui.php`, `web/includes/panel_daemon_status.php`):** Service health overview, mail passage metrics, daemon status, and resource monitoring.

### Changed

- **Global Navigation (`web/includes/panel_nav.php`):** Reorganized left sidebar layout with collapsible state saved in `localStorage`.
- **Journal & Logs Layout:** Updated `relationship-status.php` and `logs.php` to inherit the unified panel navigation and styling.
- **Language Strings (`web/lang/ru.php`, `web/lang/en.php`):** Updated dictionary keys for directories, modals, and dashboard elements.

### Fixed

- Fixed return-path handling so validation error redirects preserve active card tabs (`return_to` & `tab`).
- Fixed flash message swallowing during directory search operations.
- Removed UTF-8 BOM from `panel_modals.php` to restore `declare(strict_types=1)` compatibility.
- Blocked destructive actions for `master` role operators at both UI and backend levels.

### Removed

- Removed legacy operational tables from the main control panel.
- Cleaned up obsolete "Legacy backlog" links and references from UI layers.
