#!/usr/bin/env bash
set -euo pipefail

# Установка окружения и зависимостей для mail-proxy-daemon

[[ $EUID -ne 0 ]] && { echo "Run as root"; exit 1; }

echo "=== DELTA-transit: установка зависимостей демона ==="

# 1. Python-зависимости (системный pip3)
pip3 install --break-system-packages \
    cryptography \
    watchdog \
    mysql-connector-python \
    requests

# 2. Создание директории конфигурации и логов
install -d -m 0750 -o root -g root /etc/mail-proxy

# Создаём отдельную группу для чтения логов веб-панелью.
# www-data не должен иметь доступ к Maildir и группе vmail.
groupadd -f mail-proxy-logs
usermod -aG mail-proxy-logs www-data
install -d -m 0750 -o vmail -g mail-proxy-logs /var/log/mail-proxy
echo "  [OK] www-data добавлен в группу mail-proxy-logs (доступ только к логам)"

# Создание директории для временных файлов демона.
# Права 0700 — только vmail имеет доступ, файлы писем не видны другим пользователям.
install -d -m 0700 -o vmail -g vmail /var/spool/mail-proxy/tmp
echo "  [OK] Создана директория /var/spool/mail-proxy/tmp"

# 3. Создание служебной группы для совместного доступа к crypto.key
groupadd -f mail-proxy-crypto
usermod -aG mail-proxy-crypto www-data
usermod -aG mail-proxy-crypto vmail

# Переназначаем группу на конфиг и ключ
chown root:mail-proxy-crypto /etc/mail-proxy
chmod 0750 /etc/mail-proxy

# Генерация crypto.key если не существует
if [[ ! -f /etc/mail-proxy/crypto.key ]]; then
    python3 -c "import secrets; print(secrets.token_hex(32))" \
        > /etc/mail-proxy/crypto.key
    chmod 0640 /etc/mail-proxy/crypto.key
    chown root:mail-proxy-crypto /etc/mail-proxy/crypto.key
    echo "  [OK] Сгенерирован новый /etc/mail-proxy/crypto.key"
else
    echo "  [SKIP] /etc/mail-proxy/crypto.key уже существует"
fi

# Шаблон db.conf если не существует
if [[ ! -f /etc/mail-proxy/db.conf ]]; then
    cat > /etc/mail-proxy/db.conf << 'EOF'
[db]
db_host = 127.0.0.1
db_user = mail_proxy
db_pass = CHANGE_ME
db_name = mail_proxy
EOF
    chmod 0640 /etc/mail-proxy/db.conf
    chown root:mail-proxy-crypto /etc/mail-proxy/db.conf
    echo "  [OK] Создан шаблон /etc/mail-proxy/db.conf"
    echo "  [!!] Установите db_pass в /etc/mail-proxy/db.conf"
else
    echo "  [SKIP] /etc/mail-proxy/db.conf уже существует"
fi

# 4. Копирование демона
if [[ -f ./mail-proxy-daemon.py ]]; then
    cp ./mail-proxy-daemon.py /usr/local/bin/mail-proxy-daemon.py
    chmod 0750 /usr/local/bin/mail-proxy-daemon.py
    chown root:vmail /usr/local/bin/mail-proxy-daemon.py
    echo "  [OK] mail-proxy-daemon.py установлен в /usr/local/bin/"
else
    echo "  [ERROR] mail-proxy-daemon.py не найден в текущей директории!"
    exit 1
fi

# 5. Копирование systemd unit
if [[ -f ./mail-proxy.service ]]; then
    tmp_unit="$(mktemp)"
    cp ./mail-proxy.service "$tmp_unit"
    sed -i '/^+[^+]/d' "$tmp_unit"
    cp "$tmp_unit" /etc/systemd/system/mail-proxy.service
    rm -f "$tmp_unit"
    chmod 0644 /etc/systemd/system/mail-proxy.service
    systemctl daemon-reload
    systemctl enable mail-proxy.service
    echo "  [OK] mail-proxy.service установлен и включен в автозапуск"
else
    echo "  [ERROR] mail-proxy.service не найден в текущей директории!"
    exit 1
fi

# 6. Увеличение inotify лимита
if ! grep -q 'fs.inotify.max_user_watches' /etc/sysctl.conf; then
    echo 'fs.inotify.max_user_watches = 65536' >> /etc/sysctl.conf
    sysctl -p
    echo "  [OK] inotify watches увеличен до 65536"
fi

# 7. Установка конфигурации logrotate
if [[ -f ./logrotate-mail-proxy ]]; then
    cp ./logrotate-mail-proxy /etc/logrotate.d/mail-proxy
    chmod 0644 /etc/logrotate.d/mail-proxy
    chown root:root /etc/logrotate.d/mail-proxy
    echo "  [OK] Конфигурация logrotate установлена: /etc/logrotate.d/mail-proxy"

    # Проверяем синтаксис конфигурации logrotate
    if logrotate --debug /etc/logrotate.d/mail-proxy >/dev/null 2>&1; then
        echo "  [OK] Синтаксис logrotate проверен успешно"
    else
        echo "  [WARN] Проверка синтаксиса logrotate завершилась с предупреждениями"
        logrotate --debug /etc/logrotate.d/mail-proxy 2>&1 | head -20
    fi
else
    echo "  [WARN] Файл logrotate-mail-proxy не найден — ротация логов не настроена"
fi

echo ""
echo "=== Установка завершена ==="
echo "Следующие шаги:"
echo "  1. Отредактируйте /etc/mail-proxy/db.conf — установите пароль БД"
echo "  2. Примените schema.sql из Шага 1"
echo "  3. Запустите: systemctl start mail-proxy.service"
echo "  4. Проверьте: journalctl -u mail-proxy.service -f"