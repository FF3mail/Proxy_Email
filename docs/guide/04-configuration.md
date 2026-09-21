# 4. Конфигурация системы

## 4.1. Обзор конфигурационных файлов

| Файл | Владелец | Права | Содержимое |
|------|----------|-------|------------|
| `/etc/mail-proxy/crypto.key` | root:mail-proxy-crypto | 0640 | 32 байта hex — ключ AES-256-GCM |
| `/etc/mail-proxy/db.conf` | root:mail-proxy-crypto | 0640 | Секция `[db]`: host, user, pass, name |
| `/var/www/mail-proxy/config.php` | root:root | 0644 | `APP_BASE_URL` для OAuth |
| `/etc/systemd/system/mail-proxy.service` | root:root | 0644 | Unit systemd с hardening |
| `/etc/nginx/sites-available/mail-proxy.conf` | root:root | 0644 | Виртуальный хост панели |

> **Никогда не копируйте `crypto.key` между серверами без плана миграции** — зашифрованные пароли на другом сервере станут нечитаемыми.

---

## 4.2. Файл db.conf

Пример (пароль задаётся инсталлятором):

```ini
[db]
db_host = 127.0.0.1
db_user = mail_proxy
db_pass = сгенерированный_пароль
db_name = mail_proxy
```

**Комментарий:** пароль **без** кавычек. Инсталлятор специально избегает кавычек, чтобы Python `configparser` и PHP `parseIniSection()` читали файл одинаково, в том числе при спецсимволах `#`, `=` в пароле.

Проверка чтения:

```bash
python3 -c "import configparser; c=configparser.ConfigParser(); c.read('/etc/mail-proxy/db.conf'); print(c['db']['db_user'])"
```

---

## 4.3. Переменные окружения демона

Задаются в `mail-proxy.service` или через `systemctl edit mail-proxy`:

| Переменная | По умолчанию | Назначение |
|------------|--------------|------------|
| `MAX_INBOUND_MESSAGE_BYTES` | 209715200 (200 MiB) | Макс. размер входящего письма с IMAP |

Пример override:

```bash
systemctl edit mail-proxy
```

```ini
[Service]
Environment=MAX_INBOUND_MESSAGE_BYTES=104857600
```

Затем `systemctl daemon-reload && systemctl restart mail-proxy`.

---

## 4.4. Схема базы данных

База `mail_proxy`, таблицы:

### referents — референты

| Поле | Описание |
|------|----------|
| `username` | Имя для отображения в панели |
| `local_inbox` | Email локального ящика (входящие) |
| `local_outbox` | **Абсолютный путь** к корню Maildir исходящих |

> **Важно:** `local_outbox` — это путь на диске (например `/var/vmail/vmail1/example.com/user/Maildir`), а не email. Путь должен существовать и принадлежать почтовой системе.

### clients — клиентские адреса

Связывает email клиента с `referent_id`.

### external_accounts — внешние ящики

| Поле | Описание |
|------|----------|
| `email` | Адрес внешнего ящика |
| `username` | Логин IMAP/SMTP (если отличается от email) |
| `password_enc` | Зашифрованный пароль (plain auth) |
| `auth_type` | `plain` или `oauth2` |
| `imap_host`, `imap_port`, `imap_encryption` | Параметры IMAP |
| `smtp_host`, `smtp_port`, `smtp_encryption` | Параметры SMTP |
| `client_id`, `client_secret_enc` | Для OAuth2 |

Демон использует **`username` + `password_enc`**, не устаревшие имена колонок `imap_user` / `imap_pass_enc`.

### oauth_tokens

Один токен на аккаунт (`UNIQUE(account_id)`). Демон обновляет access token по refresh token.

### oauth_providers

Предзаполнены: Google, Yandex, Microsoft. Можно редактировать endpoints в панели (с проверкой SSRF).

### panel_admins — операторы панели

| Поле | Описание |
|------|----------|
| `username` | Логин оператора |
| `password_hash` | `password_hash()` PHP (не Cryptor) |
| `role` | `master` (один, seed установщиком) или `admin` |
| `active` | `0` — вход запрещён |

