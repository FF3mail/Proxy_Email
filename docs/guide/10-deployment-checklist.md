# 10. Чек-лист развёртывания (пилот)

> Версия 4.0 — синхронизирован с кодом и документацией 2026-09-07.  
> Заменяет устаревший `Ckeck-list_00.md` (содержал неверные пути `mailproxy`, `/opt/mail-proxy`, таблицу `users`).

Используйте этот документ **после** установки ([03-installation.md](03-installation.md)) и настройки референтов ([05-web-panel.md](05-web-panel.md)).

---

## 1. Подготовка ОС

- [ ] Поддерживаемый Linux (Debian 11+ / Ubuntu 20.04+)
- [ ] Установлены обновления безопасности
- [ ] Синхронизация времени (NTP/chrony)
- [ ] Корректный hostname и DNS

```bash
hostnamectl
timedatectl status
```

---

## 2. Пользователь сервиса vmail

- [ ] Пользователь `vmail` существует
- [ ] Демон `mail-proxy` работает от `User=vmail` в systemd
- [ ] `www-data` **не** в группе `vmail`

```bash
id vmail
systemctl show mail-proxy -p User
groups www-data
```

---

## 3. Структура каталогов

- [ ] `/opt/delta-transit/venv/` — Python venv
- [ ] `/etc/mail-proxy/` — crypto.key, db.conf
- [ ] `/var/log/mail-proxy/` — логи (vmail:mail-proxy-logs)
- [ ] `/var/spool/mail-proxy/tmp/` — temp (vmail:vmail, 0700)
- [ ] `/var/www/mail-proxy/` — веб-панель
- [ ] Нет каталогов с правами 777

```bash
ls -la /etc/mail-proxy/ /var/log/mail-proxy/ /var/spool/mail-proxy/
```

---

## 4. Python-окружение

- [ ] `/opt/delta-transit/venv/bin/python3` существует
- [ ] Зависимости из requirements.txt установлены

```bash
/opt/delta-transit/venv/bin/pip list | grep -E "cryptography|watchdog|mysql-connector"
```

---

## 5. MariaDB

- [ ] База `mail_proxy` создана
- [ ] Таблицы: `referents`, `clients`, `external_accounts`, `oauth_tokens`, `oauth_providers`, `panel_admins`
- [ ] Подключение из демона и панели работает

```sql
USE mail_proxy;
SHOW TABLES;
SELECT COUNT(*) FROM oauth_providers;  -- ожидается >= 3
```

---

## 6. Шифрование

- [ ] `/etc/mail-proxy/crypto.key` существует (64 hex)
- [ ] Права 0640, владелец root:mail-proxy-crypto
- [ ] Резервная копия ключа в безопасном месте

```bash
stat -c '%a %U:%G' /etc/mail-proxy/crypto.key
```

---

## 7. Systemd

- [ ] Unit установлен: `/etc/systemd/system/mail-proxy.service`
- [ ] Сервис active и enabled
- [ ] Hardening: NoNewPrivileges, ProtectSystem, ProtectHome

```bash
systemctl is-active mail-proxy
systemctl is-enabled mail-proxy
systemctl cat mail-proxy | grep -E "NoNewPrivileges|ProtectSystem|User="
```

---

## 8. Логирование

- [ ] `/var/log/mail-proxy/mail-proxy-daemon.log` создаётся
- [ ] `/var/log/mail-proxy/web_admin.log` доступен для записи PHP
- [ ] logrotate настроен (`/etc/logrotate.d/mail-proxy`)

```bash
journalctl -u mail-proxy -n 20 --no-pager
ls -la /etc/logrotate.d/mail-proxy
```

---

## 9. Веб-панель

