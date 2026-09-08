# 7. Устранение неполадок

## 7.1. Подход к диагностике

Рекомендуемый порядок:

1. **Сервисы** — `systemctl status mail-proxy postfix dovecot mariadb nginx`
2. **Лог демона** — `/var/log/mail-proxy/mail-proxy-daemon.log`
3. **Конфигурация БД** — референт активен? аккаунт активен? корректные host/port?
4. **Права** — `vmail` может писать в Maildir?
5. **Сеть** — исходящий доступ к IMAP/SMTP провайдера?

---

## 7.2. Демон не запускается

### Симптом

```
systemctl status mail-proxy
→ failed / activating
```

### Проверки

```bash
journalctl -u mail-proxy -n 50 --no-pager
python3 /usr/local/bin/mail-proxy-daemon.py   # краткий тест (Ctrl+C)
ls -l /etc/mail-proxy/crypto.key /etc/mail-proxy/db.conf
```

| Причина | Решение |
|---------|---------|
| Нет `crypto.key` или `db.conf` | Переустановите фазу UsersGroups или `mail-proxy-setup.sh` |
| Ошибка подключения к MariaDB | Проверьте пароль в `db.conf`, `systemctl status mariadb` |
| Permission denied на лог | `chown vmail:mail-proxy-logs /var/log/mail-proxy/*.log` |
| systemd hardening / ReadWritePaths | Убедитесь, что путь Maildir в `ReadWritePaths` unit-файла соответствует вашему `/var/vmail/...` |

---

## 7.3. Входящая почта не приходит

### Чек-лист

- [ ] `external_accounts.active = 1`
- [ ] `referents.active = 1`
- [ ] OAuth токен не истёк (dashboard) или пароль plain верный
- [ ] IMAP host/port/encryption верны
- [ ] В логе нет `Invalid imap_encryption` (FIX P7)
- [ ] Письмо не пропущено из-за размера (`IMAP size skip`)

### Диагностика

```bash
grep -i "poll\|imap\|deliver" /var/log/mail-proxy/mail-proxy-daemon.log | tail -30
# Тест IMAP вручную (замените учётные данные)
openssl s_client -connect imap.gmail.com:993 -quiet
```

### Локальная доставка

Если IMAP успешен, но письма нет в ящике:

```bash
grep "$(postconf myhostname)" /var/log/mail.log
doveadm mailbox list -u referent@domain.local
```

---

## 7.4. Исходящая почта не уходит

### Чек-лист

- [ ] `local_outbox` — **правильный абсолютный путь** к Maildir
- [ ] Файл появился в `cur/` или `new/` outbox (зависит от клиента)
- [ ] SMTP host/port/encryption верны
- [ ] `REQUIRE_TLS=True` — для порта 587 нужен `smtp_encryption=tls`
- [ ] Провайдер не блокирует SMTP (Gmail — OAuth или app password)

```bash
grep -i "smtp\|maildir\|outgoing" /var/log/mail-proxy/mail-proxy-daemon.log | tail -30
```

---

## 7.5. OAuth2: токен не сохраняется / истекает

| Симптом | Возможная причина |
|---------|-------------------|
| Redirect error у провайдера | Неверный `APP_BASE_URL` или redirect URI |
| `assertSafeOAuthEndpoint` в логе | Попытка использовать private IP в endpoint |
| Токен сразу «Истёк» | Нет refresh token (Google: `access_type=offline`, `prompt=consent`) |
| Ошибка после смены URL | Обновите redirect URI в консоли провайдера и `config.php` |

```bash
tail -50 /var/log/mail-proxy/web_admin.log
mysql mail_proxy -e "SELECT account_id, expires_at FROM oauth_tokens;"
```

---

## 7.6. Веб-панель: 403 Access Denied

Панель отклоняет IP вне частных сетей.

**Решение:** подключитесь из LAN/VPN или через SSH-туннель. Не отключайте проверку без понимания рисков.

Если вы **в** локальной сети, но всё равно 403:

- Проверьте, что Nginx передаёт `$remote_addr`, а не подменённый заголовок
- Проверьте `getClientIp()` — при доступе через reverse proxy `REMOTE_ADDR` должен быть `127.0.0.1`

---

## 7.7. Ошибка установки: /var/vmail ownership

```
Refusing to harden /var/vmail until ownership is vmail:vmail (found root:root)
```

**Не игнорируйте** — это защита от поломки Dovecot.

**Варианты:**

1. Оставить `root:root` и пропустить hardening (доставка iRedMail работает)
2. После согласования с политикой: `chown vmail:vmail /var/vmail` и повторить установку

---

## 7.8. Большие вложения отклоняются

Проверьте согласованность лимитов:

```bash
postconf message_size_limit
grep client_max_body_size /etc/nginx/sites-enabled/mail-proxy.conf
php -i | grep -E "upload_max|post_max"
```

Запустите `./configure_limits.sh` и перезапустите сервисы.

На IMAP стороне — `MAX_INBOUND_MESSAGE_BYTES` в unit-файле.

---

## 7.9. MariaDB: connection errors

```bash
mysql -u mail_proxy -p mail_proxy -e "SELECT 1"
```

Проверьте `max_connections`, место на диске, права на `/var/lib/mysql`.

---

## 7.10. Nginx / PHP ошибки

```bash
nginx -t
tail -20 /var/log/nginx/error.log
tail -20 /var/log/php*-fpm.log
```

Частая причина: неверный socket PHP-FPM — инсталлятор определяет версию автоматически; при ручной смене PHP обновите `fastcgi_pass` в конфиге Nginx.

---

## 7.11. Когда обращаться к разработчикам

Соберите пакет:

1. Версия из `/root/delta-transit-install-report.txt`
2. Фрагмент лога (без паролей): последние 200 строк `mail-proxy-daemon.log`
3. Описание референта (без секретов): провайдер, auth_type, шифрование
4. Результат `verify-install-regression.sh`

---

*Предыдущий: [06-operations.md](06-operations.md) · Следующий: [08-security.md](08-security.md)*
