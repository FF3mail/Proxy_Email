# DELTA-transit — Якорный документ v3.5

**Статус:** Production Candidate (Stage 2a inbound + Stage 2b outbound routing)  
**Дата:** 2026-09-10  
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
├── relationship_shadow.py           # Stage 1 shadow-mode helpers (PROMPT-58)
├── relationship_routing.py          # Stage 2a inbound routing modes (PROMPT-63)
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
| Pre-fetch size guard в `poll_external_imap()` | **Частично закрыт** — `RFC822.SIZE` (primary), `BODYSTRUCTURE` (defensive fallback) до `fetch(RFC822)` |
| Oversized / unknown-size inbound | **Пропуск** — без полного RFC822 fetch; fail-closed при неизвестном размере |
| Retry / forced `\Seen` | `MAX_SIZE_SKIP_RETRIES` последовательных skip → `UID STORE` `\Seen` (fallback: `STORE` по seq) |
| `imaplib.fetch(num, '(RFC822)')` для принятых писем | **Открыт** — письма ≤ лимита всё ещё буферизуются imaplib в RAM |

Перед каждым `fetch(RFC822)` демон запрашивает `(UID RFC822.SIZE)`. Если размер неизвестен или `> MAX_INBOUND_MESSAGE_BYTES` — RFC822 fetch не выполняется. `MAX_INBOUND_MESSAGE_BYTES` задаётся через env (default 200 MiB, согласован с `configure_limits.sh`). Process-local трекер `(account_id, uid)` ограничен `MAX_SIZE_SKIP_TRACKER_ENTRIES`.

BODYSTRUCTURE fallback: только однопартовые структуры без `multipart`; неоднозначный BODYSTRUCTURE → `unknown` (fail-closed), без оценки размера.

> P4 не полностью закрыт: сообщения на или ниже лимита всё ещё полностью буферизуются imaplib при RFC822 fetch.

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
| **Message Transformation** | Attachment-only inbound rebuild; новый From/To per relationship |
| **Spam Handling** | IMAP DELETE/EXPUNGE для unknown external sender |
| **Outbound watch cutover** | Production rollout `OUTBOUND_WATCH_MODE=relationship_only` + `OUTBOUND_ROUTING_MODE=relationship_live` (PROMPT-67+); отключение referent-level watch после стабилизации dual-наблюдения |
| **Operational Hardening** | Panel UI для shadow/routing/watch stats; non-interactive routing mode audit |

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

---

*Конец документа · DELTA-transit Anchor v3.6*
