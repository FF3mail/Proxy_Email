#!/usr/bin/env bash
set -euo pipefail

# =============================================================================
# DELTA-transit — configure_limits.sh
# Настройка лимитов: Postfix, Dovecot, MariaDB, Nginx, PHP-FPM
#
# Лимиты согласованы с якорным документом v3.0:
#   Целевое вложение 150 МБ. С Base64-overhead ~33% → SMTP-размер ≈200 МБ.
#   message_size_limit = 209715200  (200 МБ)
#   mailbox_size_limit = 314572800  (300 МБ)
#   Nginx client_max_body_size = 210M
#   PHP upload_max_filesize = 200M / post_max_size = 210M
#
# Исправления относительно предыдущей версии:
#   FIX-CL-1  Dovecot: set_or_add_param заменена на dovecot_set_param().
#             Старый sed-паттерн совпадал с закомментированными строками
#             (# key = ...) и перезаписывал их как активные параметры.
#             Новая функция явно пропускает строки, начинающиеся с '#'.
#   FIX-CL-2  Postfix message_size_limit: 157286400 → 209715200 (200 МБ).
#   FIX-CL-3  Nginx client_max_body_size: 150M → 210M.
#   FIX-CL-4  PHP upload_max_filesize: 150M → 200M; post_max_size: 160M → 210M.
#   FIX-CL-5  Nginx: двойной цикл по sites-enabled исключён.
#   FIX-CL-6  Postfix и Dovecot перезапускаются только если оба прошли проверку.
# =============================================================================

[[ $EUID -ne 0 ]] && { echo "Run as root"; exit 1; }

# -----------------------------------------------------------------------------
# Лимиты (единое место для редактирования)
# -----------------------------------------------------------------------------

POSTFIX_MESSAGE_LIMIT="209715200"   # 200 МБ
POSTFIX_MAILBOX_LIMIT="314572800"   # 300 МБ
POSTFIX_SMTPD_TIMEOUT="300s"
POSTFIX_SMTP_DONE_TIMEOUT="300s"

DOVECOT_MAX_USERIP_CONN="50"
DOVECOT_IMAP_MAX_LINE="65536"

MYSQL_MAX_PACKET="256M"

NGINX_BODY_LIMIT="210M"

PHP_INI="/etc/php/8.1/fpm/php.ini"
PHP_UPLOAD="200M"
PHP_POST="210M"
PHP_MEMORY="512M"
PHP_EXEC_TIME="600"
PHP_INPUT_TIME="600"

# -----------------------------------------------------------------------------
# Цвета / логирование
# -----------------------------------------------------------------------------

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

log_info()  { echo -e "${GREEN}[INFO]${NC} $*"; }
log_warn()  { echo -e "${YELLOW}[WARN]${NC} $*"; }
log_error() { echo -e "${RED}[ERROR]${NC} $*"; }

# -----------------------------------------------------------------------------
# Состояние
# -----------------------------------------------------------------------------

declare -A CHANGE_STATUS
declare -A SERVICE_STATUS

POSTFIX_OK=true
DOVECOT_OK=true
MYSQL_OK=true
NGINX_OK=true
PHP_OK=true

declare -A BACKUP_MAP

# -----------------------------------------------------------------------------
# Резервные копии
# -----------------------------------------------------------------------------

backup_file() {
    local file="$1"
    [[ -f "$file" ]] || { log_warn "Backup skipped, not found: $file"; return 0; }

    local backup="${file}.bak_$(date +%Y%m%d_%H%M%S_%N)"
    cp -a "$file" "$backup" || { log_error "Backup failed: $backup"; return 1; }
    [[ -f "$backup" ]]     || { log_error "Backup verify failed: $backup"; return 1; }
    BACKUP_MAP["$file"]="$backup"
    log_info "Backup: $backup"
}

# -----------------------------------------------------------------------------
# PHP / Dovecot — универсальный set-or-add
#
# FIX-CL-1: ищем ТОЛЬКО раскомментированные строки (без ведущего '#').
# Паттерн grep/sed намеренно не совпадает с закомментированными строками,
# чтобы не «активировать» закомментированные параметры.
# -----------------------------------------------------------------------------

