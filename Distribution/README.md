# DELTA-transit — дистрибутив для рабочего сервера

Пакет для развёртывания на **Ubuntu + iRedMail** (Postfix / Dovecot / Nginx / MariaDB уже работают).

**Сборка:** 2026-09-25 (UTC)  
**Источник:** `origin/master` @ `e551b71426962c5f632c1e937d2a71de68f15f06`  
**Включены закрытые PR:** #31 (hygiene), #32 (mail-passage journal), #33 (inbound multi-attach / panel tabs).

Скопируйте весь каталог `Distribution/` на сервер (scp/rsync/архив) и работайте из него как из корня проекта.

---

## Состав

| Путь | Назначение |
|------|------------|
| `delta-transit-install.sh` | Полный инсталлятор (рекомендуется) |
| `configure_limits.sh` | Лимиты PHP-FPM / системы |
| `mail-proxy-setup.sh` | Установка только демона (если панель/БД уже настроены) |
| `mail-proxy-daemon.py` + модули `*.py` | Демон и зависимости (см. § «Модули демона») |
| `web/` | Веб-панель → `/var/www/mail-proxy` (вкладки журнала 79.2n) |
| `schema.sql` | Базовая схема БД `mail_proxy` |
| `migrations/` | Доп. миграции (на свежей установке обязательны **004+005**) |
| `mail-proxy.service`, `mail-proxy.service.d/` | systemd |
| `logrotate-mail-proxy`, `tmpfiles.d-mail-proxy.conf` | Ротация логов / tmpfiles |
| `requirements.txt` | Python-зависимости |
| `scripts/purge_mail_passage_journal.py` | Суточная очистка журнала (ADR-001) |
| `docs/guide/` | Руководство оператора (10 глав) |
| `docs/DELTA_transit_admin_guide.pdf` | Справочник администратора |
| `docs/DELTA-transit_anchor.md` | Архитектурный якорь (SoT) |
| `docs/decisions/ADR-001-mail-passage-journal.md` | Журнал прохождения писем |

**Не входит:** тесты, `.keys/`, отчёты разработки (`docs/reports/`), промпты, лабораторные скрипты.

---

## Быстрый старт

### 1. Предпосылки

- Ubuntu 20.04+ / 22.04 / 24.04 с рабочим iRedMail
- Локальная доставка почты уже проверена
- Hostname панели (`PARAM_APP_URL`) **не совпадает** с Postfix `myhostname`  
  (например `https://panel.example.com` при `mail.example.com`)

Подробнее: [docs/guide/02-requirements.md](docs/guide/02-requirements.md)

### 2. Установка

```bash
cd /path/to/Distribution
chmod +x delta-transit-install.sh configure_limits.sh mail-proxy-setup.sh
sudo ./delta-transit-install.sh
sudo ./configure_limits.sh   # при необходимости
```

Инструкция: [docs/guide/03-installation.md](docs/guide/03-installation.md)

### 3. Модули демона (обязательно)

Инсталлятор копирует в `/usr/local/bin/` в основном `mail-proxy-daemon.py`.  
Демон импортирует соседние модули — их тоже нужно положить рядом:

```bash
sudo install -m 0750 -o root -g vmail \
  mail-proxy-daemon.py \
  relationship_lookup.py \
  relationship_routing.py \
  message_rebuild.py \
  attachment_policy.py \
  mail_passage_journal.py \
  mail_disposal.py \
  referent_notify.py \
  /usr/local/bin/

sudo systemctl restart mail-proxy
```

Проверка:

```bash
/opt/delta-transit/venv/bin/python3 -c \
  "import sys; sys.path.insert(0,'/usr/local/bin'); import mail_disposal, message_rebuild; print('OK')"
```

### 4. Миграции журнала (после schema.sql)

Актуальный `schema.sql` **не** содержит таблицу `mail_passage_journal`. После установки БД:

```bash
sudo mysql mail_proxy < migrations/004_mail_passage_journal.sql
sudo mysql mail_proxy < migrations/005_mail_passage_journal_skipped.sql
```

Миграции 002/003 нужны только при обновлении **старых** БД (в актуальном `schema.sql` эти колонки уже есть).

### 5. Очистка журнала (рекомендуется)

```bash
sudo install -m 0750 -o root -g vmail \
  scripts/purge_mail_passage_journal.py \
  /usr/local/bin/purge_mail_passage_journal.py

# cron (пример: ежедневно в 03:15)
# 15 3 * * * /opt/delta-transit/venv/bin/python3 /usr/local/bin/purge_mail_passage_journal.py
```

### 6. GO / NO-GO

Чек-лист: [docs/guide/10-deployment-checklist.md](docs/guide/10-deployment-checklist.md)

---

## Документация (минимум для эксплуатации)

| Документ | Когда |
|----------|--------|
| [docs/guide/README.md](docs/guide/README.md) | Оглавление |
| [docs/guide/03-installation.md](docs/guide/03-installation.md) | Установка |
| [docs/guide/04-configuration.md](docs/guide/04-configuration.md) | Конфиг, БД, лимиты |
| [docs/guide/05-web-panel.md](docs/guide/05-web-panel.md) | Панель, референты, OAuth |
| [docs/guide/06-operations.md](docs/guide/06-operations.md) | Логи, рестарт, обновления |
| [docs/guide/07-troubleshooting.md](docs/guide/07-troubleshooting.md) | Типичные сбои |
| [docs/guide/08-security.md](docs/guide/08-security.md) | Безопасность |
| [docs/guide/09-backup-restore.md](docs/guide/09-backup-restore.md) | Бэкап |
| [docs/guide/10-deployment-checklist.md](docs/guide/10-deployment-checklist.md) | Пилот / GO–NO-GO |
| [docs/DELTA_transit_admin_guide.pdf](docs/DELTA_transit_admin_guide.pdf) | PDF-справочник |
| [docs/decisions/ADR-001-mail-passage-journal.md](docs/decisions/ADR-001-mail-passage-journal.md) | Журнал прохождения писем |

При расхождении текста с кодом приоритет у скриптов и исходников в этом каталоге.

---

## Обновление уже установленного хоста

1. Остановите демон: `systemctl stop mail-proxy`
2. Обновите файлы из нового `Distribution/`
3. Скопируйте модули демона (§3) и при необходимости `rsync -a --exclude=config.php web/ /var/www/mail-proxy/`
4. Примените новые миграции (если ещё не применены 004/005)
5. `systemctl start mail-proxy` и проверьте `journalctl -u mail-proxy -n 50`

Секреты (`/etc/mail-proxy/crypto.key`, `db.conf`, локальный `web/config.php`) **не** перезаписывайте слепо из дистрибутива.

---

## Проверка целостности сборки

```bash
md5sum mail-proxy-daemon.py message_rebuild.py web/relationship-status.php
grep -n PANEL_TAB_PASSAGE web/includes/relationship_status.php | head -3
test -f migrations/005_mail_passage_journal_skipped.sql && echo migrations_ok
```

Ожидается наличие вкладок журнала (`tab=passage` / `tab=nonstandard`) и модулей `mail_passage_journal` / `mail_disposal`.