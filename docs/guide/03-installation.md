# 3. Установка DELTA-transit

## 3.1. Два способа установки

| Способ | Скрипт | Когда использовать |
|--------|--------|-------------------|
| **Полная установка** | `delta-transit-install.sh` | Новый сервер или первое развёртывание (рекомендуется) |
| **Только демон** | `mail-proxy-setup.sh` | Демон на уже настроенном хосте, панель и БД настроены вручную |

Далее описан **полный** путь через `delta-transit-install.sh`.

---

## 3.2. Подготовка

### Шаг 1. Скопируйте дистрибутив на сервер

```bash
cd /root
git clone https://github.com/FF3mail/Proxy_Email.git
cd Proxy_Email
```

Или скопируйте архив и распакуйте в каталог, где лежат `mail-proxy-daemon.py`, `schema.sql`, папка `web/`.

### Шаг 2. Убедитесь, что базовая почта работает

```bash
# Пример: отправка тестового письма локальному пользователю
echo "test" | mail -s "preflight" user@yourdomain.local
```

Проверьте появление письма в Maildir или через IMAP-клиент.

### Шаг 3. (Опционально) Переменные окружения

```bash
# Больше воркеров PHP-FPM на мощном сервере
export PHP_FPM_MAX_CHILDREN=20

# Зеркало pip при медленном PyPI
export PARAM_PIP_MIRROR="https://pypi.org/simple/"
```

---

## 3.3. Запуск инсталлятора

```bash
chmod +x delta-transit-install.sh configure_limits.sh
./delta-transit-install.sh
```

Скрипт запросит:

1. **Public URL** — HTTPS-адрес панели (не `https://mail-proxy.local`)
2. **Пароль root MariaDB** — если ещё не задан
3. **Зеркало pip** — Enter для стандартного PyPI
4. **Учётная запись master панели** — если в БД ещё нет активного `panel_admins` с `role='master'`:
   - `Panel master username:` — имя оператора (до 100 символов)
   - `Panel master password:` / `Confirm panel master password:` — скрытый ввод, минимум 8 символов

> **Комментарий:** скрипт интерактивный. Без интерактивного TTY и без уже существующего активного master установщик **прервётся в Preflight** с инструкцией по ручному seed (см. [05-web-panel.md](05-web-panel.md) §5.2). Для автоматизации URL изучите `ask_public_url` — при необходимости можно заранее экспортировать `PARAM_APP_URL`.

---

## 3.4. Фазы установки

Инсталлятор выполняет фазы по порядку:

| Фаза | Что делает |
|------|------------|
| Preflight | Проверка файлов, запрос URL и паролей |
| Packages | apt-пакеты, пользователь `vmail`, каталоги |
| Database | Создание БД, импорт `schema.sql`, `db.conf` |
| UsersGroups | Группы `mail-proxy-crypto`, `mail-proxy-logs`, права на `/var/vmail` |
| Python | venv, `pip install -r requirements.txt`, копирование демона |
| Web | rsync `web/` → `/var/www/mail-proxy`, `APP_BASE_URL` в config.php |
| Postfix | Базовые настройки для локальной доставки |
| Dovecot | Проверка/настройка Maildir |
| PHP | Лимиты upload, FPM pool |
| Nginx | Виртуальный хост; интерактивный выбор TLS: self-signed (по умолчанию), существующие файлы сертификата или certbot/Let's Encrypt (если hostname панели резолвится в публичный IPv4) |
| Systemd | `mail-proxy.service`, logrotate |
| Validation | Проверка сервисов и логов |
| Audit | Права crypto.key, изоляция www-data от Maildir |

В конце выводится отчёт и пути к файлам:

```
/root/delta-transit-install-report.txt
/etc/mail-proxy/install-secrets.txt
```

**Сохраните `install-secrets.txt` в защищённое хранилище** и удалите с сервера после переноса паролей, если политика безопасности это требует.

---

## 3.5. Настройка лимитов (отдельный шаг)

Если лимиты не были применены внутри инсталлятора или вы обновляете существующий хост:

```bash
./configure_limits.sh
```

Скрипт настраивает Postfix, Dovecot, MariaDB, Nginx и PHP-FPM для вложений **до 150 МБ** (SMTP ~200 МБ). Перед изменением создаёт резервные копии конфигов с суффиксом `.bak_YYYYMMDD_…`.

Postfix и Dovecot перезапускаются **только если оба** прошли проверку конфигурации — это защита от ситуации «один сервис сломан, второй перезапущен».

### Интерактивные запросы (обязательны)

После настройки `max_allowed_packet` в MariaDB скрипт **останавливается и ждёт ввода с клавиатуры**. Без ответов он не продолжит настройку Nginx/PHP-FPM и перезапуск служб.

**Запрос 1 — пароль root MariaDB**

```
MySQL root password:
```

- Ввод **скрыт** (символы не отображаются).
- Используется тот же пароль `root`, что при первоначальной установке MariaDB / iRedMail.
- Нужен для `UPDATE vmail.mailbox SET quota = …` от имени `mysql -u root`.

**Запрос 2 — выбор почтовых ящиков**

```
Email-адреса через запятую или --all-referents:
```

Допустимы **два варианта**:

1. **Список через запятую** — например `referent1@example.com, referent2@example.com`. Скрипт выполняет  
   `UPDATE vmail.mailbox SET quota = 10240 WHERE username IN ('…');`
2. **Флаг `--all-referents`** — дополнительный запрос `Домен:` и SQL  
   `UPDATE vmail.mailbox SET quota = 10240 WHERE username LIKE '%@<домен>';`  
   (квота для всех ящиков домена).

