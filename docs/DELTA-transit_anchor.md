# DELTA-transit — Якорный документ v4.3

**Статус:** Production Candidate / pilot (Stage 2a inbound + Stage 2b outbound routing)  
**Дата:** 2026-09-24  
**Синхронизирован с:** кодом на момент этого обновления (код — источник истины)

---

## 0. Назначение документа

Документ передаёт контекст языковым моделям и подготавливает промпты на доработку.

- Отражает **только текущее состояние** кодовой базы.
- Исторические дефекты (до патчей PROMPT 01–09 и V2.0) не считаются активными.
- Изменения вносятся точечно.

---

## 1. Назначение проекта

DELTA-transit — корпоративный почтовый прокси-шлюз между внешними IMAP/SMTP и локальными Maildir референтов (iRedMail).

| Компонент | Роль |
|-----------|------|
| Postfix | MTA — приём/отправка |
| Dovecot | IMAP — локальная доставка |
| MariaDB | Конфигурация, аккаунты, OAuth2 |
| Nginx + PHP-FPM | Веб-панель |
| Python 3 + venv | Демон `mail-proxy-daemon.py` |
| systemd | Управление сервисом |

---

## 2. Архитектура и карта файлов

### Потоки данных

**Входящий:** `ImapPoller` → IMAP Queue (max 5000) → `ImapWorkerPool` (20) → local SMTP :25  
**Исходящий:** `MaildirHandler` (watchdog) → SMTP Queue (max 1000) → `SmtpWorkerPool` (20) → external SMTP

### Ключевые классы демона

| Класс | Назначение |
|-------|------------|
| `Cryptor` | AES-256-GCM (+ чтение legacy AES-CBC), совместим с PHP |
| `Database` | `MySQLConnectionPool`, `DB_POOL_SIZE=12` |
| `MailHandler` | Бизнес-логика IMAP/SMTP, `_validate_account_settings()` (FIX P7) |
| `ProxyDaemon` | Lifecycle, watchdog, супервизор |

### Структура дистрибутива

```
DELTA-transit/
├── docs/
│   ├── DELTA-transit_anchor.md      # этот документ
│   ├── Ckeck-list_00.md             # чек-лист развёртывания
│   └── prompts/                     # архивные промпты (не источник истины)
├── web/                             # деплой веб-панели (rsync → /var/www/mail-proxy)
│   ├── index.php
│   ├── config.php
│   ├── monitor.php
│   └── includes/
│       ├── helpers.php
│       ├── Cryptor.php
│       ├── oauth2.php
│       └── providers_ui.php
├── mail-proxy-daemon.py
├── relationship_lookup.py           # ClientRelationship lookup (PROMPT-53/54)
├── relationship_routing.py          # Relationship-centric routing (live-only, PROMPT-79.1)
├── mail-proxy.service.d/routing.conf  # Production routing Environment= drop-in
├── mail-proxy.service
├── mail-proxy-setup.sh              # быстрая установка демона (без полного инсталлятора)
├── delta-transit-install.sh         # полный инсталлятор v3.1.0
├── configure_limits.sh              # лимиты Postfix/Dovecot/Nginx/PHP v2.0
├── logrotate-mail-proxy
├── schema.sql
├── requirements.txt
└── test_large_attachment.py
```

| Файл | Назначение |
|------|------------|
| `delta-transit-install.sh` | Полная установка (venv, nginx, systemd, web, audit) |
| `configure_limits.sh` | Настройка лимитов для вложений 150 МБ |
| `logrotate-mail-proxy` | Ротация `/var/log/mail-proxy/*.log` |

---

## 3. Схема базы данных (`mail_proxy`)

| Таблица | Назначение |
|---------|------------|
| `referents` | Референты: `local_inbox`, `local_outbox` |
| `clients` | ClientRelationship: legacy `email` + additive columns (`external_client_email`, `local_client_email`, `local_referent_email`, `external_account_id`, `local_client_maildir`) |
| `external_accounts` | Внешние ящики: IMAP/SMTP, OAuth2 |
| `oauth_tokens` | Токены OAuth2, **UNIQUE(`account_id`)** |
| `oauth_providers` | Google, Yandex, Microsoft (идемпотентный seed) |