# Для PHP .ini — формат "key = value" (пробелы опциональны)
php_set_param() {
    local file="$1" key="$2" value="$3"

    if grep -Eq "^[[:space:]]*${key}[[:space:]]*=" "$file" 2>/dev/null; then
        sed -i -E "s|^([[:space:]]*)${key}([[:space:]]*)=.*|\1${key}\2= ${value}|" "$file"
    else
        printf "\n%s = %s\n" "$key" "$value" >> "$file"
    fi
}

# FIX-CL-1: для Dovecot — ищем строки БЕЗ ведущего '#'
# Паттерн: начало строки → необязательные пробелы → НЕ '#' → ключ → '='
dovecot_set_param() {
    local file="$1" key="$2" value="$3"

    # Активная (не закомментированная) строка с этим ключом
    if grep -Eq "^[[:space:]]*[^#[:space:]][^#]*${key}[[:space:]]*=" "$file" 2>/dev/null ||
       grep -Eq "^${key}[[:space:]]*=" "$file" 2>/dev/null; then
        # Заменяем только строки без ведущего '#'
        sed -i -E "/^[[:space:]]*#/!s|^([[:space:]]*)${key}([[:space:]]*)=.*|\1${key}\2= ${value}|" "$file"
    else
        printf "\n%s = %s\n" "$key" "$value" >> "$file"
    fi
}

# Для MariaDB — вставка под [mysqld]
update_mysqld_param() {
    local file="$1" key="$2" value="$3"

    if grep -Eq "^[[:space:]]*${key}[[:space:]]*=" "$file" 2>/dev/null; then
        sed -i -E "s|^([[:space:]]*)${key}([[:space:]]*)=.*|\1${key}\2= ${value}|" "$file"
        return
    fi

    if grep -q "^\[mysqld\]" "$file"; then
        sed -i "/^\[mysqld\]/a ${key} = ${value}" "$file"
    else
        printf "\n[mysqld]\n%s = %s\n" "$key" "$value" >> "$file"
    fi
}

# -----------------------------------------------------------------------------
# Перезапуск сервиса
# -----------------------------------------------------------------------------

restart_service() {
    local name="$1"
    systemctl restart "$name"
    sleep 2
    if systemctl is-active --quiet "$name"; then
        SERVICE_STATUS["$name"]="OK"
        log_info "$name restarted OK"
    else
        SERVICE_STATUS["$name"]="FAILED"
        log_error "$name failed — проверьте: journalctl -u $name"
    fi
}

# -----------------------------------------------------------------------------
# Валидация email
# -----------------------------------------------------------------------------

validate_email() {
    local email="$1"
    [[ "$email" =~ ^[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}$ ]]
}

# =============================================================================
# PRE-CHECKS
# =============================================================================

echo
log_info "=== PRE-CHECKS ==="

check_bin() {
    command -v "$1" >/dev/null 2>&1 \
        && log_info "Found binary: $1" \
        || log_warn "Binary not found: $1"
}

check_readable() {
    if [[ -e "$1" ]]; then
        [[ -r "$1" ]] && log_info "Readable: $1" || log_warn "Not readable: $1"
    else
        log_warn "Missing: $1"
    fi
}

check_bin postconf
check_bin dovecot
check_bin mysql
check_bin nginx

if command -v php8.1-fpm >/dev/null 2>&1; then
    log_info "Found binary: php8.1-fpm"
elif command -v php-fpm8.1 >/dev/null 2>&1; then
    log_info "Found binary: php-fpm8.1"
else
    log_warn "PHP-FPM binary not found"
fi

check_readable /etc/postfix/main.cf
check_readable /etc/dovecot/conf.d/10-mail.conf
check_readable /etc/mysql/mariadb.conf.d/50-server.cnf
check_readable "$PHP_INI"
check_readable /etc/nginx/nginx.conf

# =============================================================================
# BLOCK 1: POSTFIX
# Используем postconf -e — единственный безопасный способ правки main.cf.
# postconf идемпотентен: повторный запуск не создаёт дублей.
# =============================================================================

echo
log_info "=== BLOCK 1: POSTFIX ==="

