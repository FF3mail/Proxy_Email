# DELTA-transit — Якорный документ v4.5

**Статус:** Production Candidate / pilot; production routing is **relationship_live** only (see §0.1 / §3.1–§3.2)  
**Дата:** 2026-10-08  
**Синхронизирован с:** `origin/master` на момент этого обновления; **код — источник истины при расхождении.**

---

## 0. Назначение документа

Документ передаёт контекст языковым моделям и подготавливает промпты на доработку.

- Отражает **только текущее состояние** кодовой базы на `origin/master`.
- `docs/reports/` и `docs/prompts/` — **исторические** материалы; не считать их текущими требованиями.
- Исторические дефекты (до патчей PROMPT 01–09 и V2.0) не считаются активными.
- Изменения вносятся точечно.

### 0.1 Current production invariants (AI must not invent otherwise)

Нормативные факты текущего `origin/master` (post PR #89 / relationship mailbox ownership):

- **Inbound / outbound routing и watch:** production = `relationship_live` / `relationship_live` / `relationship_only` через `mail-proxy.service.d/routing.conf`. Daemon code accepts only live/relationship aliases; unsupported env values fall back to those same live defaults (PROMPT-79.1). Shadow / legacy / dual **routing paths removed** from the daemon (`relationship_shadow.py` absent).
- **Mailbox ownership / routing boundary** is the **ClientRelationship** only: external client + external account, `local_client_email`, `local_referent_email`, and `local_client_maildir`. Not 1:1 referent→account; not shared `referents.local_*`.
- **`referents.local_inbox` / `local_outbox`:** nullable **legacy only** (`schema.sql` + `migrations/006_…`). Daemon **does not** use them for normal routing, disposal notify addresses, `_resolve_local_recipients` delivery, or referent-outbox backlog/watch (those paths are relationship-local or quarantined empty). Columns may still be loaded for display/collision diagnostics.
- **`referents.active`:** parent-level administrative kill-switch / enable gate (PROMPT-83). A referent may be active only with at least one complete, active relationship that has an active external account. This gate does **not** imply shared mailbox ownership.
- **Panel:** Local tab is demoted / non-routing (legacy note). No shared `local_referent_email` prefill from `referents.local_inbox`. Referent create does **not** invent incomplete `clients` rows from a legacy `client_email` field.
- **Mail-passage journal:** write a durable journal row **before** irreversible IMAP delete/EXPUNGE or Maildir unlink (ADR-001 / PROMPT-79.2). See §21 for disposal reasons and attachment gates — do not restate every sub-PROMPT here.
- **Attachment gates / disposal** are implemented on master (`attachment_policy.py`, `mail_disposal.py`, `message_rebuild.py`, `referent_notify.py`) — see §21.
- **Panel / daemon:** do **not** invent features from `docs/reports/` or `docs/prompts/`. Prefer this anchor + the repository tree.
- **Open PR behaviour is not on master** until merged. Never assume an open PR is already production.

---

## 1. Назначение проекта

DELTA-transit — корпоративный почтовый прокси-шлюз между внешними IMAP/SMTP и локальными Maildir референтов (iRedMail).

| Компонент | Роль |
|-----------|------|
| Postfix | MTA — приём/отправка |
| Dovecot | IMAP — локальная доставка |
| MariaDB | Конфигурация, аккаунты, OAuth2, mail-passage journal |
| Nginx + PHP-FPM | Веб-панель |
| Python 3 + venv | Демон `mail-proxy-daemon.py` |
| systemd | Управление сервисом |

---

## 2. Архитектура и карта файлов

### Потоки данных

**Входящий:** `ImapPoller` → IMAP Queue (max 5000) → `ImapWorkerPool` (20) → relationship_live plan → rebuild / dispose → local SMTP `:25` → that relationship’s `local_referent_email`  
**Исходящий:** `MaildirHandler` watches **only** `ClientRelationship.local_client_maildir/new` → SMTP Queue (max 1000) → `SmtpWorkerPool` (20) → that relationship’s external SMTP  

> Shared `referents.local_inbox` / `local_outbox` are not part of these paths.

### Ключевые классы демона

| Класс | Назначение |
|-------|------------|
| `Cryptor` | AES-256-GCM (+ чтение legacy AES-CBC), совместим с PHP |
| `Database` | `MySQLConnectionPool`, `DB_POOL_SIZE=12` |
| `MailHandler` | Бизнес-логика IMAP/SMTP, `_validate_account_settings()` (FIX P7) |
| `ProxyDaemon` | Lifecycle, watchdog, супервизор |

### Структура дистрибутива (корень репозитория)

```
DELTA-transit/
├── docs/
│   ├── DELTA-transit_anchor.md       # этот документ (SoT)
│   ├── README.md                     # индекс документации
│   ├── Ckeck-list_00.md              # исторический чек-лист
│   ├── DELTA_transit_admin_guide.pdf
│   ├── decisions/                    # ADR (например ADR-001)
│   ├── guide/                        # руководство оператора (Markdown)
│   ├── prompts/                      # ИСТОРИЯ — не требования
│   └── reports/                      # ИСТОРИЯ — не требования
├── web/                              # веб-панель
│   ├── index.php
│   ├── config.php
│   ├── monitor.php
│   ├── logs.php
│   ├── relationship-status.php       # mail-passage journal views
│   ├── lang/                         # en.php, ru.php
│   ├── assets/                       # panel CSS/JS, brand, presets
│   └── includes/                     # auth, Cryptor, relationship_*,
│                                     # referent_*, panel_*, oauth2, …
├── mail-proxy-daemon.py
├── relationship_lookup.py            # ClientRelationship lookup
├── relationship_routing.py           # live-only routing (PROMPT-79.1)
├── message_rebuild.py                # attachment-only rebuild / fan-out
├── attachment_policy.py              # extension / subject / nested gates
├── mail_passage_journal.py           # durable journal writer
├── mail_disposal.py                  # dispose + notify orchestration
├── referent_notify.py                # local disposal notifications
├── mail-proxy.service
├── mail-proxy.service.d/routing.conf # production Environment= drop-in
├── mail-proxy-setup.sh
├── delta-transit-install.sh
├── configure_limits.sh
├── logrotate-mail-proxy
├── tmpfiles.d-mail-proxy.conf        # log dir ACLs / setgid (PROMPT-78)
├── schema.sql
├── migrations/
│   ├── 002_client_relationship_columns.sql
│   ├── 003_referent_mode_overrides.sql
│   ├── 004_mail_passage_journal.sql
│   ├── 005_mail_passage_journal_skipped.sql
│   └── 006_referent_local_nullable.sql
├── scripts/
│   ├── purge_mail_passage_journal.py
│   ├── verify-install-regression.sh
│   └── panel_ui_vps_deploy.sh
├── requirements.txt
└── tests/                            # Python unittest + PHP panel static tests
```

| Файл | Назначение |
|------|------------|
| `delta-transit-install.sh` | Полная установка (venv, nginx, systemd, web, audit) |
| `configure_limits.sh` | Настройка лимитов для вложений 150 МБ |
| `logrotate-mail-proxy` | Ротация `/var/log/mail-proxy/*.log` |
| `tmpfiles.d-mail-proxy.conf` | Durable log directory permissions |

> `relationship_shadow.py` **отсутствует** на master (удалён в PROMPT-79.1).

---

## 3. Схема базы данных (`mail_proxy`)

| Таблица | Назначение |
|---------|------------|
| `referents` | Референты: `username`, nullable legacy `local_inbox` / `local_outbox` (non-routing), retained override ENUM columns (unused by daemon after 79.1), `active` (parent kill-switch) |
| `clients` | **ClientRelationship** (sole mailbox ownership): legacy `email` + additive columns (`external_client_email`, `local_client_email`, `local_referent_email`, `external_account_id`, `local_client_maildir`) |
| `external_accounts` | Внешние ящики: IMAP/SMTP, OAuth2 |
| `oauth_tokens` | Токены OAuth2, **UNIQUE(`account_id`)** |
| `oauth_providers` | Google, Yandex, Microsoft (идемпотентный seed) |
| `panel_admins` | Операторы панели (`master` / `admin`) |
| `mail_passage_journal` | Durable passage / disposal / skipped events — создаётся миграциями `004` + `005` (не дублируется как `CREATE` в `schema.sql`) |

> `referents.local_inbox` / `local_outbox` — **nullable legacy only** on current master (`NULL DEFAULT NULL` in `schema.sql`; upgrade path `migrations/006_referent_local_nullable.sql`). They are **not** mailbox owners and are **not** used for routing, watch, or disposal notify after PR #89. Production identity is the ClientRelationship four-mailbox chain + `local_client_maildir`. Activation policy for `referents.active` is enforced in panel code (`referent_activation.php`, PROMPT-83).

> If set, `referents.local_outbox` is an absolute Maildir root on disk (not an email address) — historical/diagnostic only; outbound watch uses `clients.local_client_maildir/new`.

### `external_accounts` — важные поля

| Поле | Назначение |
|------|------------|
| `username` | Логин для plain IMAP/SMTP (fallback: `email`) |
| `password_enc` | Единый зашифрованный пароль (AES-GCM / legacy CBC) |
| `auth_type` | `plain` \| `oauth2` (`ENUM` в `schema.sql`) |
| `imap_encryption` / `smtp_encryption` | `none` \| `ssl` \| `tls` (`ENUM` в `schema.sql`) |

> Демон использует **`username` + `password_enc`**, не отдельные `imap_user`/`smtp_pass_enc`.

---

## 4. Конфигурация и права

| Путь | Права / владелец |
|------|------------------|
| `/etc/mail-proxy/crypto.key` | `root:mail-proxy-crypto` 0640 |
| `/etc/mail-proxy/db.conf` | `root:mail-proxy-crypto` 0640, секция `[db]` |
| `/var/log/mail-proxy/` | `vmail:mail-proxy-logs` 2750 (setgid; see PROMPT-78 / §19) |
| `/var/spool/mail-proxy/tmp/` | `vmail:vmail` 0700 |
| `/var/www/mail-proxy/` | `root:root` 0755/0644 |
| `/opt/delta-transit/venv/` | Python virtualenv |

| Группа | Назначение |
|--------|------------|
| `mail-proxy-crypto` | `vmail`, `www-data` — доступ к crypto.key и db.conf |
| `mail-proxy-logs` | `www-data` — **только** логи, **не** Maildir |
| `vmail` | Демон, Maildir — **www-data не входит** |

### Константы демона

| Константа | Значение |
|-----------|----------|
| `TEMP_DIR` | `/var/spool/mail-proxy/tmp` |
| `IMAP_QUEUE_MAXSIZE` | 5000 |
| `SMTP_QUEUE_MAXSIZE` | 1000 |
| `IMAP_WORKER_COUNT` / `SMTP_WORKER_COUNT` | 20 / 20 |
| `DB_POOL_SIZE` | 12 |
| `REQUIRE_TLS` | `True` (STARTTLS обязателен для SMTP с `smtp_encryption=tls`) |
| `VALID_AUTH_TYPES` | `('plain', 'oauth2')` — FIX P7 |
| `VALID_ENCRYPTION_MODES` | `('none', 'ssl', 'tls')` — FIX P7 |
| `MAX_INBOUND_MESSAGE_BYTES` | `200 * 1024 * 1024` (override: env `MAX_INBOUND_MESSAGE_BYTES`) |
| `MAX_SIZE_SKIP_RETRIES` | `3` — forced `\Seen` after consecutive size skips |
| `MAX_SIZE_SKIP_TRACKER_ENTRIES` | `10000` — cap on process-local skip tracker |
| `LOG_FILE` | `/var/log/mail-proxy/mail-proxy-daemon.log` |
| `APP_BASE_URL` | `config.php` — доверенный URL для OAuth redirect_uri |
| `INBOUND_ROUTING_MODE` | production / code default: **`relationship_live`** (see §3.1) |
| `OUTBOUND_ROUTING_MODE` | production / code default: **`relationship_live`** (see §3.2) |
| `OUTBOUND_WATCH_MODE` | production / code default: **`relationship_only`** (see §3.2) |

### 3.1 Входящая маршрутизация (relationship_live)

**Текущее поведение (master):**

| Компонент | Модуль | Назначение |
|-----------|--------|------------|
| `RelationshipLookup` | `relationship_lookup.py` | Запросы ClientRelationship (inbound/outbound API) |
| Live routing | `relationship_routing.py` | Только `relationship_live`; ignores `referent_local_inbox` |
| Rebuild / fan-out | `message_rebuild.py` + `attachment_policy.py` | Attachment-only local RFC822 → `local_referent_email` |
| Disposal / journal | `mail_disposal.py` + `mail_passage_journal.py` | Write-before-delete; notify via relationship local mailbox |
| Панель CRUD | `relationship_editor.php` + `referent_card_ui.php` | Relationship-owned mailboxes; Local tab = legacy only |
| Activation | `referent_activation.php` | Parent `referents.active` kill-switch (PROMPT-83) |
| Миграция | `migrations/002_…` … `006_…` | Additive schema evolution |

**Контракт inbound lookup (авторитетный):**

```text
resolve_inbound(external_account_id, normalize_email(From))
```

Не используется: To/Cc, Subject, envelope recipient.

**Production env** (`mail-proxy.service.d/routing.conf`):

```text
INBOUND_ROUTING_MODE=relationship_live
```

Unset / unsupported values in code → still `relationship_live`.

**`relationship_live` — текущее поведение:**

| Ситуация | Локальная доставка | IMAP | Legacy fallback |
|----------|-------------------|------|-----------------|
| Match + attach gates pass | Да → rebuild fan-out → `local_referent_email` | `\Seen` after success; source dispose after journal | Нет |
| No relationship / inactive | Нет | Journal → Seen → Deleted+EXPUNGE | **Нет** |
| Attach gate fail | Нет | Journal (disposed/skipped) → dispose | **Нет** |
| Lookup/MIME/DB ambiguity | Нет | Leave UNSEEN (fail-closed) | **Нет** |

**Historical (removed):** Stage 1–2a `shadow` / `legacy` dual-run and `relationship_shadow.py` existed during cutover; removed in PROMPT-79.1. See `docs/reports/PROMPT-58-*.md`, `PROMPT-63-*.md`, `PROMPT-79-1-*.md` (historical only).

**Ops / rollback note:** production drop-in is `mail-proxy.service.d/routing.conf`. Changing modes back to shadow/legacy is **not** supported by current daemon code (values coerce to live). Rollback means redeploying an older package that still contained those paths — not a config-only flip on current master.

### 3.2 Исходящая маршрутизация и watch (relationship_live / relationship_only)

**Текущее поведение (master):**

- Watch targets: `RelationshipLookup.list_watch_targets()` → `local_client_maildir/new` only (`OUTBOUND_WATCH_MODE=relationship_only`).
- Referent-level `local_outbox/new` watches removed (PROMPT-79.1).
- Account selection: `resolve_outbound(normalize_email(From))` → `ClientRelationship.external_account_id`.

**Контракт outbound lookup (авторитетный):**

```text
resolve_outbound(normalize_email(From))
```

| Компонент | Источник |
|-----------|----------|
| Outbound routing identity | RFC822 `From` → `local_client_email` |
| Relationship lookup | `RelationshipLookup.resolve_outbound()` |
| Selected account | `ClientRelationship.external_account_id` → `dto.account` |

Не используется для выбора аккаунта: `referent_id LIMIT 1`, порядок строк в `external_accounts`, To/Cc.

**Production env:**

```text
OUTBOUND_ROUTING_MODE=relationship_live
OUTBOUND_WATCH_MODE=relationship_only
```

**`relationship_live` — текущее поведение:**

| Ситуация | Внешняя доставка | Legacy fallback |
|----------|------------------|-----------------|
| Match | Да → rebuild 1:1 → SMTP via `dto.account` | Нет |
| Miss (unknown From) | Нет; файл остаётся в `new` (retry) / disposal path per code | **Нет** |
| Lookup error | Нет; fail-closed / retry per code | **Нет** |

**Historical (removed):** `OUTBOUND_WATCH_MODE=referent_only|dual` and outbound `shadow`/`legacy` routing existed during Stage 2b/2c; removed in PROMPT-79.1. Reports: `PROMPT-65-*.md`, `PROMPT-66-*.md`, `PROMPT-79-1-*.md` (historical only).

---

## 5. Python-зависимости (`requirements.txt`)

| Пакет | Назначение |
|-------|------------|
| `cryptography>=42.0.0` | AES-256-GCM |
| `watchdog>=4.0.0` | Maildir inotify |
| `mysql-connector-python>=8.4.0` | Connection pool |
| `requests>=2.32.0` | OAuth2 token refresh |

---

## 6. Лимиты (целевое вложение 150 МБ)

Base64-overhead ~33% → SMTP ≈ 200 МБ. Значения согласованы в `configure_limits.sh`.

| Параметр | Значение |
|----------|----------|
| Postfix `message_size_limit` | 209 715 200 (200 МБ) |
| Postfix `mailbox_size_limit` | 314 572 800 (300 МБ) |
| Nginx `client_max_body_size` | 210M |
| PHP `upload_max_filesize` | 200M |
| PHP `post_max_size` | 210M |
| PHP-FPM `pm.max_children` | 10 on staging (~8 GB RAM; override `PHP_FPM_MAX_CHILDREN`) |
| MariaDB `max_allowed_packet` | 256M |

> PHP-FPM 8.1–8.3: `configure_limits.sh` and `delta-transit-install.sh` detect the installed version under `/etc/php/<version>/fpm/` instead of hard-coding 8.1. Staging/integration hosts (Ubuntu 24.04 + PHP 8.3) are supported; production pool sizing is evaluated separately.

---

## 7. Исправления v3.2 (синхронизация с кодом)

| ID | Проблема | Решение |
|----|----------|---------|
| FIX-3.2-1 | Демон запрашивал несуществующие колонки `imap_user`/`imap_pass_enc` | SQL → `username`, `password_enc`; хелперы `_plain_auth_login()` / `_plain_auth_password()` |
| FIX-3.2-2 | Разрозненная структура (zip + flat web) | Единый дистрибутив: `web/` для инсталлятора |
| FIX-3.2-3 | `monitor.php` подключал `helpers.php` из корня | `require_once includes/helpers.php` |
| FIX-3.2-4 | Навигация: `action=providers` не обрабатывался | Алиасы `providers` → `provider_list`, `referents`/`accounts` → dashboard |
| FIX-3.2-5 | `mail-proxy.service` содержал git-артефакты `+` | Удалены из исходника; `mail-proxy-setup.sh` чистит через `sed` |
| FIX-3.2-6 | `writeLog()` молчал после первой ошибки FPM | Убран `static $reportedError` |
| FIX-3.2-7 | Независимый рестарт Postfix/Dovecot при ошибке одного | Joint-restart только если оба `*_OK=true` |
| FIX-3.2-8 | Logrotate-файл без стандартного имени | `logrotate-mail-proxy` |
| FIX-3.2-9 | `delta-transit-install.sh` ожидал flat `web/` | `WEB_FILES` включает `config.php` и `includes/*` |

### P4 — частично закрыт (pre-fetch size guard)

| Этап | Статус |
|------|--------|
| `_deliver_to_local_smtp()` / исходящая SMTP | **Закрыт** — потоковая передача из временного файла (чанки 64 КБ) |
| Pre-fetch size guard в `poll_external_imap()` | **Частично закрыт** — `RFC822.SIZE` (primary), `BODYSTRUCTURE` (defensive fallback) до `fetch('(BODY.PEEK[])')` |
| Oversized / unknown-size inbound | **Пропуск** — без полного RFC822 fetch; fail-closed при неизвестном размере |
| Retry / forced `\Seen` | `MAX_SIZE_SKIP_RETRIES` последовательных skip → `UID STORE` `\Seen` (fallback: `STORE` по seq) |
| `imaplib.fetch(num, '(BODY.PEEK[])')` для принятых писем | **Открыт** — письма ≤ лимита всё ещё буферизуются imaplib в RAM (PEEK устраняет побочный `\Seen`, но не снижает footprint) |

Перед каждым `fetch('(BODY.PEEK[])')` демон запрашивает `(UID RFC822.SIZE)`. Если размер неизвестен или `> MAX_INBOUND_MESSAGE_BYTES` — полный body fetch не выполняется. `MAX_INBOUND_MESSAGE_BYTES` задаётся через env (default 200 MiB, согласован с `configure_limits.sh`). Process-local трекер `(account_id, uid)` ограничен `MAX_SIZE_SKIP_TRACKER_ENTRIES`.

BODYSTRUCTURE fallback: только однопартовые структуры без `multipart`; неоднозначный BODYSTRUCTURE → `unknown` (fail-closed), без оценки размера.

> P4 не полностью закрыт: сообщения на или ниже лимита всё ещё полностью буферизуются imaplib при `BODY.PEEK[]` fetch.

### P7 — закрыт (FIX P7)

Whitelist `auth_type` и режимов шифрования реализован в `mail-proxy-daemon.py`:

| Элемент | Значение в коде |
|---------|-----------------|
| `VALID_AUTH_TYPES` | `('plain', 'oauth2')` |
| `VALID_ENCRYPTION_MODES` | `('none', 'ssl', 'tls')` |
| Метод | `MailHandler._validate_account_settings(acc, encryption_field, protocol_label)` |
| Вызов до IMAP | `poll_external_imap()` — до `imaplib` connect |
| Вызов до SMTP | `send_via_external_smtp()` — до `smtplib` connect |
| Невалидное значение | `logger.error(...)`, `return` / `return False` — аккаунт пропускается, **без подстановки умолчаний** |

Константы синхронизированы с `ENUM` в `schema.sql` (`external_accounts.auth_type`, `imap_encryption`, `smtp_encryption`).

---

## 8. Подтверждённые исправления (не трогать)

- MySQL Connection Pool (`DB_POOL_SIZE=12`)
- Worker Pools (`ImapWorkerPool` / `SmtpWorkerPool`)
- `IMAP_QUEUE_MAXSIZE = 5000`
- `UNIQUE(account_id)` + `INSERT ... ON DUPLICATE KEY UPDATE` для OAuth-токенов
- systemd hardening (seccomp, namespaces, capabilities)
- `monitor.php` — устойчивость к `shell_exec() === null`
- Потоковая **исходящая** SMTP-передача (чанки 64 КБ)
- `TEMP_DIR` → `/var/spool/mail-proxy/tmp`
- `APP_BASE_URL` — не из `HTTP_HOST`; проверяется инсталлятором
- CSRF: `requireValidCsrfToken()` в `index.php`
- SSRF: `assertSafeOAuthEndpoint()` (PHP) + `validate_oauth_endpoint()` (Python)
- AES-256-GCM с обратной совместимостью CBC (PHP ↔ Python)
- `getClientIp()` — доверие заголовкам только при `REMOTE_ADDR` = localhost
- `www-data` не в группе `vmail`
- `parse_ini_file($file, true)` для секции `[db]`
- **FIX P7:** whitelist `auth_type` / `imap_encryption` / `smtp_encryption` через `_validate_account_settings()` перед каждым IMAP/SMTP-соединением
- **PROMPT-79.1:** live-only routing; shadow/legacy/dual paths removed from daemon
- **PROMPT-79.2 / ADR-001:** mail-passage journal write-before-delete
- **PROMPT-77 / rebuild:** attachment-only inbound fan-out + outbound 1:1 under `relationship_live`
- **PROMPT-78:** durable daemon log permissions via tmpfiles.d
- **PROMPT-83:** nullable legacy `referents.local_*` + panel activation gate
- **PR #89:** ClientRelationship is the sole normal mailbox ownership / routing boundary; daemon notify and recipient resolution no longer use shared `referents.local_*`

---

## 9. Требования к безопасности

| Требование | Статус |
|------------|--------|
| `www-data` ∉ `vmail` | Проверяется инсталлятором |
| `crypto.key` / `db.conf` через `mail-proxy-crypto` | Реализовано |
| Логи через `mail-proxy-logs` | Реализовано |
| `getClientIp()` REMOTE_ADDR guard | Реализовано |
| systemd hardening | Реализовано |
| SSRF OAuth endpoints | Реализовано (PHP + Python) |
| CSRF веб-панели | Реализовано |
| Whitelist auth_type/encryption | **Закрыт (P7 / FIX P7)** |
| Journal before irreversible delete | **Закрыт (ADR-001 / 79.2)** |

---

## 10. Инструкция для следующей модели

**Предпочитать:** этот якорь (v4.5) + код на `origin/master`. При конфликте текст ↔ код — **править текст** или реализовывать по коду, не по устаревшему абзацу.

**НЕ ДЕЛАТЬ:**

- Реализовывать фичи **только** из `docs/reports/` или `docs/prompts/` без проверки master
- Считать поведение **открытого PR** уже находящимся на master
- Возвращать shadow / legacy / dual как «безопасный default» — на текущем master их нет
- Восстанавливать shared `referents.local_inbox` / `local_outbox` as routing, watch, notify, or `_resolve_local_recipients` owners
- Prefill relationship `local_referent_email` from `referents.local_inbox`, or create incomplete `clients` from a legacy `client_email` on referent create
- Менять архитектуру пула воркеров
- Удалять OAuth2
- Возвращаться к «1 референт = 1 поток»
- Заменять пул БД на одиночные подключения
- Ослаблять systemd hardening
- Давать `www-data` доступ к Maildir / группе `vmail`
- Нарушать совместимость PHP `Cryptor` ↔ Python `Cryptor`
- Доверять `X-Real-IP` без проверки `REMOTE_ADDR`
- Удалять или обходить `_validate_account_settings()` (FIX P7)
- Обходить write-before-delete journal invariant (ADR-001)

---

## 11. Критерии готовности к Production

| Задача | Статус |
|--------|--------|
| Лимиты 150 МБ (configure_limits v2.0) | Закрыт |
| Инсталлятор v3.1.0 (FIX-1…FIX-8) | Закрыт |
| Синхронизация демон ↔ schema.sql (FIX-3.2-1) | Закрыт v3.2 |
| Структура `web/` (FIX-3.2-2) | Закрыт v3.2 |
| Live-only routing + relationship watch (79.1) | Закрыт |
| Message rebuild + attachment gates (77 / 79.2) | Закрыт |
| Mail-passage journal (ADR-001 / 79.2) | Закрыт |
| Daemon log permissions (78) | Закрыт (reboot V4 deferred to maintenance) |
| Relationship sole mailbox ownership (PR #89) | Закрыт |
| Nullable legacy `referents.local_*` + activation (PROMPT-83) | Закрыт |
| P4 — imaplib.fetch RAM | **Частично закрыт** (pre-fetch size guard; imaplib буфер для писем ≤ лимита) |
| P7 — whitelist encryption | **Закрыт (FIX P7)** |

---

## 12. Реестр открытых архитектурных пробелов (актуально на v4.5)

Закрыто на master и **не** числится открытым: PROMPT-77 rebuild, PROMPT-78 log perms, PROMPT-79.1 live-only cutover, PROMPT-79.2 journal/disposal, Stage 2a/2b/2c shadow dual-run, PROMPT-83 nullable locals + activation, PR #89 relationship sole mailbox ownership.

| Группа | Открытые пункты |
|--------|-----------------|
| **P4 inbound RAM** | `imaplib` всё ещё буферизует принятые RFC822 ≤ `MAX_INBOUND_MESSAGE_BYTES` (§7) |
| **Schema cleanup** | DB columns `referents.inbound_routing_mode` / `outbound_*` retained but unused by daemon after 79.1 (cleanup deferred historically as PROMPT-79.4); legacy `referents.local_*` columns retained as nullable non-routing |
| **Isolation / scale** | Option B multi-instance daemon still deferred (§13); confirm operator isolation needs before designing |
| **Hardening** | Fan-out duplicate suppression on IMAP retry (NG-7) remains deferred hardening if still absent in code |

Do **not** treat open PR branches (e.g. UI polish) as master requirements until merged.

**Historical cutover plan:** [`docs/reports/PROMPT-68-cutover-readiness-plan.md`](reports/PROMPT-68-cutover-readiness-plan.md) — written when mode flips were process-global; production is now live-only via drop-in.

**PROMPT-70:** симметричные коллизии путей Maildir должны блокироваться на provisioning — см. [`docs/reports/PROMPT-70-provisioning-path-guard.md`](reports/PROMPT-70-provisioning-path-guard.md) (historical report; verify against current panel guards).

---

## 13. Decision record — per-referent mode granularity (PROMPT-71)

**Status:** Historical decision record. Option A was implemented in PROMPT-73, then **routing overrides / shadow paths were removed from the daemon in PROMPT-79.1**. Schema override columns remain; panel no longer edits them. Do **not** treat the old «design only; not implemented» line as current work.  
**Full analysis:** [`docs/reports/PROMPT-71-mode-granularity-decision.md`](reports/PROMPT-71-mode-granularity-decision.md)  
**Verified against:** historical baseline noted in that report; current behaviour = §0.1 / §3.1–§3.2

### Decision (historical summary)

**Option A — single daemon process, DB-driven per-referent overrides** was the accepted cutover approach for staggered pilots.

**Option B (multi-instance / systemd template / referent partition) remains deferred** until operator scale or failure-isolation requirements justify it.

### Superseding note — PROMPT-72 (2026-09-11)

**Full reassessment:** [`docs/reports/PROMPT-72-scale-reassessment.md`](reports/PROMPT-72-scale-reassessment.md)

Scale clause for Option B was met in lab planning (dozens of referents); isolation need remains unconfirmed. Staggered cutover used Option A during pilot; production on current master is process-global live-only via `routing.conf`.

**PROMPT-71 record above is preserved for audit; production invariants are in §0.1.**

---

## 14. Пилотный cutover — референт №1 (PROMPT-75) [исторический]

**Полный отчёт:** [`docs/reports/PROMPT-75-pilot-cutover-referent-1.md`](reports/PROMPT-75-pilot-cutover-referent-1.md)

Первый live-пилот референта №1 от 2026-09-14 описан в этом отчёте (режимы и вердикт — как указано там).
Текущие режимы и вердикт пилота — в §18 и §19, не в этом разделе.

---

## 15. Message rebuild specification (PROMPT-76 / PROMPT-76.1)

**Full report:** [`docs/reports/PROMPT-76-message-rebuild-spec.md`](reports/PROMPT-76-message-rebuild-spec.md)

| Field | Value |
|-------|-------|
| **Date** | 2026-09-15 (PROMPT-76.1 revision) |
| **Type** | Design/spec (implementation landed in PROMPT-77; see §16–§18) |
| **Approved target** | Attachment-only rebuild + new From/To per ClientRelationship |
| **Rebuild gate** | **`relationship_live`** per direction |
| **Customer questions** | **CQ-1…CQ-12 closed** — see report §7 |
| **Non-goals at spec time** | Spam delete / journal (later PROMPT-79.2) |

### Inbound fan-out architecture (PROMPT-76.1)

| Aspect | Rule |
|--------|------|
| **Shape** | One internet message with N attachable parts → **N separate** local RFC822 messages, each with **exactly one** attachment |
| **Subject** | Regenerated per child = that attachment's filename (with extension) |
| **Inline parts** | Discarded (CQ-3) |
| **Zero attachments** | Fail closed / dispose path (see §21 on master) |
| **Nested .eml / forward** | `nested_message` disposal (79.2i/j) on master |
| **Atomicity (RD-13)** | All-or-nothing before source dispose; retry may duplicate already-delivered children (NG-7 deferred) |

### Outbound (1:1)

Locally originated messages are **already single-attachment** by house convention. Outbound rebuild is **1:1**. Unexpected multi-attachment outbound → fail closed / dispose.

**Status on master:** Implemented (PROMPT-77 + 79.2 gates). Spec report remains historical detail.

---

## 16. Message rebuild VPS pilot — referent #1 (PROMPT-77.1)

**Full report:** [`docs/reports/PROMPT-77-message-rebuild-implementation.md`](reports/PROMPT-77-message-rebuild-implementation.md) §8

| Field | Value |
|-------|-------|
| **Date** | 2026-09-17 |
| **Host** | Lab VPS `192.0.2.10` |
| **Verdict** | **NOT ACCEPTED** (see report; superseded by §17–§18) |

**Next:** see §17–§18. Historical «PROMPT-78 spam delete» pointer in older text was retargeted — disposal/journal is PROMPT-79.2; PROMPT-78 closed log permissions (§19).

---

## 17. PROMPT-77.2 — Rebuild live defects (PEEK + watch coupling)

**Full report:** [`docs/reports/PROMPT-77-message-rebuild-implementation.md`](reports/PROMPT-77-message-rebuild-implementation.md) §9

| Field | Value |
|-------|-------|
| **Date** | 2026-09-18 |
| **Defect 1** | `FETCH (BODY.PEEK[])` — `\Seen` only via gated `STORE` |
| **Defect 2 fix** | Rebuild pilot required `outbound_watch_mode=relationship_only` |
| **Next** | **PROMPT-77.3** — see §18 |

---

## 18. PROMPT-77.3/77.4 — VPS rebuild pilot closure (PEEK + relationship_only)

**Full report:** [`docs/reports/PROMPT-77-message-rebuild-implementation.md`](reports/PROMPT-77-message-rebuild-implementation.md) §10–§11

| Field | Value |
|-------|-------|
| **Date** | 2026-09-18 (pilot) / 2026-09-21 (observation closure) |
| **Host** | Lab VPS `192.0.2.10` |
| **Live flip** | `relationship_live` / `relationship_live` / **`relationship_only`** |
| **Verdict** | **ACCEPTED** |
| **Next** | ~~PROMPT-78 (daemon log permissions)~~ → see §19 |

---

## 19. PROMPT-78 — Durable daemon log permissions (Issue #23)

**Full report:** [`docs/reports/PROMPT-78-daemon-log-permissions.md`](reports/PROMPT-78-daemon-log-permissions.md)

| Field | Value |
|-------|-------|
| **Issue** | [#23](https://github.com/FF3mail/Proxy_Email/issues/23) — panel `monitor.php` shows `"(недоступен)"` for daemon log after restart/rotation |
| **Fix** | `tmpfiles.d` (setgid `2750` dir + file ACLs); `UMask=0027`; `ExecStartPre=+systemd-tmpfiles`; logrotate `create … mail-proxy-logs` |
| **Target perms** | dir `2750 vmail:mail-proxy-logs`; `mail-proxy-daemon.log` `0640 vmail:mail-proxy-logs`; `web_admin.log` `0660 vmail:mail-proxy-logs` |
| **V4 (reboot)** | **Deferred** — execute at next maintenance window |
| **Verdict** | **ACCEPTED** — V1–V3 pass on lab VPS |

---

## 20. PROMPT-79.1 — Legacy shadow removal (live-only routing)

**Reports:** [`docs/reports/PROMPT-79-0-inventory.md`](reports/PROMPT-79-0-inventory.md), [`docs/reports/PROMPT-79-1-legacy-shadow-removal.md`](reports/PROMPT-79-1-legacy-shadow-removal.md)

| Field | Value |
|-------|-------|
| **Scope** | Remove `relationship_shadow.py`, shadow/legacy/dual routing paths, per-referent mode UI, shadow observability parsing |
| **Daemon** | `relationship_routing.py` live-only; `mail-proxy-daemon.py` relationship-only watches; override cache removed |
| **Production modes** | `INBOUND_ROUTING_MODE=relationship_live`, `OUTBOUND_ROUTING_MODE=relationship_live`, `OUTBOUND_WATCH_MODE=relationship_only` via `mail-proxy.service.d/routing.conf` |
| **Panel** | Referent form no longer edits routing override columns; `relationship-status.php` → mail-passage journal views (PROMPT-79.2) |
| **Schema** | DB override columns retained (cleanup deferred) |
| **Verdict** | Landed on master — current production default |

---

## 21. PROMPT-79.2 — Mail-passage journal and disposal

**Reports:** [`docs/reports/PROMPT-79-2-mail-passage-journal.md`](reports/PROMPT-79-2-mail-passage-journal.md)  
**ADR:** [`docs/decisions/ADR-001-mail-passage-journal.md`](decisions/ADR-001-mail-passage-journal.md)  
**Issue:** [#26](https://github.com/FF3mail/Proxy_Email/issues/26) (case-insensitive subject/extension rules)

| Field | Value |
|-------|-------|
| **Storage** | MySQL `mail_passage_journal` via `004` + `005` (`skipped` event + `detail` VARCHAR(1024)) |
| **Timestamps** | `event_ts` / `received_at` / `action_at` — UTC in application code; naive `DATETIME(0)`; never SQL `NOW()` |
| **One row** | Per delivered recipient/child and per disposal / skipped event |
| **Disposal** | `no_relationship` / `relationship_inactive` / attachment gates (`zero_attachments`, `disallowed_extension`, `subject_mismatch`, `too_many_attachments`, `missing_filename`; outbound `multiple_attachments`; inbound `nested_message`). Skipped → `event_type=skipped` |
| **Inbound attach gate (79.2d/e/i/j)** | Attachment = Disposition contains `attachment` only (inline ignored). Archives ∪ images (no SVG); MAX=20; archive Subject (E2 NFC); `missing_filename` skip; **`nested_message`** for `message/rfc822` attachment (opaque, no inner walk) |
| **Fail-closed** | Lookup/MIME/DB errors → leave message (UNSEEN / Maildir intact); never dispose on ambiguity |
| **Write-before-delete** | Journal `INSERT` must succeed before IMAP `\Deleted`+EXPUNGE or Maildir unlink |
| **Notify** | Outbound disposal → local template to referent; inbound-from-internet → silent; `notified` flipped to 1 only after confirmed send |
| **Inactive model** | Deactivated relationship disposed like no-match with distinct reason code |
| **Retention** | 1 year; daily cron `scripts/purge_mail_passage_journal.py` |
| **Panel** | `relationship-status.php` — passage table + nonstandard events from journal only |
| **Verdict** | Landed on master |

---

*Конец документа · DELTA-transit Anchor v4.5*