> `referents.local_outbox` — абсолютный путь к корню Maildir на диске (не email-адрес);
> должен соответствовать ящику, уже созданному базовой почтовой системой (iRedMail или аналог).

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
| `/var/log/mail-proxy/` | `vmail:mail-proxy-logs` 0750 |
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
| `INBOUND_ROUTING_MODE` | `shadow` (default) \| `legacy` \| `relationship_live` — env, см. §3.1 |
| `OUTBOUND_ROUTING_MODE` | `shadow` (default) \| `legacy` \| `relationship_live` — env, см. §3.2 |
| `OUTBOUND_WATCH_MODE` | `referent_only` (default) \| `dual` \| `relationship_only` — env, см. §3.2 |
| `RELATIONSHIP_LOOKUP_SHADOW` | default **on** в режиме `shadow` — shadow-статистика (PROMPT-58) |

### 3.1 Входящая маршрутизация (Stage 2a, PROMPT-63)

**Реализовано:**

| Компонент | Модуль | Назначение |
|-----------|--------|------------|
| `RelationshipLookup` | `relationship_lookup.py` | Запросы ClientRelationship (inbound/outbound API) |
| Shadow mode | `relationship_shadow.py` | Наблюдение AGREE/DIVERGE без изменения доставки |
| Stage 2a routing | `relationship_routing.py` | Режимы `shadow` / `legacy` / `relationship_live` |
| Панель CRUD | `web/includes/relationship_editor.php` | Редактор связей + legacy backfill |
| Миграция | `migrations/002_client_relationship_columns.sql` | Additive columns на `clients` |

**Контракт inbound lookup (авторитетный):**

```text
resolve_inbound(external_account_id, normalize_email(From))
```

Не используется: To/Cc, Subject, envelope recipient.

**Режимы `INBOUND_ROUTING_MODE`:**

| Режим | Доставка | Shadow |
|-------|----------|--------|
| `shadow` (default) | Legacy To/Cc + fallback `referent.local_inbox` | Да |
| `legacy` | Legacy only | Нет |
| `relationship_live` | `local_referent_email` выбранной связи | Нет |

**Безопасный default:** незаданный `INBOUND_ROUTING_MODE` → `shadow`.

**Rollback:** systemd drop-in `/etc/systemd/system/mail-proxy.service.d/inbound-routing.conf` + `systemctl daemon-reload && systemctl restart mail-proxy`.

**`relationship_live` — текущее поведение:**

| Ситуация | Локальная доставка | IMAP `\Seen` | Legacy fallback |
|----------|-------------------|--------------|-----------------|
| Match | Да → `local_referent_email`, оригинальный RFC822 | При успехе SMTP | Нет |
| Miss (unknown sender) | Нет | Да (interim skip) | **Нет** |
| Lookup/validation error | Нет | Нет (retry) | **Нет** |

**Ещё не реализовано (customer spec / Stage 3+):**

- MIME attachment-only transformation / пересборка RFC822
- IMAP DELETE/EXPUNGE для unknown sender («spam delete»)
- Полное соответствие customer-spec inbound transformation

**Shadow stats:** `/run/mail-proxy/relationship_shadow_stats.json` + лог `[RELATIONSHIP_SHADOW]`.

### 3.2 Исходящая маршрутизация (Stage 2b, PROMPT-65) и watch (Stage 2c, PROMPT-66)

**Watch target (по умолчанию, без изменений):** `referents.local_outbox/new` — `OUTBOUND_WATCH_MODE=referent_only` (default). На тестовом VPS один референт обслуживает две ClientRelationship; детерминизм обеспечивается **не** 1:1 referent→relationship, а ключом сообщения (`From` → `local_client_email`).

**Режимы `OUTBOUND_WATCH_MODE` (независимы от `OUTBOUND_ROUTING_MODE`, PROMPT-66):**