if [[ -f /etc/postfix/main.cf ]]; then
    backup_file /etc/postfix/main.cf

    # FIX-CL-2: лимит согласован с якорным документом (200 МБ)
    postconf -e "message_size_limit = ${POSTFIX_MESSAGE_LIMIT}"
    postconf -e "mailbox_size_limit = ${POSTFIX_MAILBOX_LIMIT}"
    postconf -e "virtual_mailbox_limit = ${POSTFIX_MAILBOX_LIMIT}"
    postconf -e "smtpd_timeout = ${POSTFIX_SMTPD_TIMEOUT}"
    postconf -e "smtpd_proxy_timeout = ${POSTFIX_SMTPD_TIMEOUT}"
    postconf -e "smtp_data_done_timeout = ${POSTFIX_SMTP_DONE_TIMEOUT}"

    if postfix check; then
        log_info "postfix check OK"
        CHANGE_STATUS["Postfix"]="UPDATED"
    else
        POSTFIX_OK=false
        CHANGE_STATUS["Postfix"]="ERROR"
        log_error "postfix check failed — Postfix не будет перезапущен"
    fi
else
    POSTFIX_OK=false
    CHANGE_STATUS["Postfix"]="CONFIG NOT FOUND"
fi

# =============================================================================
# BLOCK 2: DOVECOT
# FIX-CL-1: dovecot_set_param() не трогает закомментированные строки.
# =============================================================================

echo
log_info "=== BLOCK 2: DOVECOT ==="

DOVECOT_MAIL_CONF=""
DOVECOT_IMAP_CONF=""

if [[ -f /etc/dovecot/conf.d/10-mail.conf ]]; then
    DOVECOT_MAIL_CONF="/etc/dovecot/conf.d/10-mail.conf"
fi

if [[ -f /etc/dovecot/conf.d/20-imap.conf ]]; then
    DOVECOT_IMAP_CONF="/etc/dovecot/conf.d/20-imap.conf"
fi

# Fallback — монолитный конфиг
if [[ -z "$DOVECOT_MAIL_CONF" && -z "$DOVECOT_IMAP_CONF" ]]; then
    if [[ -f /etc/dovecot/dovecot.conf ]]; then
        DOVECOT_MAIL_CONF="/etc/dovecot/dovecot.conf"
        DOVECOT_IMAP_CONF="/etc/dovecot/dovecot.conf"
    fi
fi

if [[ -n "$DOVECOT_MAIL_CONF" ]]; then
    backup_file "$DOVECOT_MAIL_CONF"
    dovecot_set_param "$DOVECOT_MAIL_CONF" "mail_max_userip_connections" "$DOVECOT_MAX_USERIP_CONN"

    if [[ -n "$DOVECOT_IMAP_CONF" && "$DOVECOT_IMAP_CONF" != "$DOVECOT_MAIL_CONF" ]]; then
        backup_file "$DOVECOT_IMAP_CONF"
    fi
    dovecot_set_param "${DOVECOT_IMAP_CONF:-$DOVECOT_MAIL_CONF}" "imap_max_line_length" "$DOVECOT_IMAP_MAX_LINE"

    if [[ -f /etc/dovecot/conf.d/15-lda.conf ]]; then
        backup_file /etc/dovecot/conf.d/15-lda.conf
        dovecot_set_param /etc/dovecot/conf.d/15-lda.conf "quota_full_tempfail" "yes"
    fi

    if dovecot -n >/dev/null 2>&1; then
        log_info "Dovecot syntax OK"
        DOVECOT_OK=true
        CHANGE_STATUS["Dovecot"]="UPDATED"
    else
        log_error "Dovecot syntax ERROR"
        DOVECOT_OK=false
        CHANGE_STATUS["Dovecot"]="ERROR"
    fi
else
    DOVECOT_OK=false
    CHANGE_STATUS["Dovecot"]="CONFIG NOT FOUND"
fi

# =============================================================================
# BLOCK 3: MARIADB
# =============================================================================

echo
log_info "=== BLOCK 3: MARIADB ==="

MYSQL_CONF="/etc/mysql/mariadb.conf.d/50-server.cnf"

