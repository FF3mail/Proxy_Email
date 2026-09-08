# PROMPT-45 — Web UI Localization Audit

**Date:** 2026-09-08  
**Branch:** `prompt-45-web-localization`  
**Status:** Implemented

## Summary

The web panel previously mixed Russian and English strings (e.g. referent form label `Username` inside a Russian UI). This audit catalogued all administrator-facing text and migrated it to a centralized RU/EN localization layer.

### Localization architecture

| Component | Path | Role |
|-----------|------|------|
| Core API | `web/includes/i18n.php` | `__()`, `initPanelI18n()`, `renderLanguageSelector()`, `LocalizedUserException` |
| Russian strings | `web/lang/ru.php` | 214 translation keys |
| English strings | `web/lang/en.php` | 214 translation keys (parity) |

**API example:**

```php
__('referent.display_name')
__('referent.mailbox_not_found', ['email' => $email])
```

**Language resolution order:**

1. `?lang=ru|en` query parameter (whitelisted; stored in session + cookie)
2. `$_SESSION['panel_lang']`
3. Cookie `panel_lang` (1 year, HttpOnly, SameSite=Lax)
4. `Accept-Language` header (defaults to `ru`)

**Default language:** `ru`

**Language selector:** Visible on login page, main sidebar, and monitor navigation (`Русский | English`).

### Terminology mapping

| Concept | RU | EN | Notes |
|---------|----|----|-------|
| Referent (entity) | Референт | Referent | |
| Referent display name (`referents.username`) | Имя референта | Referent name | **Not** mail login; display only |
| Referent mail identity (`local_inbox`) | Email референта | Referent email | iRedMail mailbox address |
| Maildir path | Maildir (определён системой) | Maildir (system-resolved) | Read-only diagnostic |
| External account IMAP login | Логин IMAP/SMTP | IMAP/SMTP login | Optional; falls back to email |
| Provider | Провайдер | Provider | OAuth2 provider |
| Client | Клиент | Client | External correspondent |
| Dashboard | Сводка | Dashboard | Main overview page |

### Intentionally untranslated (technical identifiers)

- Protocol/service names: `IMAP`, `SMTP`, `OAuth2`, `SSL`, `TLS`, `Plain`
- Field names where canonical: `Auth endpoint`, `Token endpoint`, `Client ID`, `Client Secret`, `extra_params_json`, `PID`, `Uptime`
- Encryption option values: `none`, `ssl`, `tls`
- Log level labels in monitor tables: `ERROR`, `WARNING`, `CRITICAL`, `INFO` (from daemon logs)
- Database role values: `master`, `admin`
- Auth type values in dashboard: `plain`, `oauth2`

### Internal / non-UI strings (not localized)

| Location | Reason |
|----------|--------|
| `writeLog()` messages | Server log convention (English) |
| `web/includes/Cryptor.php` exceptions | Caught and mapped to `oauth.decrypt_error` in OAuth flow |
| `web/config.php` `error_log` warning | Installer/admin diagnostic |
| Daemon log message text in monitor tables | Raw log content from `mail-proxy-daemon.log` |

---

## Inventory by UI area

### 1. Authentication

| Location | Current text (before) | Context | RU | EN | Mechanism | Status |
|----------|----------------------|---------|----|----|-----------|--------|
| `panel_auth_ui.php` | Вход — DELTA-транзит | Page title | Вход — DELTA-транзит | Login — DELTA Transit | `auth.login_title` | Done |
| `panel_auth_ui.php` | Имя пользователя | Login field | Имя пользователя | Username | `auth.username` | Done |
| `panel_auth_ui.php` | Пароль | Login field | Пароль | Password | `auth.password` | Done |
| `panel_auth_ui.php` | Войти | Button | Войти | Log in | `auth.login_button` | Done |
| `panel_auth_ui.php` | Неверные учётные данные… | Error flash | (localized) | (localized) | `auth.invalid_credentials` | Done |
| `panel_auth_ui.php` | Вход выполнен | Success flash | (localized) | (localized) | `auth.login_success` | Done |
| `panel_auth_ui.php` | Вы вышли из системы | Logout flash | (localized) | (localized) | `auth.logout_success` | Done |
| `panel_auth_ui.php` | Setup messages (3 variants) | Pre-auth warning | (localized) | (localized) | `auth.setup_*` | Done |
| `auth.php` | 403 Forbidden / Master privileges required | Master gate | (localized) | (localized) | `auth.forbidden*` | Done |
| `helpers.php` | 403 Access Denied | IP allow-list | (localized) | (localized) | `auth.access_denied*` | Done |