| Режим | Referent `local_outbox/new` | Relationship `local_client_maildir/new` | Назначение |
|-------|----------------------------|----------------------------------------|------------|
| `referent_only` (default) | Да | Нет | Текущее поведение PROMPT-65 |
| `dual` | Да | Да (dedup по пути) | Параллельное наблюдение; `[OUTBOUND_WATCH_DUAL]` при расхождении видимости путей |
| `relationship_only` | Нет | Да | Только с `OUTBOUND_ROUTING_MODE=relationship_live`; иначе fail-closed → `referent_only` |

**Безопасный default:** незаданный `OUTBOUND_WATCH_MODE` → `referent_only`.

**Rollback:** тот же systemd drop-in — `Environment=OUTBOUND_WATCH_MODE=referent_only` + restart (без миграции БД).

**Путь relationship watch:** `RelationshipLookup.list_watch_targets()` → `local_client_maildir/new`. Путь **не** auto-create (в отличие от referent outbox); невалидный путь → log + skip relationship.

**Dual mode:** файлы в `local_client_maildir/new` **наблюдаются и подбираются**; лог `[OUTBOUND_WATCH_DUAL]` фиксирует файлы, видимые только на одном уровне watch. Выбор SMTP-аккаунта по-прежнему определяется только `OUTBOUND_ROUTING_MODE` (shadow/legacy/relationship_live).

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

**Режимы `OUTBOUND_ROUTING_MODE` (независимы от inbound):**

| Режим | Доставка | Shadow |
|-------|----------|--------|
| `shadow` (default) | Legacy `referent_id LIMIT 1` | Да (`[OUTBOUND_RELATIONSHIP_SHADOW]`) |
| `legacy` | Legacy only | Нет |
| `relationship_live` | `external_account_id` выбранной связи | Нет |

**Безопасный default:** незаданный `OUTBOUND_ROUTING_MODE` → `shadow`.

**Rollback:** тот же systemd drop-in `/etc/systemd/system/mail-proxy.service.d/inbound-routing.conf` — добавить `Environment=OUTBOUND_ROUTING_MODE=shadow` + restart.

**`relationship_live` — текущее поведение:**

| Ситуация | Внешняя доставка | Legacy fallback |
|----------|------------------|-----------------|
| Match | Да → SMTP через `dto.account` | Нет |
| Miss (unknown From) | Нет; файл остаётся в `new` (retry) | **Нет** |
| Lookup error | Нет; файл остаётся в `new` (retry) | **Нет** |

**Целевой watch:** `ClientRelationship.local_client_maildir/new` — реализован в `dual` / `relationship_only` (PROMPT-66). Полный production cutover (`relationship_only` + `relationship_live`) — см. §12.

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
| Вызов до IMAP | `poll_external_imap()` — строка ~417, до `imaplib` connect |
| Вызов до SMTP | `send_via_external_smtp()` — строка ~653, до `smtplib` connect |
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

---

## 10. Инструкция для следующей модели

**НЕ ДЕЛАТЬ:**

- Менять архитектуру пула воркеров
- Удалять OAuth2
- Возвращаться к «1 референт = 1 поток»
- Заменять пул БД на одиночные подключения
- Ослаблять systemd hardening
- Давать `www-data` доступ к Maildir / группе `vmail`
- Нарушать совместимость PHP `Cryptor` ↔ Python `Cryptor`
- Доверять `X-Real-IP` без проверки `REMOTE_ADDR`
- Удалять или обходить `_validate_account_settings()` (FIX P7)

---

## 11. Критерии готовности к Production

| Задача | Статус |
|--------|--------|
| Лимиты 150 МБ (configure_limits v2.0) | Закрыт |
| Инсталлятор v3.1.0 (FIX-1…FIX-8) | Закрыт |
| Синхронизация демон ↔ schema.sql (FIX-3.2-1) | Закрыт v3.2 |
| Структура `web/` (FIX-3.2-2) | Закрыт v3.2 |
| P4 — imaplib.fetch RAM | **Частично закрыт** (pre-fetch size guard; imaplib буфер для писем ≤ лимита) |
| P7 — whitelist encryption | **Закрыт (FIX P7)** |

---

## 12. Реестр открытых архитектурных пробелов (PROMPT-66)

