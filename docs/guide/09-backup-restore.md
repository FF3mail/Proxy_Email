# 9. Резервное копирование и восстановление

## 9.1. Что обязательно бэкапить

| Объект | Почему критично | Частота |
|--------|-----------------|---------|
| `/etc/mail-proxy/crypto.key` | Без него не расшифровать пароли и токены | При создании + при ротации |
| `/etc/mail-proxy/db.conf` | Подключение к БД | При изменении |
| База `mail_proxy` | Вся конфигурация референтов | Ежедневно |
| `/var/vmail/` (Maildir) | Содержимое почты референтов | По политике почты |
| `/var/www/mail-proxy/config.php` | `APP_BASE_URL` | При изменении |
| `/root/delta-transit-install-report.txt` | Метаданные установки | После установки |

**Без `crypto.key` восстановление БД бесполезно** — зашифрованные поля останутся нечитаемыми.

---

## 9.2. Резервная копия MariaDB

```bash
# Полный дамп
mysqldump -u root -p \
  --single-transaction \
  --routines \
  mail_proxy > /backup/mail_proxy_$(date +%Y%m%d).sql

# Сжатие
gzip /backup/mail_proxy_$(date +%Y%m%d).sql
```

Автоматизация (cron, ежедневно в 02:00):

```bash
0 2 * * * root mysqldump -u root -p'ПАРОЛЬ' --single-transaction mail_proxy | gzip > /backup/mail_proxy_$(date +\%Y\%m\%d).sql.gz
```

> Храните пароль root MySQL в защищённом файле (`/root/.my.cnf` с chmod 600), не в открытом cron.

---

## 9.3. Резервная копия crypto.key

```bash
install -d -m 0700 /root/backup-mail-proxy
cp -a /etc/mail-proxy/crypto.key /root/backup-mail-proxy/crypto.key.$(date +%Y%m%d)
chmod 600 /root/backup-mail-proxy/*
```

**Рекомендация:** копируйте ключ на **отдельный** носитель или в vault (HashiCorp Vault, зашифрованный S3). Не храните ключ только на том же диске, что и БД.

---

## 9.4. Резервная копия конфигурации

```bash
tar czf /backup/mail-proxy-config_$(date +%Y%m%d).tar.gz \
  /etc/mail-proxy/ \
  /etc/nginx/sites-available/mail-proxy.conf \
  /etc/systemd/system/mail-proxy.service \
  /var/www/mail-proxy/config.php
```

---

## 9.5. Maildir

Объём может быть большим. Варианты:

- **rsync** инкрементальный: `rsync -a /var/vmail/ backup@server:/backup/vmail/`
- **Снапшоты** LVM/ZFS/btrfs на уровне СХД
- Политика iRedMail / корпоративная политика хранения почты

DELTA-transit не добавляет отдельное хранилище — почта лежит в стандартном Maildir Dovecot.

---

## 9.6. Восстановление на том же сервере

### Шаг 1. Остановите демон

```bash
systemctl stop mail-proxy
```

### Шаг 2. Восстановите crypto.key

```bash
cp /backup/crypto.key /etc/mail-proxy/crypto.key
chown root:mail-proxy-crypto /etc/mail-proxy/crypto.key
chmod 0640 /etc/mail-proxy/crypto.key
```

### Шаг 3. Восстановите БД

```bash
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS mail_proxy;"
gunzip -c /backup/mail_proxy_YYYYMMDD.sql.gz | mysql -u root -p mail_proxy
```

### Шаг 4. Проверьте db.conf

```bash
mysql -u mail_proxy -p mail_proxy -e "SELECT COUNT(*) FROM referents;"
```

### Шаг 5. Запустите демон

```bash
systemctl start mail-proxy
tail -f /var/log/mail-proxy/mail-proxy-daemon.log
```

---

## 9.7. Восстановление на новом сервере

1. Установите базовый стек (iRedMail) и DELTA-transit (`delta-transit-install.sh`)
2. **Остановите** демон
3. Замените **новый** `crypto.key` на **старый** с бэкапа
4. Импортируйте дамп `mail_proxy`
5. Восстановите Maildir в `/var/vmail/` (те же пути, что в `referents.local_outbox`)
6. Обновите `APP_BASE_URL` в config.php при смене DNS
7. Обновите OAuth redirect URI у провайдеров
8. Запустите демон и пройдите [10-deployment-checklist.md](10-deployment-checklist.md)

> При смене `crypto.key` на новом сервере без старого ключа зашифрованные поля в БД не восстановить.

---

## 9.8. Тестовое восстановление

**Обязательно** раз в квартал (или по политике):

1. На тестовой VM восстановите дамп + ключ
2. Запустите демон
3. Проверьте расшифровку: откройте аккаунт в панели, убедитесь что OAuth/plain работает
4. Зафиксируйте время восстановления (RTO)

---

## 9.9. Что не входит в стандартный бэкап

- Очереди демона в памяти (при crash необработанные задачи теряются)
- Временные файлы `/var/spool/mail-proxy/tmp/` (эфемерные)
- Журнал systemd (ротируется отдельно)

---

## 9.10. Удаление секретов после установки

Файл `/etc/mail-proxy/install-secrets.txt` содержит сгенерированные пароли. После переноса в password manager:

```bash
shred -u /etc/mail-proxy/install-secrets.txt   # если политика требует
```

---

*Предыдущий: [08-security.md](08-security.md) · Следующий: [10-deployment-checklist.md](10-deployment-checklist.md)*