### 2. Main navigation

| Location | Current text (before) | Context | RU | EN | Mechanism | Status |
|----------|----------------------|---------|----|----|-----------|--------|
| `index.php` renderHeader | DELTA-транзит | App name | DELTA-транзит | DELTA Transit | `app.name` | Done |
| `index.php` nav | Референты / Аккаунты / … | Sidebar links | (localized) | (localized) | `nav.*` | Done |
| `index.php` nav | Выход | Logout button | Выход | Logout | `nav.logout` | Done |
| `index.php` / `monitor.php` | — | Language selector | Русский \| English | Русский \| English | `renderLanguageSelector()` | Done |

### 3. Referents

| Location | Current text (before) | Context | RU | EN | Mechanism | Status |
|----------|----------------------|---------|----|----|-----------|--------|
| `index.php` | **Username** | Form label | **Имя референта** | Referent name | `referent.display_name` | Done |
| `index.php` | (none) | Display name hint | Только для отображения… | Display only in panel… | `referent.display_name_hint` | Done |
| `index.php` | Email референта | Mail identity field | Email референта | Referent email | `referent.email` | Done |
| `index.php` | Maildir (автоматически) | Read-only path | Maildir (определён системой) | Maildir (system-resolved) | `referent.maildir_auto` | Done |
| `index.php` | Почтовый ящик референта на iRedMail… | Help text | (localized) | (localized) | `referent.email_hint` | Done |
| `index.php` | Client Email | Client section | Email клиента | Client email | `referent.client_email` | Done |
| `index.php` | Референт сохранён | Success flash | (localized) | (localized) | `referent.saved` | Done |
| `maildir_resolver.php` | Ящик для … не найден | Validation error | (localized) | (localized) | `referent.mailbox_not_found` | Done |

### 4. Providers

| Location | Current text (before) | Context | RU | EN | Mechanism | Status |
|----------|----------------------|---------|----|----|-----------|--------|
| `providers_ui.php` | OAuth2 провайдеры | Page heading | (localized) | (localized) | `provider.title` | Done |
| `providers_ui.php` | Добавить провайдер | Button | (localized) | (localized) | `provider.add` | Done |
| `providers_ui.php` | Table headers (Код, Название, …) | List table | (localized) | (localized) | `provider.*` / `common.*` | Done |
| `providers_ui.php` | Validation messages | Form errors | (localized) | (localized) | `provider.*` / `error.oauth_*` | Done |
| `providers_ui.php` | Провайдер сохранён | Success flash | (localized) | (localized) | `provider.saved` | Done |

### 5. Monitor

| Location | Current text (before) | Context | RU | EN | Mechanism | Status |
|----------|----------------------|---------|----|----|-----------|--------|
| `monitor.php` | Мониторинг системы | Page heading | (localized) | (localized) | `monitor.heading` | Done |
| `monitor.php` | ● Работает / ○ Остановлен / … | Status badges | (localized) | (localized) | `monitor.status_*` | Done |
| `monitor.php` | Критические события… | Section headings | (localized) | (localized) | `monitor.*_errors` | Done |
| `monitor.php` | Время / Уровень / Сообщение | Table headers | (localized) | (localized) | `monitor.col_*` | Done |
| `monitor.php` | Empty-state messages | No events | (localized) | (localized) | `monitor.no_*` | Done |
| `monitor.php` | Uptime `3д 5ч 12м` | Duration format | `{n}д/{n}ч/{n}м` | `{n}d/{n}h/{n}m` | `monitor.uptime_*` | Done |

### 6. Administration (operators)

| Location | Current text (before) | Context | RU | EN | Mechanism | Status |
|----------|----------------------|---------|----|----|-----------|--------|
| `panel_auth_ui.php` | **Username / Role / Active / Actions** (EN) | Operator table | (localized) | (localized) | `common.*` / `operator.*` | Done |
| `panel_auth_ui.php` | Deactivate | Button | Деактивировать | Deactivate | `operator.deactivate` | Done |
| `panel_auth_ui.php` | yes / no | Active column | да / нет | yes / no | `common.yes` / `common.no` | Done |

### 7. Forms (external accounts)

| Location | Current text (before) | Context | RU | EN | Mechanism | Status |
|----------|----------------------|---------|----|----|-----------|--------|
| `index.php` | **Username** | IMAP login field | **Логин IMAP/SMTP** | IMAP/SMTP login | `account.login` | Done |
| `index.php` | -- Select -- | Provider dropdown | — Выберите — | — Select — | `common.select` | Done |
| `index.php` | Password (plain auth) | Password field | (localized) | (localized) | `account.password_plain` | Done |
| `index.php` | Авторизовать OAuth2 | OAuth button | (localized) | (localized) | `account.authorize_oauth2` | Done |