В колонке `vmail.mailbox.quota` (iRedMail) значение хранится в **мегабайтах**; `10240` = **10 ГБ** дисковой квоты на ящик (отдельно от лимитов Postfix/Nginx на размер вложения).

После запроса выводится `Updated mailbox rows: N`. При `N = 0` проверьте адреса или домен.

> **Не для автоматизации:** у `configure_limits.sh` **нет флагов** для пароля или списка ящиков. Перенаправление stdin (`echo … | ./configure_limits.sh`) и запуск из cron/CI **не поддерживаются** — оператор должен отвечать на запросы вручную в интерактивной SSH-сессии.

## 3.6. Проверка после установки

### Быстрая проверка сервисов

```bash
systemctl status mail-proxy mariadb nginx postfix dovecot
```

### Регрессионный скрипт

```bash
chmod +x verify-install-regression.sh
./verify-install-regression.sh
```

Ожидается вывод `[PASS]` по всем пунктам. При `[FAIL]` — см. [07-troubleshooting.md](07-troubleshooting.md).

### Проверка панели

Откройте в браузере с машины из локальной сети:

```
https://<hostname-панели>/index.php?action=login
```

Войдите учётной записью **master**, созданной установщиком (см. §3.3). Без активного master форма входа покажет жёлтое предупреждение — повторно запустите `delta-transit-install.sh` интерактивно или выполните ручной seed (см. [05-web-panel.md](05-web-panel.md) §5.2).

### TLS-сертификат панели (фаза Nginx)

При **интерактивной** установке (есть TTY) в фазе Nginx установщик предлагает источник сертификата:

```
TLS certificate source:
  1) self-signed (default, test/lab only)
  2) existing certificate files (prompt for paths)
  3) certbot / Let's Encrypt (<hostname> resolves to a public IP)   # только если A-запись указывает на публичный IPv4
```

- **1 — self-signed (по умолчанию):** сертификат в `/etc/ssl/certs/mail-proxy.crt` и ключ в `/etc/ssl/private/mail-proxy.key`; браузер предупредит — для пилота это нормально.
- **2 — существующие файлы:** установщик запросит пути к `fullchain`/`cert` и `privkey`.
- **3 — certbot / Let's Encrypt:** доступен, если hostname панели (из `APP_BASE_URL`) резолвится в **публичный** IPv4; при отсутствии `certbot` установщик может предложить установку через `apt`. Выпуск через standalone HTTP-01 (Nginx на время останавливается). При сбое — откат к запросу путей существующего сертификата или к self-signed.

Без TTY (pipe, CI) установщик **не задаёт** этот вопрос и применяет self-signed или переиспользует файлы по путям по умолчанию (`SSL_MODE=self-signed` / `existing` в отчёте установки).

Для production с публичным DNS предпочтительны **certbot** (вариант 3) или корпоративный CA (вариант 2).

---

## 3.7. Типичные проблемы при установке

### Ошибка владельца /var/vmail

```
Refusing to harden /var/vmail until ownership is vmail:vmail (found root:root)
```

**Причина:** iRedMail часто держит `/var/vmail` как `root:root`, а Dovecot доставляет почту от пользователя из `doveconf mail_uid` / `mail_gid` (обычно `vmail:vmail`).  
**Это жёсткая остановка:** установщик вызывает `fatal()` и **не продолжит** фазы UsersGroups и далее, пока владелец не совпадёт с почтовым пользователем Dovecot. Игнорировать сообщение нельзя — установка останется незавершённой.

**Действие:**

1. Уточните ожидаемого владельца: `doveconf -h mail_uid mail_gid`
2. Если для вашей схемы iRedMail владелец должен быть `vmail:vmail`, исправьте **осознанно** (по документации iRedMail / политике бэкапов), например: `chown vmail:vmail /var/vmail`
3. **Полностью перезапустите** `./delta-transit-install.sh` и дождитесь прохождения фазы UsersGroups

Установщик **не** выполняет `chown` на существующем хранилище автоматически.

### Конфликт Nginx

Инсталлятор проверяет конфликты `server_name`. При `NGINX_CONFLICTS=yes` просмотрите существующие сайты в `/etc/nginx/sites-enabled/`.

### Placeholder URL

`APP_BASE_URL` не может остаться `https://mail-proxy.local` — укажите реальный HTTPS-URL при установке.

---

## 3.8. Установка только демона (`mail-proxy-setup.sh`)

Используйте, если веб-панель и MariaDB уже настроены:

```bash
./mail-proxy-setup.sh
```

Скрипт:

- Ставит Python-зависимости (системный pip)
- Создаёт `/etc/mail-proxy/`, группы, `crypto.key` при отсутствии
- Копирует демон в `/usr/local/bin/`
- Устанавливает systemd unit

После этого вручную проверьте `db.conf`, права и `systemctl enable --now mail-proxy`.

---

## 3.9. Режимы только валидации

Для повторной проверки без переустановки:

```bash
# Только фаза validation
DELTA_VALIDATION_ONLY=1 ./delta-transit-install.sh

# Проверка runtime-лога демона
DELTA_VALIDATION_LOG_ONLY=1 ./delta-transit-install.sh
```

---

## 3.10. Следующие шаги

1. Настройте референтов в панели — [05-web-panel.md](05-web-panel.md)
2. Пройдите чек-лист — [10-deployment-checklist.md](10-deployment-checklist.md)
3. Настройте резервное копирование — [09-backup-restore.md](09-backup-restore.md)

---

*Предыдущий: [02-requirements.md](02-requirements.md) · Следующий: [04-configuration.md](04-configuration.md)*