if [[ -f "$MYSQL_CONF" ]]; then
    backup_file "$MYSQL_CONF"
    update_mysqld_param "$MYSQL_CONF" "max_allowed_packet" "$MYSQL_MAX_PACKET"
    MYSQL_OK=true
    CHANGE_STATUS["MariaDB"]="UPDATED"
else
    MYSQL_OK=false
    CHANGE_STATUS["MariaDB"]="CONFIG NOT FOUND"
fi

# --- Квоты почтовых ящиков ---

echo
read -rs -p "MySQL root password: " MYSQL_ROOT_PASSWORD
echo

read -rp "Email-адреса через запятую или --all-referents: " MAILBOX_INPUT

if [[ "$MAILBOX_INPUT" == "--all-referents" ]]; then
    read -rp "Домен: " DOMAIN_NAME

    SQL_QUERY="
UPDATE vmail.mailbox
SET quota = 10240
WHERE username LIKE '%@${DOMAIN_NAME}';
"
else
    IFS=',' read -ra ADDRESSES <<< "$MAILBOX_INPUT"
    EMAIL_LIST=""

    for addr in "${ADDRESSES[@]}"; do
        addr="$(echo "$addr" | xargs)"
        [[ -z "$addr" ]] && continue

        if ! validate_email "$addr"; then
            log_error "Невалидный email: $addr"
            exit 1
        fi

        escaped="${addr//\'/\'\'}"
        [[ -n "$EMAIL_LIST" ]] && EMAIL_LIST+=","
        EMAIL_LIST+="'${escaped}'"
    done

    if [[ -z "$EMAIL_LIST" ]]; then
        log_error "Не указано ни одного валидного email"
        exit 1
    fi

    SQL_QUERY="
UPDATE vmail.mailbox
SET quota = 10240
WHERE username IN (${EMAIL_LIST});
"
fi

set +e

MYSQL_RESULT=$(
mysql -u root -p"${MYSQL_ROOT_PASSWORD}" -N -B -e "
USE vmail;
${SQL_QUERY}
SELECT ROW_COUNT();
" 2>/tmp/mysql_error.log
)
MYSQL_EXIT_CODE=$?

set -e

if [[ $MYSQL_EXIT_CODE -ne 0 ]]; then
    log_error "MariaDB query failed"
    [[ -s /tmp/mysql_error.log ]] \
        && while IFS= read -r line; do log_error "$line"; done < /tmp/mysql_error.log \
        || log_error "No details available"
    MYSQL_OK=false
    AFFECTED_ROWS=0
else
    AFFECTED_ROWS=$(echo "$MYSQL_RESULT" | tail -n1)
fi

log_info "Updated mailbox rows: ${AFFECTED_ROWS:-0}"

# =============================================================================
# BLOCK 4: NGINX
# FIX-CL-3: лимит 210M
# FIX-CL-5: собираем уникальный список файлов без дублей.
#   nginx.conf обрабатывается один раз; sites-enabled — отдельно.
#   Директива client_max_body_size заменяется идемпотентно через sed
#   с якорем ^ — не создаёт дублей при повторном запуске.
# =============================================================================

echo
log_info "=== BLOCK 4: NGINX ==="

declare -A NGINX_FILE_SET

if [[ -f /etc/nginx/nginx.conf ]]; then
    NGINX_FILE_SET["/etc/nginx/nginx.conf"]=1
fi

while IFS= read -r file; do
    NGINX_FILE_SET["$file"]=1
done < <(find /etc/nginx/sites-enabled -maxdepth 1 -type f 2>/dev/null)

for file in "${!NGINX_FILE_SET[@]}"; do
    backup_file "$file"

    if grep -Eq '^[[:space:]]*client_max_body_size[[:space:]]' "$file"; then
        # Идемпотентная замена существующей директивы
        sed -i -E \
            "s|^([[:space:]]*)client_max_body_size[[:space:]]+[^;]+;|\1client_max_body_size ${NGINX_BODY_LIMIT};|g" \
            "$file"
        log_info "Nginx: обновлён client_max_body_size в $file"
        NGINX_OK=true
    else
        # Добавляем только в секцию http {}
        if grep -q 'http[[:space:]]*{' "$file"; then
            sed -i '/http[[:space:]]*{/a\    client_max_body_size '"${NGINX_BODY_LIMIT}"';' "$file"
            log_info "Nginx: добавлен client_max_body_size в $file"
            NGINX_OK=true
        else
            log_warn "Nginx: в $file нет секции http {}, директива не добавлена"
        fi
    fi