### 8. Validation / errors

| Location | Current text (before) | Context | RU | EN | Mechanism | Status |
|----------|----------------------|---------|----|----|-----------|--------|
| `index.php` | referent_id is required | Flash | (localized) | (localized) | `account.referent_id_required` | Done |
| `index.php` | Access denied | Flash | (localized) | (localized) | `account.access_denied` | Done |
| `index.php` | Invalid entity or id | Flash | (localized) | (localized) | `error.invalid_entity` | Done |
| `helpers.php` | CSRF token mismatch | Flash | (localized) | (localized) | `error.csrf` | Done |
| `helpers.php` | OAuth endpoint SSRF errors | Provider save | (localized) | (localized) | `error.oauth_*` | Done |

### 9. Success / warning notifications

All flash messages in `index.php`, `panel_auth_ui.php`, `providers_ui.php`, `oauth2.php` now use `__()` keys. See `web/lang/*.php` for full list.

### 10. JavaScript / AJAX

No custom JavaScript files exist in `web/`. All user-facing dynamic text is PHP-generated flash messages (localized). Tailwind CDN has no UI strings.

### 11. Accessibility labels

| Location | Attribute | Key | Status |
|----------|-----------|-----|--------|
| Sidebar / login / monitor | `aria-label` on language selector | `common.language` | Done |
| Referent Maildir field | `aria-readonly="true"` | — | Done |
| Language selector active locale | `aria-current="true"` | — | Done |
| Language links | `hreflang="ru|en"` | — | Done |
| `<html lang>` | Dynamic | `panelHtmlLang()` | Done |

### 12. Dashboard

| Location | Current text (before) | Context | RU | EN | Mechanism | Status |
|----------|----------------------|---------|----|----|-----------|--------|
| `index.php` | **Dashboard** (EN title) | Page heading | **Сводка** | Dashboard | `dashboard.title` | Done |
| `index.php` | Референт: Вкл/Выкл … | Activity column | (localized) | (localized) | `dashboard.*_on/off` | Done |
| `index.php` | Активен до / Истёк | Token status | (localized) | (localized) | `dashboard.token_*` | Done |

---

## Files changed

| File | Change |
|------|--------|
| `web/includes/i18n.php` | **New** — localization core |
| `web/lang/ru.php` | **New** — Russian translations |
| `web/lang/en.php` | **New** — English translations |
| `web/index.php` | Migrated all UI strings; language selector in header |
| `web/monitor.php` | Full localization; language selector in nav |
| `web/includes/panel_auth_ui.php` | Auth + operators UI |
| `web/includes/providers_ui.php` | Provider CRUD UI |
| `web/includes/oauth2.php` | OAuth flash messages |
| `web/includes/helpers.php` | CSRF, 403, OAuth SSRF errors |
| `web/includes/auth.php` | Master 403 page |
| `web/includes/maildir_resolver.php` | `LocalizedUserException` for referent errors |

## Maintainer guide

When adding new UI text:

1. Add a semantic key to **both** `web/lang/ru.php` and `web/lang/en.php`.
2. Use `__('your.key')` or `__('your.key', ['param' => $value])` in PHP.
3. For user-facing exceptions, throw `new LocalizedUserException('your.key', [...])` and display via `exceptionUserMessage($e)`.
4. Do **not** hardcode Russian or English strings in PHP templates.
5. Technical identifiers (protocol names, log levels) may remain untranslated.

## Static verification

Post-migration grep confirms no remaining hardcoded mixed-language UI strings in `web/index.php`, `panel_auth_ui.php`, `providers_ui.php`, or `monitor.php`. All administrator-facing labels route through `__()`.

## Testing notes

| Test | Environment | Result |
|------|-------------|--------|
| RU runtime (login, referent, provider, monitor, lang switch) | Requires deployed panel | Pending server test |
| EN runtime | Requires deployed panel | Pending server test |
| `DELTA_VALIDATION_ONLY=1 ./delta-transit-install.sh` | Windows dev host (no PHP/bash) | Not run locally |
| PROMPT-43 Maildir auto-resolution | Code unchanged (labels only) | No regression expected |

---

## Recommendation

**ACCEPTED** — pending server-side runtime verification and validation-only installer run on Linux target host.