- [ ] HTTPS открывается с машины из LAN
- [ ] С внешнего IP — 403 (ожидаемое поведение allow-list)
- [ ] Страница входа (`/index.php?action=login`) доступна из LAN
- [ ] Активный **master** в `panel_admins` (установщик или ручной seed)
- [ ] Вход master успешен; выход и повторный вход работают
- [ ] `APP_BASE_URL` не содержит заглушку `mail-proxy.local`
- [ ] Создание/редактирование референта работает
- [ ] CSRF: формы сохраняются без ошибки токена

```sql
SELECT username, role, active FROM panel_admins;
```

```bash
grep 'Panel login' /var/log/mail-proxy/web_admin.log | tail -5
```

---

## 10. OAuth2 (если используется)

### Google
- [ ] Redirect URI совпадает с `{APP_BASE_URL}/index.php?action=oauth_callback`
- [ ] Токен сохраняется, статус «Активен до …» в dashboard

### Microsoft
- [ ] Авторизация и refresh token

### Yandex
- [ ] Авторизация, получение почты по IMAP

---

## 11. SMTP (исходящая)

- [ ] Письмо без вложения доставлено на внешний адрес
- [ ] Вложение 1 МБ
- [ ] Вложение 10 МБ
- [ ] Вложение 50 МБ (при необходимости — 150 МБ)
- [ ] Ошибок SMTP в логе нет

```bash
grep -i error /var/log/mail-proxy/mail-proxy-daemon.log | grep -i smtp | tail -10
```

---

## 12. IMAP (входящая)

- [ ] Входящее письмо появилось в local_inbox
- [ ] Вложения и кириллица в теме корректны
- [ ] Oversized: пропуск до RFC822 fetch, запись в лог (P4)
- [ ] Невалидный auth_type/encryption не открывает соединение (FIX P7)

```bash
grep -E "IMAP size skip|Invalid (auth_type|imap_encryption)" /var/log/mail-proxy/mail-proxy-daemon.log | tail -5
```

---

## 13. /var/vmail (PROMPT-34)

- [ ] Владелец `/var/vmail` согласован с Dovecot (`doveconf mail_uid`)
- [ ] Пользователь почты может traverse каталог

```bash
stat -c '%U:%G' /var/vmail
doveconf mail_uid mail_gid
./verify-install-regression.sh
```

---

## 14. Перезапуск и reboot

- [ ] `systemctl restart mail-proxy` — сервис поднимается, почта продолжает идти
- [ ] После `reboot` все сервисы (mail-proxy, postfix, dovecot, mariadb, nginx) active

---

## 15. Резервное копирование

- [ ] Дамп `mail_proxy` по расписанию
- [ ] Бэкап `crypto.key` отдельно от сервера
- [ ] Тестовое восстановление выполнено ([09-backup-restore.md](09-backup-restore.md))

---

## 16. Мониторинг пилота

- [ ] CPU, RAM, диск — базовые алерты
- [ ] Ежедневный просмотр лога демона
- [ ] `monitor.php` доступен администраторам

---

## Критерии GO (можно подключать пользователей)

- [ ] Все сервисы active после reboot
- [ ] Входящая и исходящая почта работают на тестовых референтах
- [ ] OAuth (если нужен) работает
- [ ] Нет критических ошибок в логах
- [ ] `verify-install-regression.sh` — 0 failures
- [ ] Резервная копия crypto.key подтверждена

---

## Критерии NO-GO

- [ ] Потеря писем при тестах
- [ ] Демон не стартует после reboot
- [ ] OAuth обязателен, но не работает
- [ ] Нет бэкапа crypto.key
- [ ] Постоянные ошибки БД или доставки Postfix/Dovecot

---

## Burn-in (48 часов перед production)

- [ ] Непрерывная работа 48 ч
- [ ] Тестовые send/receive каждые 5–10 мин
- [ ] Память демона стабильна (нет неконтролируемого роста)
- [ ] Логи не заполняют диск
- [ ] Нет деградации задержки доставки

---

*Предыдущий: [09-backup-restore.md](09-backup-restore.md) · Индекс: [README.md](README.md)*
