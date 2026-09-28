# 8. Безопасность

## 8.1. Модель угроз (кратко)

DELTA-transit хранит **зашифрованные** пароли и OAuth-токены внешних почтовых ящиков и имеет доступ к **всей почте референтов** в Maildir. Основные риски:

| Угроза | Митигация в проекте |
|--------|---------------------|
| Компрометация веб-панели | IP allow-list **и** вход оператора (`panel_admins`); CSRF; www-data ∉ vmail |
| Утечка crypto.key | Права 0640, группа mail-proxy-crypto |
| SSRF через OAuth URL | `assertSafeOAuthEndpoint()` (PHP) + `validate_oauth_endpoint()` (Python) |
| Подмена IP клиента | `getClientIp()` доверяет X-Forwarded-* только от localhost |
| Эскалация через демон | systemd hardening, User=vmail, CapabilityBoundingSet= пусто |
| Перехват трафика | TLS на панели; IMAP ssl / SMTP tls |

---

## 8.2. Изоляция www-data и vmail

**Принцип:** веб-сервер **не должен** читать Maildir пользователей.

Реализация:

- `www-data` в группах `mail-proxy-crypto` (только ключ/БД) и `mail-proxy-logs` (только логи)
- `www-data` **удаляется** из группы `vmail` при установке
- Инсталлятор выполняет audit `validate_www_data_has_no_maildir_access()`

Проверка вручную:

```bash
groups www-data
namei -l /var/vmail/vmail1/  # www-data не должен иметь доступ на чтение
```

---

## 8.3. Шифрование секретов

- Алгоритм: **AES-256-GCM** (префикс `gcm:` в БД), обратная совместимость с legacy AES-CBC
- Ключ: `/etc/mail-proxy/crypto.key` (64 hex-символа = 32 байта)
- PHP (`Cryptor.php`) и Python (`Cryptor` в демоне) используют **один ключ**

**Ротация ключа** не автоматизирована — требует перешифровки всех `password_enc` и токенов. Планируйте отдельной процедурой.

---

## 8.4. systemd hardening демона

Ключевые директивы в `mail-proxy.service`:

| Директива | Эффект |
|-----------|--------|
| `User=vmail` | Непривилегированный пользователь |
| `ProtectSystem=strict` | /usr, /boot read-only |
| `ProtectHome=true` | Нет доступа к /home, /root |
| `ReadWritePaths=...` | Запись только в логи, Maildir, spool |
| `NoNewPrivileges=true` | Запрет повышения привилегий |
| `CapabilityBoundingSet=` | Без Linux capabilities |
| `SystemCallFilter=...` | Ограниченный набор syscall |
| `MemoryDenyWriteExecute=true` | W^X для памяти |

Не ослабляйте hardening без формального обоснования — это требование проекта.

---

## 8.5. Сетевая безопасность и аутентификация панели

### Два слоя доступа

1. **Nginx + PHP allow-list** — внешний клиент вне RFC1918/loopback видит только 403, без формы логина.
2. **Сессия оператора** — `requirePanelAdmin()` на `index.php` и `monitor.php`; пароли в `panel_admins.password_hash` через `password_hash()` PHP (не Cryptor).

Роли: один **master** (seed только установщиком), дополнительные **admin** (создаёт master). UI не может создать второго master или повысить роль — только прямой SQL (принятое ограничение).

### Рекомендации

1. **Не полагайтесь только на allow-list** — всегда используйте сильный пароль master и отдельные учётки admin для каждого оператора
2. Не публикуйте панель в интернет без VPN или корпоративного периметра
3. Используйте **доверенный TLS-сертификат** (не self-signed в production)
4. Ограничьте доступ к MariaDB только с localhost
5. Закройте неиспользуемые порты на firewall

### Nginx: передача IP

См. комментарии в `web/includes/helpers.php`. Используйте:

```nginx
fastcgi_param HTTP_X_REAL_IP $remote_addr;
```

**Запрещено:**

```nginx
fastcgi_param HTTP_X_REAL_IP $http_x_real_ip;  # подмена IP клиентом
```

---

## 8.6. OAuth SSRF

При сохранении OAuth endpoints панель и демон проверяют:

- Только **HTTPS**
- Hostname не localhost, не metadata (169.254.169.254)
- Resolved IP не в private/reserved диапазонах

Это защищает от атак, когда злоумышленник подставляет URL на внутренние сервисы.

---

## 8.7. CSRF

Все изменяющие POST-запросы в `index.php` требуют `csrf_token` из PHP-сессии. OAuth callback — исключение (GET от провайдера).

---

## 8.8. Валидация настроек аккаунтов (FIX P7)

Whitelist `auth_type` и режимов шифрования **до** сетевого соединения предотвращает использование неожиданных значений из БД (в т.ч. после компрометации SQL).

---

## 8.9. Права на файлы (эталон)

| Путь | Права | Владелец |
|------|-------|----------|
| `/etc/mail-proxy/crypto.key` | 0640 | root:mail-proxy-crypto |
| `/etc/mail-proxy/db.conf` | 0640 | root:mail-proxy-crypto |
| `/var/log/mail-proxy/` | 0770 | vmail:mail-proxy-logs |
| `/var/spool/mail-proxy/tmp/` | 0700 | vmail:vmail |
| `/var/www/mail-proxy/` | 644 файлы | root:root |

Каталоги с правами **777** недопустимы — инсталлятор и чек-лист это проверяют.

---

## 8.10. Рекомендации для production

- [ ] Панель доступна только через VPN или корпоративную сеть
- [ ] `crypto.key` в резервной копии в зашифрованном хранилище
- [ ] `install-secrets.txt` удалён с сервера после переноса паролей
- [ ] Регулярные обновления ОС и MariaDB
- [ ] Мониторинг failed login / ошибок в логах
- [ ] Отдельный hostname для панели и для почты (MX)

---

*Предыдущий: [07-troubleshooting.md](07-troubleshooting.md) · Следующий: [09-backup-restore.md](09-backup-restore.md)*
