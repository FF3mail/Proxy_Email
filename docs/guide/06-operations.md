# 6. Эксплуатация и обслуживание

## 6.1. Ежедневные задачи администратора

| Задача | Частота | Команда / место |
|--------|---------|-----------------|
| Проверка статуса демона | Ежедневно | `systemctl status mail-proxy` |
| Просмотр ошибок в логе | Ежедневно | `tail -100 /var/log/mail-proxy/mail-proxy-daemon.log` |
| Мониторинг диска | Ежедневно | `df -h /var/vmail /var/log` |
| Проверка панели | По необходимости | `monitor.php` |
| Ротация логов | Автоматически | logrotate (ежедневно) |

---

## 6.2. Управление сервисом mail-proxy

```bash
# Статус
systemctl status mail-proxy

# Перезапуск (после изменения db.conf или кода демона)
systemctl restart mail-proxy

# Остановка (почта не будет проксироваться)
systemctl stop mail-proxy

# Просмотр журнала systemd
journalctl -u mail-proxy -f
```

**Комментарий:** при `restart` активные IMAP/SMTP-сессии завершаются с таймаутом до 60 с (`TimeoutStopSec`). Письма в локальных очередях демона (в памяти) могут быть потеряны — для планового обслуживания выбирайте время низкой нагрузки.

---

## 6.3. Логи

| Файл | Кто пишет | Содержимое |
|------|-----------|------------|
| `/var/log/mail-proxy/mail-proxy-daemon.log` | Python-демон | IMAP/SMTP, OAuth refresh, ошибки |
| `/var/log/mail-proxy/web_admin.log` | PHP-панель | Админ-действия, CSRF, OAuth |
| `journalctl -u mail-proxy` | systemd | stdout/stderr при старте |

### Полезные фильтры

```bash
# Ошибки за сегодня
grep -i error /var/log/mail-proxy/mail-proxy-daemon.log | tail -50

# OAuth обновление токенов
grep -i oauth /var/log/mail-proxy/mail-proxy-daemon.log | tail -20

# Пропуск больших писем
grep "IMAP size skip" /var/log/mail-proxy/mail-proxy-daemon.log | tail -10
```

### Ротация

Конфиг: `/etc/logrotate.d/mail-proxy` (из `logrotate-mail-proxy`). Хранение 30 дней, сжатие gzip.

---

## 6.4. Обновление демона

1. Остановите сервис (опционально): `systemctl stop mail-proxy`
2. Скопируйте новый `mail-proxy-daemon.py` в `/usr/local/bin/`
3. При изменении зависимостей:

```bash
/opt/delta-transit/venv/bin/pip install -r /path/to/requirements.txt
```

4. `systemctl start mail-proxy`
5. Проверьте лог и `verify-install-regression.sh`

---

## 6.5. Обновление веб-панели

```bash
rsync -a /path/to/Proxy_Email/web/ /var/www/mail-proxy/
# Сохраните config.php с правильным APP_BASE_URL
chown -R root:root /var/www/mail-proxy
find /var/www/mail-proxy -type f -exec chmod 644 {} \;
systemctl reload php*-fpm nginx
```

Не перезаписывайте `config.php` без бэкапа — в нём `APP_BASE_URL`.

---

## 6.6. Добавление нового референта в production

1. Создайте ящик в iRedMail
2. Проверьте доставку тестового письма на `local_inbox`
3. Создайте референта в панели с корректным `local_outbox`
4. Настройте внешний аккаунт
5. Тест: отправьте письмо на внешний ящик → проверьте появление в local_inbox (может занять до 60 с)
6. Тест исходящего: положите письмо в outbox Maildir или отправьте через клиент в папку исходящих

---

## 6.7. Мониторинг ресурсов

```bash
# Память демона
ps aux | grep mail-proxy-daemon

# Открытые файлы (при проблемах с лимитом)
ls -l /proc/$(pgrep -f mail-proxy-daemon)/fd | wc -l

# Размер логов
du -sh /var/log/mail-proxy/
```

При нехватке RAM уменьшите нагрузку (меньше активных референтов) или увеличьте RAM. Изменение `IMAP_WORKER_COUNT` — только с тестированием.

---

## 6.8. Плановое обслуживание MariaDB

```bash
mysqlcheck -u mail_proxy -p mail_proxy
```

Перед major-обновлением MariaDB — полный бэкап (см. [09-backup-restore.md](09-backup-restore.md)).

---

## 6.9. Проверка почтового потока вручную

### Входящий

```bash
# Лог демона в реальном времени
tail -f /var/log/mail-proxy/mail-proxy-daemon.log
# Отправьте тест на внешний ящик, смотрите записи IMAP poll / deliver
```

### Исходящий

```bash
# Проверка очереди Postfix (локальная доставка в outbox)
mailq
# Создайте файл в tmp/new/ Maildir и смотрите SMTP worker в логе
```

---

## 6.10. Регрессионная проверка

После любых значительных изменений:

```bash
./verify-install-regression.sh
```

Все пункты должны быть `[PASS]`.

---

*Предыдущий: [05-web-panel.md](05-web-panel.md) · Следующий: [07-troubleshooting.md](07-troubleshooting.md)*