---

## 4.5. Согласованные лимиты (150 МБ вложение)

Значения из `configure_limits.sh`:

| Компонент | Параметр | Значение |
|-----------|----------|----------|
| Postfix | `message_size_limit` | 209715200 (~200 МБ) |
| Postfix | `mailbox_size_limit` | 314572800 (~300 МБ) |
| Nginx | `client_max_body_size` | 210M |
| PHP | `upload_max_filesize` | 200M |
| PHP | `post_max_size` | 210M |
| PHP | `memory_limit` | 512M |
| MariaDB | `max_allowed_packet` | 256M |

После изменения лимитов перезапустите затронутые сервисы (скрипт делает это автоматически).

> **Интерактивные запросы** при запуске `configure_limits.sh` (квоты `vmail.mailbox`) — см. [03-installation.md](03-installation.md) §3.5.

---

## 4.6. Константы демона (обычно не меняют)

| Константа | Значение | Комментарий |
|-----------|----------|-------------|
| `IMAP_POLL_INTERVAL` | 60 с | Интервал опроса внешнего IMAP |
| `IMAP_WORKER_COUNT` | 20 | Параллельные IMAP-задачи |
| `SMTP_WORKER_COUNT` | 20 | Параллельные SMTP-отправки |
| `IMAP_QUEUE_MAXSIZE` | 5000 | Размер очереди в памяти |
| `SMTP_QUEUE_MAXSIZE` | 1000 | |
| `DB_POOL_SIZE` | 12 | Пул соединений MySQL |
| `TEMP_DIR` | `/var/spool/mail-proxy/tmp` | Временные файлы писем |
| `REQUIRE_TLS` | True | STARTTLS обязателен для `smtp_encryption=tls` |

Изменение воркеров требует правки `mail-proxy-daemon.py` и оценки RAM — не делайте без нагрузочного теста.

---

## 4.7. Валидация настроек аккаунта (FIX P7)

Перед каждым IMAP/SMTP-соединением демон проверяет:

- `auth_type` ∈ `plain`, `oauth2`
- `imap_encryption` / `smtp_encryption` ∈ `none`, `ssl`, `tls`

Недопустимое значение в БД → запись в лог, аккаунт **пропускается** (без подстановки «умолчаний»). Это защита от SQL-инъекций и порчи данных.

Проверка в логе:

```bash
grep -E "Invalid (auth_type|imap_encryption|smtp_encryption)" /var/log/mail-proxy/mail-proxy-daemon.log
```

---

## 4.8. Защита от oversized IMAP (P4)

До загрузки RFC822 демон запрашивает `RFC822.SIZE`. Если размер неизвестен или превышает лимит — письмо не загружается. После 3 пропусков подряд UID помечается `\Seen`, чтобы не зациклиться.

Логи:

```bash
grep -E "IMAP size skip|size probe failed|size skip limit reached" /var/log/mail-proxy/mail-proxy-daemon.log | tail -20
```

---

## 4.9. Nginx и передача IP клиента

Для корректной работы `checkLocalNetworkAccess()` Nginx должен передавать реальный IP через FastCGI:

```nginx
fastcgi_param HTTP_X_REAL_IP $remote_addr;
fastcgi_param HTTP_X_FORWARDED_FOR $proxy_add_x_forwarded_for;
```

**Не используйте** `$http_x_real_ip` — клиент сможет подменить IP.

Подробнее — [08-security.md](08-security.md).

---

## 4.10. Ручное создание референта в БД (не рекомендуется)

Предпочтительно — через панель. При экстренном SQL убедитесь, что:

1. `local_inbox` — существующий адрес на Postfix
2. `local_outbox` — существующий путь Maildir с правами `vmail`
3. Пароли шифруются тем же `crypto.key` (через PHP `Cryptor`)

---

*Предыдущий: [03-installation.md](03-installation.md) · Следующий: [05-web-panel.md](05-web-panel.md)*