| Группа | Открытые пункты |
|--------|-----------------|
| **Routing** | Полная замена legacy To/Cc inbound |
| **Message Transformation** | Inbound 1:N fan-out (N attachments → N single-attachment local messages); outbound 1:1 rebuild; новый From/To per relationship; **NG-7** — fan-out duplicate suppression on IMAP retry (deferred hardening) |
| **Spam Handling** | IMAP DELETE/EXPUNGE для unknown external sender |
| **Outbound watch cutover** | Production rollout `OUTBOUND_WATCH_MODE=relationship_only` + `OUTBOUND_ROUTING_MODE=relationship_live` (PROMPT-67+); отключение referent-level watch после стабилизации dual-наблюдения |
| **Operational Hardening** | Panel UI для shadow/routing/watch stats; non-interactive routing mode audit; ~~daemon log permission drift (Issue #23)~~ → **закрыто PROMPT-78** (§19) |

---

## 13. Decision record — per-referent mode granularity (PROMPT-71)

**Status:** Accepted direction (design only; not implemented).  
**Full analysis:** [`docs/reports/PROMPT-71-mode-granularity-decision.md`](reports/PROMPT-71-mode-granularity-decision.md)  
**Verified against:** `origin/master` @ `b1fb42a`

### Decision

**Option A — single daemon process, DB-driven per-referent overrides** for
`inbound_routing_mode` / `outbound_routing_mode` / `outbound_watch_mode`, with
`NULL` = inherit process-global env defaults.

**Overrides apply only after daemon restart** (not live inside the 60s
`_sync_database_state()` loop). Membership changes (active referent /
valid relationship add-remove) continue to sync live under the
startup-resolved effective modes.

**Option B (multi-instance / systemd template / referent partition) is deferred**
until operator scale or failure-isolation requirements justify it. Unscoped
`ImapPoller` + `_load_referents()` + `list_watch_targets()` would make naïve
multi-instance **incorrect** (duplicate IMAP + duplicate watches), not merely
inefficient.

### Why A over B (summary)

- Motivating gap is **staggered cutover policy**, not process isolation.
- Per-message `plan_*` call sites already receive `referent_data`; routing wiring is local.
- Watch-mode cost is real (loop inversion + filtered watch targets + heterogeneous registries) but bounded if live mode-flips are refused.
- Option B’s partition + PID/log/stats/panel surface is a larger correctness project; scale N is **undocumented** (lab = 1 referent).

### Limitations accepted

- One process remains the shared failure domain.
- Production referent-count expectation is an **open operator question**.

### Reversibility

Nullable overrides are additive; all-`NULL` restores global-env behaviour.
Option B remains possible later and is not foreclosed.

### PROMPT-72 (if proceeding)

Implement Option A schema + startup-resolved effective modes + watch loop
inversion. Do **not** implement live mode transitions, multi-instance units,
or cutover execution in the same PROMPT.

### Superseding note — PROMPT-72 (2026-09-11)

**Full reassessment:** [`docs/reports/PROMPT-72-scale-reassessment.md`](reports/PROMPT-72-scale-reassessment.md)

**What changed:**

| PROMPT-71 assumption | PROMPT-72 finding |
|---------------------|-------------------|
| Scale N undocumented | **25 referents now, 50+ planned**; 125–250 relationships today, ~500 at growth target |
| Option B deferred — scale unknown | **Scale clause of Option B trigger is now met** ("dozens of referents") |
| Isolation need bundled with scale | **Isolation need remains unconfirmed** — must not be inferred from scale alone |

**Revised recommendation (staged):**

- **PROMPT-73 → Option A** (per-referent mode overrides, single process, restart-only). Synthetic verification shows DB/sync/collision paths comfortable at N=50; staggered cutover policy remains the immediate gap.
- **Option B later** when operator confirms crash-isolation requirement **or** production proves IMAP poll cadence miss (~3.2 s avg poll budget at N=50 with 20 workers) or filesystem sync scan exceeds budget.

**Measured at N=50 (lab VPS):** IMAP poller DB 94 ms; sync DB portion 121 ms; collision checks ~3 ms; log-tail 100% coverage at 35% shadow density (degrades to 53% at 10%). IMAP network poll latency **not measured**.

**PROMPT-71 record above is preserved for audit; this note supersedes only the scale-based deferral rationale and PROMPT-72 scope pointer.**

---

## 15. Message rebuild specification (PROMPT-76 / PROMPT-76.1)

**Full report:** [`docs/reports/PROMPT-76-message-rebuild-spec.md`](reports/PROMPT-76-message-rebuild-spec.md)

| Field | Value |
|-------|-------|
| **Date** | 2026-09-15 (PROMPT-76.1 revision) |
| **Type** | Design/spec only (no code) |
| **Code baseline** | `88339c4` — `_stream_file_via_smtp()` still relays **original RFC822** both directions |
| **Approved target** | Attachment-only rebuild + new From/To per ClientRelationship (PROMPT-52 §3.2–3.3, inbound refined) |
| **Primary PDF admin guide** | **Silent** on rebuild semantics (size limits only) |
| **Rebuild gate** | **`relationship_live`** per direction (shadow/legacy remain raw stream); no independent toggle (CQ-11) |
| **Customer questions** | **CQ-1…CQ-12 closed** — see report §7 (resolved decisions table) |
| **Non-goals** | Spam delete (PROMPT-78), full spec reconciliation (PROMPT-79) |

### Inbound fan-out architecture (PROMPT-76.1)

Customer confirmation changed inbound from 1:1 message transformation to **1:N fan-out**:

| Aspect | Rule |
|--------|------|
| **Shape** | One internet message with N attachable parts → **N separate** local RFC822 messages, each with **exactly one** attachment |
| **Subject** | Regenerated per child = that attachment's filename (with extension); original multi-file Subject is never copied |
| **Inline parts** | Discarded (CQ-3) |
| **Zero attachments** | Fail closed — no delivery, UNSEEN (CQ-1/CQ-7) |
| **Nested .eml / forward** | Fail closed as malformed MIME (CQ-9/CQ-12) — no distinct handling |
| **Atomicity (RD-13)** | All-or-nothing: rebuild all N before any SMTP; `\Seen` only when all N deliveries succeed; retry may duplicate already-delivered children |

### Outbound (unchanged shape: 1:1)

Locally originated messages are **already single-attachment** by house convention. Outbound rebuild is **1:1** — no fan-out. Unexpected multi-attachment outbound → fail closed.

**Status:** Spec **ready for PROMPT-77 implementation** (all CQ items closed; RD-13 atomicity decided in spec).

---

## 16. Message rebuild VPS pilot — referent #1 (PROMPT-77.1)

**Full report:** [`docs/reports/PROMPT-77-message-rebuild-implementation.md`](reports/PROMPT-77-message-rebuild-implementation.md) §8

| Field | Value |
|-------|-------|
| **Date** | 2026-09-17 |
| **Host** | Lab VPS `192.168.125.116` |
| **Code merge** | `1d5f8c1` (PR #21, PROMPT-77) deployed to VPS |
| **Isolation** | Shadow-first deploy (overrides cleared → deploy → restart on shadow → then live flip) |
| **Live flip** | `2026-09-17T07:30:16Z` — `relationship_live` / `relationship_live` / `referent_only` |
| **Observation** | 60 min (`07:40:56Z`–`08:40:57Z`); no unexpected `[MESSAGE_REBUILD]` beyond deliberate zero-attach |
| **Verdict** | **NOT ACCEPTED** |
| **Rollback** | Overrides cleared to NULL; effective `shadow`/`shadow`/`referent_only` since `2026-09-17T08:42:17Z` |
| **Blockers for ACCEPTED** | (1) fail-closed does not retain IMAP UNSEEN (`FETCH RFC822` side-effect); (2) inbound fan-out into watched referent outbox echoes rebuilt children outbound |

**Next:** ~~PROMPT-77.2~~ → see §17. ~~PROMPT-77.3~~ → see §18. **PROMPT-78** (spam/unknown-sender deletion) remains after 77.3 closure.

---

## 17. PROMPT-77.2 — Rebuild live defects (PEEK + watch coupling)

**Full report:** [`docs/reports/PROMPT-77-message-rebuild-implementation.md`](reports/PROMPT-77-message-rebuild-implementation.md) §9

| Field | Value |
|-------|-------|
| **Date** | 2026-09-18 |
| **Branch** | `prompt-77-2-rebuild-live-defects` (from `origin/master` `c55aa9e`) |
| **Defect 1** | `FETCH (BODY.PEEK[])` — `\Seen` only via gated `STORE`; regression `tests/test_imap_fetch_seen.py` |
| **Defect 2 investigation** | **Confirmed:** rebuilt `From=local_client_email` matches `resolve_outbound`; raw external From does not — path coupling under `referent_only` now fires |
| **Defect 2 fix** | **Config (option c):** rebuild pilot must use `outbound_watch_mode=relationship_only` (not `referent_only`); no delivery-target or watch-exclusion code change |
| **VPS in this PROMPT** | **No** live flip / no override change — code + docs only |
| **Next** | **PROMPT-77.3** — deploy PEEK, shadow-first, then live with `relationship_live`/`relationship_live`/`relationship_only` |

---

## 18. PROMPT-77.3/77.4 — VPS rebuild pilot closure (PEEK + relationship_only)

**Full report:** [`docs/reports/PROMPT-77-message-rebuild-implementation.md`](reports/PROMPT-77-message-rebuild-implementation.md) §10–§11

| Field | Value |
|-------|-------|
| **Date** | 2026-09-18 (pilot) / 2026-09-21 (observation closure) |
| **Host** | Lab VPS `192.168.125.116` |
| **Code** | `6718ce6` (PR #22) deployed; binaries match repo — no drift vs `origin/master` code |
| **PEEK gate** | **PASS** — independent `BODY.PEEK[]`; UNSEEN retained |
| **Live flip** | `relationship_live` / `relationship_live` / **`relationship_only`** (`2026-09-18T12:04:28Z`) |
| **Topology** | `0` referent watches, `2` relationship maildir paths |
| **Fan-out** | Delivered; children retained in referent Maildir; **no outbound echo** across full window |
| **Outbound inject** | `local_client_maildir/new` only — external delivery confirmed (PROMPT-77.3) |
| **Observation (PROMPT-77.4)** | **60 min completed** — `2026-09-21T07:27:25Z` → `2026-09-21T08:29:35Z` (3730 s wall clock; 60 poll samples) |
| **Rollback** | **Not required** |
| **Verdict** | **ACCEPTED** — full window clean; referent #1 remains live |
| **Next** | ~~PROMPT-78 (daemon log permissions)~~ → see §19 |

---

## 19. PROMPT-78 — Durable daemon log permissions (Issue #23)

**Full report:** [`docs/reports/PROMPT-78-daemon-log-permissions.md`](reports/PROMPT-78-daemon-log-permissions.md)

| Field | Value |
|-------|-------|
| **Issue** | [#23](https://github.com/FF3mail/Proxy_Email/issues/23) — panel `monitor.php` shows `"(недоступен)"` for daemon log after restart/rotation |
| **Root cause** | `logrotate` `create 0640 vmail vmail` + daemon creates `vmail:vmail`; fragile `ExecStartPre chown` fails as unprivileged `vmail` |
| **Fix** | `tmpfiles.d` (setgid `2750` dir + file ACLs); `UMask=0027`; `ExecStartPre=+systemd-tmpfiles`; logrotate `create … mail-proxy-logs` |
| **Target perms** | dir `2750 vmail:mail-proxy-logs`; `mail-proxy-daemon.log` `0640 vmail:mail-proxy-logs`; `web_admin.log` `0660 vmail:mail-proxy-logs` |
| **Invariant** | `/var/log/mail-proxy` **must** retain setgid (`2750`) so new files inherit `mail-proxy-logs`; enforced via `tmpfiles.d` + installers |
| **Operational note** | Forced `logrotate -f` same calendar day as midnight `dateext` rotation fails if `…-YYYYMMDD` archive already exists — remove dated archive first or wait next day |
| **V4 (reboot)** | **Deferred** — `systemd-tmpfiles --cat-config` confirms boot registration; execute at next maintenance window |
| **Pilot state** | referent #1 modes unchanged: `relationship_live` / `relationship_live` / `relationship_only` (§18; pre-existing) |
| **Out of scope** | `message_rebuild.py`, routing/watch, PHP panel code (proposal: distinguish missing vs unreadable in `monitor.php:603`) |
| **Verdict** | **ACCEPTED** — V1–V3 pass on lab VPS (`ac24213`); V4 reboot deferred to maintenance window |

---

## 20. PROMPT-79.1 — Legacy shadow removal (live-only routing)

**Reports:** [`docs/reports/PROMPT-79-0-inventory.md`](reports/PROMPT-79-0-inventory.md), [`docs/reports/PROMPT-79-1-legacy-shadow-removal.md`](reports/PROMPT-79-1-legacy-shadow-removal.md)

| Field | Value |
|-------|-------|
| **Scope** | Remove `relationship_shadow.py`, shadow/legacy/dual routing paths, per-referent mode UI, shadow observability parsing |
| **Daemon** | `relationship_routing.py` live-only; `mail-proxy-daemon.py` relationship-only watches; override cache removed |
| **Production modes** | `INBOUND_ROUTING_MODE=relationship_live`, `OUTBOUND_ROUTING_MODE=relationship_live`, `OUTBOUND_WATCH_MODE=relationship_only` via `mail-proxy.service.d/routing.conf` |
| **Panel** | Referent form no longer edits `inbound_routing_mode` / `outbound_*` columns; `relationship-status.php` → mail-passage journal views (PROMPT-79.2) |
| **Schema** | DB override columns retained (cleanup deferred PROMPT-79.4) |
| **Verdict** | See closure report |

---

## 21. PROMPT-79.2 — Mail-passage journal and disposal

**Reports:** [`docs/reports/PROMPT-79-2-mail-passage-journal.md`](reports/PROMPT-79-2-mail-passage-journal.md)  
**ADR:** [`docs/decisions/ADR-001-mail-passage-journal.md`](decisions/ADR-001-mail-passage-journal.md)  
**Issue:** [#26](https://github.com/FF3mail/Proxy_Email/issues/26) (case-insensitive subject/extension rules)

| Field | Value |
|-------|-------|
| **Storage** | MySQL `mail_passage_journal` via `004` + `005` (`skipped` event + `detail` VARCHAR(1024); `schema.sql` / `003` untouched) |
| **Timestamps** | `event_ts` / `received_at` / `action_at` — UTC computed in application code; naive `DATETIME(0)`; never SQL `NOW()` |
| **One row** | Per delivered recipient/child and per disposal event |
| **Disposal** | Confirmed `no_relationship`, `relationship_inactive`, or invalid attachment (`zero_attachments`, `disallowed_extension`, `subject_mismatch`, `too_many_attachments`; outbound also `multiple_attachments`). Skipped parts → `event_type=skipped` |
| **Inbound attach gate (79.2d)** | One path for N≥1: `classify_inbound_attachments` (limit→subject→extension). Archives ∪ images (no SVG); MAX=20; archive-based Subject (D7); child Subject=filename (D8); skipped journal rows. Report: `PROMPT-79-2d-inbound-extension-subject.md` |
| **Fail-closed** | Lookup/MIME/DB errors → leave message (UNSEEN / Maildir intact); never dispose on ambiguity |
| **Write-before-delete** | Journal `INSERT` must succeed before IMAP `\Deleted`+EXPUNGE or Maildir unlink |
| **Notify** | Outbound disposal → local §4 template to referent; inbound-from-internet → silent; `notified` flipped to 1 only after confirmed send |
| **Inactive model** | Deactivated relationship disposed like no-match with distinct reason code (operator clarification 2026-09-23; decisions log §2 amended) |
| **Retention** | 1 year; daily cron `scripts/purge_mail_passage_journal.py` (independent of debug-log logrotate) |
| **Panel** | `relationship-status.php` — passage table + nonstandard events from journal only |
| **Verdict** | See closure report |

---

*Конец документа · DELTA-transit Anchor v4.3*