done

if nginx -t >/tmp/nginx_test.log 2>&1; then
    CHANGE_STATUS["Nginx"]="UPDATED"
else
    NGINX_OK=false
    CHANGE_STATUS["Nginx"]="ERROR"
    log_error "nginx -t failed"
    # Откат только Nginx-файлов
    for original in "${!BACKUP_MAP[@]}"; do
        if [[ "$original" == *nginx* ]]; then
            cp -a "${BACKUP_MAP[$original]}" "$original"
            log_warn "Restored: $original"
        fi
    done
fi

# =============================================================================
# BLOCK 5: PHP-FPM
# FIX-CL-4: лимиты 200M/210M
# =============================================================================

echo
log_info "=== BLOCK 5: PHP-FPM ==="

if [[ -f "$PHP_INI" ]]; then
    backup_file "$PHP_INI"

    php_set_param "$PHP_INI" "upload_max_filesize" "$PHP_UPLOAD"
    php_set_param "$PHP_INI" "post_max_size"       "$PHP_POST"
    php_set_param "$PHP_INI" "memory_limit"        "$PHP_MEMORY"
    php_set_param "$PHP_INI" "max_execution_time"  "$PHP_EXEC_TIME"
    php_set_param "$PHP_INI" "max_input_time"      "$PHP_INPUT_TIME"

    PHP_OK=true
    CHANGE_STATUS["PHP-FPM"]="UPDATED"
else
    PHP_OK=false
    CHANGE_STATUS["PHP-FPM"]="CONFIG NOT FOUND"
fi

# =============================================================================
# SERVICE RESTARTS
# =============================================================================

echo
log_info "=== SERVICE RESTARTS ==="

restart_if_ok() {
    local svc="$1" ok_var="$2"
    if ${!ok_var}; then
        restart_service "$svc"
    else
        SERVICE_STATUS["$svc"]="SKIPPED"
        log_warn "$svc пропущен (ошибка конфигурации)"
    fi
}

restart_if_ok mariadb MYSQL_OK

# Postfix и Dovecot перезапускаем только если оба прошли проверку конфигурации
if $POSTFIX_OK && $DOVECOT_OK; then
    restart_service postfix
    restart_service dovecot
else
    SERVICE_STATUS["postfix"]="SKIPPED"
    SERVICE_STATUS["dovecot"]="SKIPPED"
    log_warn "Postfix/Dovecot пропущены: ошибка конфигурации одного из сервисов"
fi

restart_if_ok php8.1-fpm PHP_OK
restart_if_ok nginx      NGINX_OK

# =============================================================================
# ИТОГОВАЯ ТАБЛИЦА
# =============================================================================

echo
echo "======================================================================"
printf "%-15s | %-20s | %-15s\n" "Компонент" "Статус изменений" "Статус службы"
echo "----------------------------------------------------------------------"

printf "%-15s | %-20s | %-15s\n" "Postfix"  "${CHANGE_STATUS[Postfix]:-N/A}"  "${SERVICE_STATUS[postfix]:-N/A}"
printf "%-15s | %-20s | %-15s\n" "Dovecot"  "${CHANGE_STATUS[Dovecot]:-N/A}"  "${SERVICE_STATUS[dovecot]:-N/A}"
printf "%-15s | %-20s | %-15s\n" "MariaDB"  "${CHANGE_STATUS[MariaDB]:-N/A}"  "${SERVICE_STATUS[mariadb]:-N/A}"
printf "%-15s | %-20s | %-15s\n" "Nginx"    "${CHANGE_STATUS[Nginx]:-N/A}"    "${SERVICE_STATUS[nginx]:-N/A}"
printf "%-15s | %-20s | %-15s\n" "PHP-FPM"  "${CHANGE_STATUS[PHP-FPM]:-N/A}" "${SERVICE_STATUS[php8.1-fpm]:-N/A}"
echo "======================================================================"
