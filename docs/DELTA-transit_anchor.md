# DELTA-transit — Якорный документ v3.2

**Статус:** Production Candidate  
**Дата:** 2026-06  
**Синхронизирован с:** дистрибутивом v3.2 (после аудита и унификации структуры `includes/` + `web/`)

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
| `MailHandler` | Бизнес-логика IMAP/SMTP |
| `ProxyDaemon` | Lifecycle, watchdog, супервизор |

### Структура дистрибутива

```
DELTA-transit/
├── docs/
│   └── DELTA-transit_anchor.md      # этот документ
├── web/                             # деплой веб-панели (rsync → /var/www/mail-proxy)
│   ├── index.php
│   ├── config.php
│   ├── monitor.php
│   └── includes/
│       ├── helpers.php
│       ├── Cryptor.php
│       ├── oauth2.php
│       └── providers_ui.php
├── includes/                        # зеркало web/includes/ для разработки
├── index.php, config.php, monitor.php   # зеркало корня web/
├── mail-proxy-daemon.py
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
| `clients` | Клиентские email → `referent_id` |
| `external_accounts` | Внешние ящики: IMAP/SMTP, OAuth2 |
| `oauth_tokens` | Токены OAuth2, **UNIQUE(`account_id`)** |
| `oauth_providers` | Google, Yandex, Microsoft (идемпотентный seed) |

### `external_accounts` — важные поля

| Поле | Назначение |
|------|------------|
| `username` | Логин для plain IMAP/SMTP (fallback: `email`) |
| `password_enc` | Единый зашифрованный пароль (AES-GCM / legacy CBC) |
| `auth_type` | `plain` \| `oauth2` |
| `imap_encryption` / `smtp_encryption` | `none` \| `ssl` \| `tls` |

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
| `DB_POOL_SIZE` | 12 |
| `REQUIRE_TLS` | `True` (STARTTLS обязателен для SMTP с `smtp_encryption=tls`) |
| `LOG_FILE` | `/var/log/mail-proxy/mail-proxy-daemon.log` |
| `APP_BASE_URL` | `config.php` — доверенный URL для OAuth redirect_uri |

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
| MariaDB `max_allowed_packet` | 256M |

---

## 7. Исправления v3.2 (синхронизация с кодом)

| ID | Проблема | Решение |
|----|----------|---------|
| FIX-3.2-1 | Демон запрашивал несуществующие колонки `imap_user`/`imap_pass_enc` | SQL → `username`, `password_enc`; хелперы `_plain_auth_login()` / `_plain_auth_password()` |
| FIX-3.2-2 | Разрозненная структура (zip + flat web) | Единый дистрибутив: `web/` для инсталлятора, `includes/` для разработки |
| FIX-3.2-3 | `monitor.php` подключал `helpers.php` из корня | `require_once includes/helpers.php` |
| FIX-3.2-4 | Навигация: `action=providers` не обрабатывался | Алиасы `providers` → `provider_list`, `referents`/`accounts` → dashboard |
| FIX-3.2-5 | `mail-proxy.service` содержал git-артефакты `+` | Удалены из исходника; `mail-proxy-setup.sh` чистит через `sed` |
| FIX-3.2-6 | `writeLog()` молчал после первой ошибки FPM | Убран `static $reportedError` |
| FIX-3.2-7 | Независимый рестарт Postfix/Dovecot при ошибке одного | Joint-restart только если оба `*_OK=true` |
| FIX-3.2-8 | Logrotate-файл без стандартного имени | `logrotate-mail-proxy` |
| FIX-3.2-9 | `delta-transit-install.sh` ожидал flat `web/` | `WEB_FILES` включает `config.php` и `includes/*` |

### P4 — уточнённый статус (частично закрыт)

| Этап | Статус |
|------|--------|
| `_deliver_to_local_smtp()` | **Закрыт** — потоковая передача из временного файла |
| `imaplib.fetch(RFC822)` | **Открыт** — письмо целиком в RAM до записи в `TEMP_DIR` |

Ограничение `imaplib` — штатными средствами потоковый fetch в файл недоступен.

### P7 — открытый риск (без изменений)

`auth_type`, `imap_encryption`, `smtp_encryption` из БД не валидируются whitelist'ом в демоне перед соединением.

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
| Whitelist auth_type/encryption | **Открыт (P7)** |

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

---

## 11. Критерии готовности к Production

| Задача | Статус |
|--------|--------|
| Лимиты 150 МБ (configure_limits v2.0) | Закрыт |
| Инсталлятор v3.1.0 (FIX-1…FIX-8) | Закрыт |
| Синхронизация демон ↔ schema.sql (FIX-3.2-1) | Закрыт v3.2 |
| Структура `web/` + `includes/` (FIX-3.2-2) | Закрыт v3.2 |
| P4 — imaplib.fetch RAM | **Открыт** |
| P7 — whitelist encryption | **Открыт** |

---

*Конец документа · DELTA-transit Anchor v3.2*
