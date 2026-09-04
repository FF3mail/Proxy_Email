#!/usr/bin/env bash

set -Eeuo pipefail

# =============================================================================
# DELTA-transit Installer v3.1.0
# Production Candidate
#
# Changelog v3.1.0 (рефакторинг):
#   FIX-1  Удалена мёртвая функция phase_bootstrap() — заменена phase_preflight()
#   FIX-2  Удалено двойное объявление TMP_FILES/cleanup/fatal/on_error/register_tmpfile
#          (дубли были в "Phase 13", которая была кодом верхнего уровня)
#   FIX-3  Исправлен порядок аргументов python3 в update_php_web_config():
#          было   python3 "$tmp" "$PARAM_APP_URL" <<'PY'  (heredoc — это stdin,
#                 а не скрипт; python3 пытался исполнить $tmp как скрипт)
#          стало  python3 - "$tmp" "$PARAM_APP_URL" <<'PY'  (- означает stdin)
#   FIX-4  render_report(): убраны несуществующие фазы Bootstrap/Crypto/Summary,
#          добавлена фаза Audit (которая вычислялась, но не выводилась)
#   FIX-5  initialize_phase_status(): приведена в соответствие с render_report()
#   FIX-6  Лимиты согласованы с якорным документом v2.0 (целевое вложение 150 МБ,
#          с учётом Base64-overhead ~33% фактический SMTP-размер ≈200 МБ):
#          Postfix  message_size_limit: 209715200  (~200 МБ)
#          Postfix  mailbox_size_limit: 314572800  (~300 МБ)
#          Nginx    client_max_body_size: 210M
#          PHP      upload_max_filesize: 200M / post_max_size: 210M
#   FIX-7  Убран ранний  trap cleanup EXIT  до объявления cleanup():
#          трапы устанавливаются только через install_traps() в main()
#   FIX-8  install_systemd_unit(): убраны git-diff артефакты (+) из
#          mail-proxy.service через sed перед копированием
# =============================================================================

SCRIPT_VERSION="3.1.0"

# -----------------------------------------------------------------------------
# Пути
# -----------------------------------------------------------------------------

INSTALL_ROOT="/opt/delta-transit"
VENV_PATH="${INSTALL_ROOT}/venv"
TEMP_DIR="/var/spool/mail-proxy/tmp"
CONFIG_DIR="/etc/mail-proxy"
DB_CONF="${CONFIG_DIR}/db.conf"
CRYPTO_KEY="${CONFIG_DIR}/crypto.key"
SECRETS_FILE="${CONFIG_DIR}/install-secrets.txt"
SYSTEMD_UNIT="/etc/systemd/system/mail-proxy.service"
LOG_DIR="/var/log/mail-proxy"
DAEMON_LOG="${LOG_DIR}/mail-proxy-daemon.log"
WEB_ADMIN_LOG="${LOG_DIR}/web_admin.log"
WEB_ROOT="/var/www/mail-proxy"
NGINX_INCLUDE_CONF="/etc/nginx/conf.d/mail-proxy.conf"
# -----------------------------------------------------------------------------
# Nginx Virtual Host
# -----------------------------------------------------------------------------
NGINX_SITE_AVAILABLE="/etc/nginx/sites-available/mail-proxy.conf"
NGINX_SITE_ENABLED="/etc/nginx/sites-enabled/mail-proxy.conf"

NGINX_SSL_CERT="/etc/ssl/certs/mail-proxy.crt"
NGINX_SSL_KEY="/etc/ssl/private/mail-proxy.key"

NGINX_VHOST_CREATED="no"
NGINX_VHOST_ENABLED="no"
NGINX_CONFLICTS="unknown"
NGINX_SERVER_NAME=""
PHP_FPM_SOCKET_DETECTED=""
SSL_MODE="none"
# -----------------------------------------------------------------------------
SYSCTL_CONF="/etc/sysctl.d/99-mail-proxy.conf"

# -----------------------------------------------------------------------------
# Пакеты
# -----------------------------------------------------------------------------

APT_PACKAGES=(
    python3
    python3-pip
    python3-venv
    mariadb-server
    nginx
    postfix
    dovecot-imapd
    logrotate
    openssl
    curl
    php-cli
    php-fpm
)

# -----------------------------------------------------------------------------
# Группы
# -----------------------------------------------------------------------------

GROUP_LOGS="mail-proxy-logs"
GROUP_CRYPTO="mail-proxy-crypto"

# -----------------------------------------------------------------------------
# Обязательные файлы дистрибутива
# -----------------------------------------------------------------------------

REQUIRED_FILES=(
    "./mail-proxy-daemon.py"
    "./mail-proxy.service"
    "./logrotate-mail-proxy"
    "./schema.sql"
    "./requirements.txt"
)

# -----------------------------------------------------------------------------
# Глобальные переменные
# -----------------------------------------------------------------------------

declare -a TMP_FILES=()
declare -A PHASE_STATUS

MYSQL_ROOT_PASSWORD=""
PARAM_DB_PASS=""
PARAM_APP_URL=""
PARAM_PIP_MIRROR=""
PANEL_MASTER_USERNAME=""
PANEL_MASTER_PASSWORD=""

# -----------------------------------------------------------------------------
# Цвета
# -----------------------------------------------------------------------------

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

# -----------------------------------------------------------------------------
# Логирование
# -----------------------------------------------------------------------------

log_info() { echo -e "${GREEN}[INFO]${NC} $*"; }
log_warn()  { echo -e "${YELLOW}[WARN]${NC} $*"; }
log_error() { echo -e "${RED}[ERROR]${NC} $*"; }
log_ok()    { echo -e "${GREEN}[OK]${NC} $*"; }

# -----------------------------------------------------------------------------
# Временные файлы
# -----------------------------------------------------------------------------

register_tmpfile() {
    TMP_FILES+=("$1")
}

cleanup() {
    local f
    for f in "${TMP_FILES[@]}"
    do
        [[ -n "$f" ]] || continue
        [[ -f "$f" ]] || continue
        rm -f "$f" || true
    done
}

# -----------------------------------------------------------------------------
# Отчёт о фазах
# -----------------------------------------------------------------------------

render_report() {

    echo
    echo "============================================================"
    printf "%-25s %-15s\n" "PHASE" "STATUS"
    echo "------------------------------------------------------------"

    local phase
    for phase in \
        Preflight   \
        Packages    \
        Database    \
        UsersGroups \
        Python      \
        Web         \
        Postfix     \
        Dovecot     \
        PHP         \
        Nginx       \
        Systemd     \
        Validation  \
        Audit
    do
        printf "%-25s %-15s\n" \
            "$phase" \
            "${PHASE_STATUS[$phase]:-NOT_STARTED}"
    done

    echo "============================================================"
}

# -----------------------------------------------------------------------------
# Обработка ошибок
# -----------------------------------------------------------------------------

fatal() {
    local msg="$1"
    log_error "$msg"
    render_report
    exit 1
}

on_error() {
    local rc="$?"
    local line="$1"
    log_error "Unhandled error at line ${line} (exit code ${rc})"
    render_report
    exit "$rc"
}

install_traps() {
    trap cleanup EXIT
    trap 'on_error $LINENO' ERR
}

# -----------------------------------------------------------------------------
# Вспомогательные функции
# -----------------------------------------------------------------------------

require_root() {
    [[ "${EUID}" -eq 0 ]] || fatal "Run installer as root"
}

require_file() {
    local file="$1"
    [[ -f "$file" ]] || fatal "Required file not found: $file"
}

require_command() {
    local cmd="$1"
    command -v "$cmd" >/dev/null 2>&1 || fatal "Required command not found: $cmd"
}

ensure_group() {
    local group="$1"
    getent group "$group" >/dev/null 2>&1 || groupadd "$group"
}

ensure_user_in_group() {
    local user="$1"
    local group="$2"
    id "$user" >/dev/null 2>&1 || fatal "User not found: $user"
    if ! id -nG "$user" | grep -qw "$group"
    then
        usermod -aG "$group" "$user"
    fi
}

generate_password() {
    openssl rand -base64 32
}

# -----------------------------------------------------------------------------
# Интерактивный ввод
# -----------------------------------------------------------------------------

ask_public_url() {
    local postfix_hostname=""
    postfix_hostname="$(postconf -h myhostname 2>/dev/null || true)"
    if [[ -n "$postfix_hostname" ]]; then
        log_info "Postfix myhostname is '${postfix_hostname}' — panel URL hostname must differ"
    fi

    while true
    do
        read -rp "Public URL (https://panel.example.com): " PARAM_APP_URL
        [[ -n "$PARAM_APP_URL" ]]                          || continue
        [[ "$PARAM_APP_URL" != "https://mail-proxy.local" ]] || continue
        [[ "$PARAM_APP_URL" =~ ^https://  ]]               || continue
        if [[ -n "$postfix_hostname" ]]; then
            local panel_hostname=""
            panel_hostname="$(
                printf '%s\n' "$PARAM_APP_URL" \
                    | sed -E 's#^https://##' \
                    | sed -E 's#/.*$##'
            )"
            if [[ "$panel_hostname" == "$postfix_hostname" ]]; then
                log_warn "Panel hostname equals Postfix myhostname (${postfix_hostname}); choose another URL"
                continue
            fi
        fi
        break
    done
}

ask_pip_mirror() {
    echo -e "\nИногда стандартный сервер пакетов Python недоступен или работает медленно."
    echo "Вы можете использовать альтернативный сервер (зеркало)."
    echo "Например, рабочее зеркало от Яндекса: https://mirror.yandex.ru/pypi/simple/"
    echo "Вводите полный URL, включая http:// или https://, и желательно с /simple/ на конце."
    echo "Если не знаете, что делать, просто нажмите Enter."
    read -rp "Адрес зеркала (или Enter для стандарта): " PARAM_PIP_MIRROR

    if [[ -n "$PARAM_PIP_MIRROR" ]]; then
        # Если не начинается с http:// или https://, добавляем https://
        if [[ ! "$PARAM_PIP_MIRROR" =~ ^https?:// ]]; then
            PARAM_PIP_MIRROR="https://${PARAM_PIP_MIRROR}"
            log_info "Добавлена схема https://, зеркало: $PARAM_PIP_MIRROR"
        fi
        # Если не заканчивается на /simple/ (или /simple), добавляем /simple/
        if [[ ! "$PARAM_PIP_MIRROR" =~ /simple/*$ ]]; then
            PARAM_PIP_MIRROR="${PARAM_PIP_MIRROR%/}/simple/"
            log_info "Добавлен суффикс /simple/, зеркало: $PARAM_PIP_MIRROR"
        fi
    fi
}

# -----------------------------------------------------------------------------
# MariaDB auth
# -----------------------------------------------------------------------------

mysql_root_exec() {
    local sql="$1"
    if mysql -u root -e "SELECT 1" >/dev/null 2>&1
    then
        mysql -u root -e "$sql"
        return 0
    fi
    mysql -u root -p"${MYSQL_ROOT_PASSWORD}" -e "$sql"
}

ask_mysql_root_password() {
    if mysql -u root -e "SELECT 1" >/dev/null 2>&1
    then
        return 0
    fi
    read -rsp "MariaDB root password: " MYSQL_ROOT_PASSWORD
    echo
    mysql -u root -p"${MYSQL_ROOT_PASSWORD}" -e "SELECT 1" >/dev/null
}

# Panel master account (PROMPT 24). Username + password are interactive only;
# plaintext is never written to install-secrets.txt or any other file.
ask_panel_master_credentials() {
    local pass1 pass2
    while true
    do
        read -rp "Panel master username: " PANEL_MASTER_USERNAME
        PANEL_MASTER_USERNAME="${PANEL_MASTER_USERNAME#"${PANEL_MASTER_USERNAME%%[![:space:]]*}"}"
        PANEL_MASTER_USERNAME="${PANEL_MASTER_USERNAME%"${PANEL_MASTER_USERNAME##*[![:space:]]}"}"
        if [[ -z "${PANEL_MASTER_USERNAME}" ]]
        then
            echo "Username must not be empty." >&2
            continue
        fi
        if (( ${#PANEL_MASTER_USERNAME} > 100 ))
        then
            echo "Username must be at most 100 characters." >&2
            continue
        fi
        break
    done

    while true
    do
        read -rsp "Panel master password: " pass1
        echo
        read -rsp "Confirm panel master password: " pass2
        echo
        if [[ -z "${pass1}" ]]
        then
            echo "Password must not be empty." >&2
            continue
        fi
        if (( ${#pass1} < 8 ))
        then
            echo "Password must be at least 8 characters." >&2
            continue
        fi
        if [[ "${pass1}" != "${pass2}" ]]
        then
            echo "Passwords do not match." >&2
            continue
        fi
        PANEL_MASTER_PASSWORD="${pass1}"
        pass1=""
        pass2=""
        break
    done
}

ensure_panel_admins_table() {
    log_info "Ensuring panel_admins table exists"
    mysql \
        -u "${DB_USER}" \
        -p"${PARAM_DB_PASS}" \
        "${DB_NAME}" \
        <<'SQL'
CREATE TABLE IF NOT EXISTS panel_admins (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('master','admin') NOT NULL DEFAULT 'admin',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_panel_admins_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
}

panel_master_exists() {
    local count
    count="$(
        mysql \
            -u "${DB_USER}" \
            -p"${PARAM_DB_PASS}" \
            "${DB_NAME}" \
            -N -e "SELECT COUNT(*) FROM panel_admins WHERE role = 'master';"
    )"
    [[ "${count}" -gt 0 ]]
}

panel_active_master_exists() {
    local count
    count="$(
        mysql \
            -u "${DB_USER}" \
            -p"${PARAM_DB_PASS}" \
            "${DB_NAME}" \
            -N -e "SELECT COUNT(*) FROM panel_admins WHERE role = 'master' AND active = 1;"
    )"
    [[ "${count}" -gt 0 ]]
}

installer_has_tty() {
    [[ -t 0 ]]
}

abort_panel_master_no_tty() {
    cat >&2 <<'EOF'
[ERROR] No active panel master and no interactive TTY available.

Non-interactive install/upgrade cannot auto-generate operator credentials.
Aborting in preflight before schema or credential changes.

Remediation (choose one):
  1. Run from a console session (or wrap with a pseudo-TTY):
       sudo ./delta-transit-install.sh
       # or:  script -q -c './delta-transit-install.sh' /tmp/install.log
     Complete the prompts for master username/password.

  2. If the database already exists, seed master manually (hash only):
       HASH=$(php -r 'echo password_hash("YOUR_PASSWORD", PASSWORD_DEFAULT);')
       mysql -u mail_proxy -p mail_proxy -e \
         "INSERT INTO panel_admins (username, password_hash, role, active) VALUES ('YOUR_USER', '$HASH', 'master', 1);"

Then re-run the installer.
EOF
    exit 1
}

# Best-effort: true if db.conf exists and an active master row is present.
try_detect_active_panel_master() {
    local db_pass="${PARAM_DB_PASS:-}"
    if [[ -z "${db_pass}" && -f "${DB_CONF}" ]]
    then
        db_pass="$(read_db_pass_from_conf 2>/dev/null || true)"
    fi
    [[ -n "${db_pass}" ]] || return 1

    local count
    count="$(
        mysql \
            -u "${DB_USER}" \
            -p"${db_pass}" \
            "${DB_NAME}" \
            -N -e "SELECT COUNT(*) FROM panel_admins WHERE role = 'master' AND active = 1;" \
            2>/dev/null || true
    )"
    [[ "${count}" =~ ^[0-9]+$ ]] || return 1
    [[ "${count}" -gt 0 ]]
}

# PROMPT-26: fail before Database phase when master cannot be seeded.
preflight_panel_master_readiness() {
    log_info "Checking panel master bootstrap path (before schema/credential changes)"

    if try_detect_active_panel_master
    then
        log_ok "Active panel master already present — interactive master seed not required"
        return 0
    fi

    if ! installer_has_tty
    then
        abort_panel_master_no_tty
    fi

    if [[ -z "${PANEL_MASTER_USERNAME:-}" || -z "${PANEL_MASTER_PASSWORD:-}" ]]
    then
        ask_panel_master_credentials
    fi
    log_ok "Panel master credentials collected for Database phase seed"
}

# Idempotent panel-auth migration: schema + master seed (PROMPT 25 upgrade path).
migrate_panel_auth() {
    log_info "Panel auth migration (idempotent)"
    ensure_panel_admins_table
    seed_panel_master
}

# Seeds exactly one master via PHP password_hash(); never logs or stores plaintext.
seed_panel_master() {
    if panel_active_master_exists
    then
        log_ok "Active panel master present — skipping seed"
        PANEL_MASTER_USERNAME=""
        PANEL_MASTER_PASSWORD=""
        return 0
    fi

    if panel_master_exists
    then
        fatal "Panel master exists but active=0. Remediation: UPDATE panel_admins SET active=1 WHERE role='master' LIMIT 1; then re-run installer."
    fi

    if [[ -z "${PANEL_MASTER_USERNAME:-}" || -z "${PANEL_MASTER_PASSWORD:-}" ]]
    then
        if installer_has_tty
        then
            ask_panel_master_credentials
        else
            # Should have been caught in preflight; keep as hard safety net.
            abort_panel_master_no_tty
        fi
    fi

    require_command php

    log_info "Seeding panel master account (hash only)"
    local hash user_sql hash_sql
    hash="$(
        PANEL_MASTER_PASSWORD="${PANEL_MASTER_PASSWORD}" php -r \
            'echo password_hash(getenv("PANEL_MASTER_PASSWORD"), PASSWORD_DEFAULT);'
    )"
    unset PANEL_MASTER_PASSWORD
    PANEL_MASTER_PASSWORD=""

    if [[ -z "${hash}" ]]
    then
        fatal "password_hash produced empty output"
    fi

    user_sql="$(escape_sql_string "${PANEL_MASTER_USERNAME}")"
    hash_sql="$(escape_sql_string "${hash}")"
    hash=""

    mysql \
        -u "${DB_USER}" \
        -p"${PARAM_DB_PASS}" \
        "${DB_NAME}" \
        -e "INSERT INTO panel_admins (username, password_hash, role, active)
            VALUES ('${user_sql}', '${hash_sql}', 'master', 1);"

    PANEL_MASTER_USERNAME=""
    log_ok "Panel master account seeded"
}

validate_panel_auth() {
    log_info "Validating panel authentication (upgrade safety)"
    ensure_panel_admins_table

    if panel_active_master_exists
    then
        log_ok "Panel auth: active master present"
        return 0
    fi

    if panel_master_exists
    then
        log_warn "Panel auth: master exists but inactive — panel login disabled until reactivated"
        return 0
    fi

    log_warn "Panel auth: no master account — panel login disabled; run installer interactively to seed"
}

# =============================================================================
# ФАЗА 0: Preflight
# =============================================================================

phase_preflight() {

    log_info "Phase: Preflight"

    require_root

    local f
    for f in "${REQUIRED_FILES[@]}"
    do
        require_file "$f"
    done

    ask_public_url
    ask_mysql_root_password
    ask_pip_mirror
    preflight_panel_master_readiness

    command -v systemd-analyze >/dev/null 2>&1 \
        || log_warn "systemd-analyze not available"

    PHASE_STATUS["Preflight"]="OK"
    log_ok "Preflight completed"
}

# =============================================================================
# ФАЗА 1: Packages / OS
# =============================================================================

apt_update_once() {
    if [[ ! -f /tmp/.delta-transit-apt-updated ]]
    then
        log_info "Updating package index"
        apt-get update -qq
        touch /tmp/.delta-transit-apt-updated
    fi
}

package_installed() {
    local pkg="$1"
    dpkg -s "$pkg" >/dev/null 2>&1
}

ensure_package() {
    local pkg="$1"
    if package_installed "$pkg"
    then
        log_info "Package already installed: $pkg"
        return 0
    fi
    apt_update_once
    log_info "Installing package: $pkg"
    DEBIAN_FRONTEND=noninteractive apt-get install -y "$pkg"
}

ensure_packages() {
    local pkg
    for pkg in "${APT_PACKAGES[@]}"
    do
        ensure_package "$pkg"
    done
}

verify_installed_binaries() {
    local commands=(
        python3 pip3 mysql nginx postconf doveconf systemctl openssl
    )
    local cmd
    for cmd in "${commands[@]}"
    do
        require_command "$cmd"
    done
}

enable_services_if_present() {
    local services=( mariadb nginx postfix dovecot php8.1-fpm php8.2-fpm php8.3-fpm )
    local svc
    for svc in "${services[@]}"
    do
        if systemctl list-unit-files \
            | awk '{print $1}' \
            | grep -qx "${svc}.service"
        then
            systemctl enable "$svc" >/dev/null 2>&1 || true
            systemctl start  "$svc" >/dev/null 2>&1 || true
        fi
    done
}

ensure_vmail_user_exists() {
    if id vmail >/dev/null 2>&1
    then
        return 0
    fi
    log_warn "User vmail not found"
    log_info "Creating system user vmail"
    useradd \
        --system \
        --home-dir /var/vmail \
        --shell /usr/sbin/nologin \
        vmail
    mkdir -p /var/vmail
    chown vmail:vmail /var/vmail
    chmod 0750 /var/vmail
}

create_base_directories() {
    install -d -m 0750 -o root  -g vmail         "$INSTALL_ROOT"
    install -d -m 0750 -o root  -g root          "$CONFIG_DIR"
    install -d -m 0770 -o vmail -g "$GROUP_LOGS" "$LOG_DIR"
    install -d -m 0700 -o vmail -g vmail         "$TEMP_DIR"
}

install_sysctl_profile() {
    local tmp
    tmp="$(mktemp)"
    register_tmpfile "$tmp"
    cat > "$tmp" <<'EOF'
#
# DELTA-transit
#
fs.inotify.max_user_watches = 65536
EOF
    chmod 0644 "$tmp"
    chown root:root "$tmp"
    mv "$tmp" "$SYSCTL_CONF"

    sysctl --system >/dev/null 2>&1 || true
}

verify_network_stack() {
    systemctl is-active mariadb >/dev/null 2>&1 || log_warn "MariaDB not active"
    systemctl is-active nginx   >/dev/null 2>&1 || log_warn "Nginx not active"
    systemctl is-active postfix >/dev/null 2>&1 || log_warn "Postfix not active"
    systemctl is-active dovecot >/dev/null 2>&1 || log_warn "Dovecot not active"
}

phase_packages() {

    log_info "Phase: Packages"

    ensure_packages
    verify_installed_binaries
    ensure_vmail_user_exists
    ensure_group "$GROUP_LOGS"
    ensure_group "$GROUP_CRYPTO"
    create_base_directories
    install_sysctl_profile
    enable_services_if_present
    verify_network_stack

    PHASE_STATUS["Packages"]="OK"
    log_ok "Packages phase completed"
}

# =============================================================================
# ФАЗА 2: MariaDB / Schema / Secrets
# =============================================================================

DB_NAME="mail_proxy"
DB_USER="mail_proxy"

escape_sql_string() {
    printf "%s" "$1" | sed "s/'/''/g"
}

ensure_mariadb_running() {
    systemctl start mariadb
    local i
    for ((i=0; i<20; i++))
    do
        if mysqladmin ping >/dev/null 2>&1
        then
            return 0
        fi
        sleep 1
    done
    fatal "MariaDB did not become ready"
}

generate_db_password() {
    if [[ -n "${PARAM_DB_PASS}" ]]
    then
        return 0
    fi
    PARAM_DB_PASS="$(generate_password)"
}

create_database() {
    mysql_root_exec "
        CREATE DATABASE IF NOT EXISTS ${DB_NAME}
        CHARACTER SET utf8mb4
        COLLATE utf8mb4_unicode_ci;
    "
}

create_database_user() {
    local db_pass_sql
    db_pass_sql="$(escape_sql_string "$PARAM_DB_PASS")"

    mysql_root_exec "
        CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost'
        IDENTIFIED BY '${db_pass_sql}';
    "
    mysql_root_exec "
        ALTER USER '${DB_USER}'@'localhost'
        IDENTIFIED BY '${db_pass_sql}';
    "
    mysql_root_exec "
        GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'localhost';
    "
    mysql_root_exec "FLUSH PRIVILEGES;"
}

schema_already_installed() {
    mysql \
        -u "${DB_USER}" \
        -p"${PARAM_DB_PASS}" \
        "${DB_NAME}" \
        -e "SHOW TABLES;" \
        2>/dev/null \
        | grep -q .
}

import_schema() {
    if schema_already_installed
    then
        log_warn "Database already contains tables"
        return 0
    fi
    log_info "Importing schema.sql"
    mysql \
        -u root \
        ${MYSQL_ROOT_PASSWORD:+-p${MYSQL_ROOT_PASSWORD}} \
        "${DB_NAME}" \
        < ./schema.sql
}

write_db_config() {
    log_info "Writing db.conf"
    local tmp_file
    tmp_file="$(mktemp)"
    register_tmpfile "$tmp_file"

    python3 - "$PARAM_DB_PASS" "$tmp_file" <<'PY'
import configparser
import sys

password = sys.argv[1]
outfile = sys.argv[2]

cfg = configparser.ConfigParser()
cfg["db"] = {
    "db_host": "127.0.0.1",
    "db_user": "mail_proxy",
    "db_pass": password,
    "db_name": "mail_proxy",
    "db_port": "3306",
}

with open(outfile, "w", encoding="utf-8") as f:
    cfg.write(f)

# Round-trip: value read back must match exactly (covers #, =, whitespace).
read_back = configparser.ConfigParser()
read_back.read(outfile)
if read_back.get("db", "db_pass") != password:
    sys.exit("db.conf round-trip verification failed")

# Regression probe for characters that break naive manual quoting.
probe = configparser.ConfigParser()
probe["db"] = {"db_pass": "p#ass=word with spaces"}
probe_path = outfile + ".probe"
with open(probe_path, "w", encoding="utf-8") as f:
    probe.write(f)
probe_read = configparser.ConfigParser()
probe_read.read(probe_path)
if probe_read.get("db", "db_pass") != "p#ass=word with spaces":
    sys.exit("db.conf special-character round-trip failed")
PY

    chmod 0640 "$tmp_file"
    chown root:"$GROUP_CRYPTO" "$tmp_file"
    mv "$tmp_file" "$DB_CONF"
}

read_db_pass_from_conf() {
    python3 - "$DB_CONF" <<'PY'
import configparser
import sys

cfg = configparser.ConfigParser()
cfg.read(sys.argv[1])
print(cfg.get("db", "db_pass"))
PY
}

validate_db_connectivity() {
    local db_pass
    db_pass="$(read_db_pass_from_conf)"
    mysql \
        -u "${DB_USER}" \
        -p"${db_pass}" \
        "${DB_NAME}" \
        -e "SELECT 1;" \
        >/dev/null
}

generate_crypto_key() {
    if [[ -f "$CRYPTO_KEY" ]]
    then
        chmod 0640 "$CRYPTO_KEY"
        chown root:"$GROUP_CRYPTO" "$CRYPTO_KEY"
        return 0
    fi

    local tmp_key
    tmp_key="$(mktemp)"
    register_tmpfile "$tmp_key"

    python3 - <<'PY' > "$tmp_key"
import secrets
print(secrets.token_hex(32))
PY

    chmod 0640 "$tmp_key"
    chown root:"$GROUP_CRYPTO" "$tmp_key"
    mv "$tmp_key" "$CRYPTO_KEY"
}

write_install_secrets() {
    local tmp
    tmp="$(mktemp)"
    register_tmpfile "$tmp"
    cat > "$tmp" <<EOF
DELTA-transit Installation Secrets

Database Name:
${DB_NAME}

Database User:
${DB_USER}

Crypto Key:
${CRYPTO_KEY}

Generated:
$(date -Is)

NOTE:
Database password stored in:
${DB_CONF}
EOF
    chmod 0600 "$tmp"
    chown root:root "$tmp"
    mv "$tmp" "$SECRETS_FILE"
}

validate_change_me() {
    if grep -q "CHANGE_ME" "$DB_CONF"
    then
        fatal "CHANGE_ME detected in db.conf"
    fi
}

phase_database() {

    log_info "Phase: Database"

    ensure_mariadb_running
    generate_db_password
    create_database
    create_database_user
    import_schema
    write_db_config
    migrate_panel_auth
    generate_crypto_key
    write_install_secrets
    validate_db_connectivity
    validate_change_me

    PHASE_STATUS["Database"]="OK"
    log_ok "Database phase completed"
}

# =============================================================================
# ФАЗА 3: Users / Groups / Permissions
# =============================================================================

ensure_www_data_exists() {
    id www-data >/dev/null 2>&1 || fatal "Required user not found: www-data"
}

ensure_vmail_exists() {
    id vmail >/dev/null 2>&1 || fatal "Required user not found: vmail"
}

remove_www_data_from_vmail_group() {
    if ! getent group vmail >/dev/null 2>&1
    then
        return 0
    fi
    local members
    members="$(getent group vmail | cut -d: -f4)"
    if echo ",${members}," | grep -q ",www-data,"
    then
        log_warn "Removing www-data from vmail group"
        gpasswd -d www-data vmail >/dev/null 2>&1 || true
    fi
}

configure_security_groups() {
    ensure_group "$GROUP_LOGS"
    ensure_group "$GROUP_CRYPTO"
    ensure_user_in_group www-data "$GROUP_LOGS"
    ensure_user_in_group www-data "$GROUP_CRYPTO"
    ensure_user_in_group vmail    "$GROUP_CRYPTO"
}

secure_config_directory() {
    install -d -m 0750 -o root -g "$GROUP_CRYPTO" "$CONFIG_DIR"
}

secure_db_config() {
    [[ -f "$DB_CONF" ]] || return 0
    chmod 0640 "$DB_CONF"
    chown root:"$GROUP_CRYPTO" "$DB_CONF"
}

secure_crypto_key() {
    [[ -f "$CRYPTO_KEY" ]] || return 0
    chmod 0640 "$CRYPTO_KEY"
    chown root:"$GROUP_CRYPTO" "$CRYPTO_KEY"
}

secure_log_directory() {
    install -d -m 0770 -o vmail -g "$GROUP_LOGS" "$LOG_DIR"
}

secure_temp_directory() {
    install -d -m 0700 -o vmail -g vmail "$TEMP_DIR"
}

secure_install_root() {
    install -d -m 0750 -o root -g vmail "$INSTALL_ROOT"
}

secure_web_root() {
    if [[ ! -d "$WEB_ROOT" ]]
    then
        return 0
    fi
    chown -R root:root "$WEB_ROOT"
    find "$WEB_ROOT" -type d -exec chmod 0755 {} \;
    find "$WEB_ROOT" -type f -exec chmod 0644 {} \;
}

ensure_log_file() {
    touch "$DAEMON_LOG"
    chown vmail:"$GROUP_LOGS" "$DAEMON_LOG"
    chmod 0640 "$DAEMON_LOG"
    touch "$WEB_ADMIN_LOG"
    chown vmail:"$GROUP_LOGS" "$WEB_ADMIN_LOG"
    chmod 0660 "$WEB_ADMIN_LOG"
}

harden_maildir_permissions() {
    if [[ -d /var/vmail ]]
    then
        chmod o-rwx /var/vmail
    fi

    if [[ -d /var/mail ]]
    then
        chmod o-rwx /var/mail
    fi
}

validate_www_data_has_no_maildir_access() {
    local violations=0
    local paths=( /var/vmail /var/mail )
    local path
    for path in "${paths[@]}"
    do
        [[ -d "$path" ]] || continue
        if runuser -u www-data -- test -r "$path" 2>/dev/null
        then
            log_warn "www-data can access: $path"
            violations=1
        fi
    done
    if [[ "$violations" -ne 0 ]]
    then
        fatal "www-data still has Maildir access"
    fi
}

validate_crypto_permissions() {
    [[ -f "$CRYPTO_KEY" ]] || fatal "crypto.key missing"

    local owner
    owner="$(stat -c '%U:%G' "$CRYPTO_KEY")"
    [[ "$owner" == "root:${GROUP_CRYPTO}" ]] || fatal "crypto.key ownership invalid"

    local mode
    mode="$(stat -c '%a' "$CRYPTO_KEY")"
    [[ "$mode" == "640" ]] || fatal "crypto.key permissions invalid"
}

validate_dbconf_permissions() {
    [[ -f "$DB_CONF" ]] || fatal "db.conf missing"

    local owner
    owner="$(stat -c '%U:%G' "$DB_CONF")"
    [[ "$owner" == "root:${GROUP_CRYPTO}" ]] || fatal "db.conf ownership invalid"

    local mode
    mode="$(stat -c '%a' "$DB_CONF")"
    [[ "$mode" == "640" ]] || fatal "db.conf permissions invalid"
}

phase_users_groups() {

    log_info "Phase: Users / Groups"

    ensure_www_data_exists
    ensure_vmail_exists
    remove_www_data_from_vmail_group
    configure_security_groups
    secure_install_root
    secure_config_directory
    secure_db_config
    secure_crypto_key
    secure_log_directory
    secure_temp_directory
    secure_web_root
    ensure_log_file
    harden_maildir_permissions
    validate_www_data_has_no_maildir_access
    validate_crypto_permissions
    validate_dbconf_permissions

    PHASE_STATUS["UsersGroups"]="OK"
    log_ok "Users / Groups phase completed"
}
# =============================================================================
# ФАЗА 4: Python Runtime / VENV / Daemon
# =============================================================================

DAEMON_TARGET="/usr/local/bin/mail-proxy-daemon.py"

ensure_python_modules() {
    python3 - <<'PY'
import venv, ssl, sqlite3, hashlib, json
print("OK")
PY
}

create_virtual_environment() {
    if [[ -x "${VENV_PATH}/bin/python3" ]]
    then
        log_info "Existing virtual environment detected"
        return 0
    fi
    log_info "Creating virtual environment"
    python3 -m venv "$VENV_PATH"
}

upgrade_pip() {
    local mirror_arg=""
    if [[ -n "$PARAM_PIP_MIRROR" ]]; then
        mirror_arg="-i $PARAM_PIP_MIRROR"
    fi
    "${VENV_PATH}/bin/pip" install $mirror_arg --upgrade pip setuptools wheel || {
        log_warn "Upgrade with mirror failed, trying without mirror"
        "${VENV_PATH}/bin/pip" install --upgrade pip setuptools wheel
    }
}

# Проверка установленных модулей (возвращает 0/1)
verify_required_python_packages() {
    "${VENV_PATH}/bin/python3" - <<'PY' || return 1
import importlib, sys
modules = ["cryptography", "watchdog", "mysql.connector", "requests"]
missing = []
for m in modules:
    try:
        importlib.import_module(m)
    except ImportError:
        missing.append(m)
if missing:
    print("Missing modules:", ", ".join(missing), file=sys.stderr)
    sys.exit(1)
else:
    print("OK")
PY
}

install_python_dependencies() {
    [[ -f ./requirements.txt ]] || fatal "requirements.txt not found"
    log_info "Installing Python dependencies"

    local retry=1
    while [[ $retry -eq 1 ]]; do
        local mirror_arg=""
        if [[ -n "$PARAM_PIP_MIRROR" ]]; then
            mirror_arg="-i $PARAM_PIP_MIRROR"
        fi

        if "${VENV_PATH}/bin/pip" install $mirror_arg -r ./requirements.txt; then
            retry=0  # успех
        else
            # Ошибка. Предложим варианты
            echo ""
            log_error "Не удалось установить зависимости с текущим зеркалом${PARAM_PIP_MIRROR:+ ($PARAM_PIP_MIRROR)}"
            echo "Что вы хотите сделать?"
            echo "  1) Ввести другое зеркало и повторить попытку"
            echo "  2) Установить пакеты вручную (предполагается, что вы уже установили их)"
            echo "  3) Прервать установку"
            read -rp "Выберите вариант (1/2/3): " choice
            case $choice in
                1)
                    ask_pip_mirror
                    retry=1
                    ;;
                2)
                    log_info "Пользователь выбрал ручную установку. Проверяем наличие пакетов..."
                    if verify_required_python_packages; then
                        log_ok "Пакеты установлены корректно."
                        retry=0
                    else
                        log_error "Не удалось импортировать необходимые модули. Попробуйте установить их вручную или выберите другой вариант."
                        retry=1
                    fi
                    ;;
                3)
                    fatal "Установка прервана пользователем."
                    ;;
                *)
                    log_error "Неверный выбор, повторите."
                    retry=1
                    ;;
            esac
        fi
    done

    # Дополнительная установка пакетов, необходимых для совместимости (dnspython, requests-toolbelt)
    log_info "Устанавливаем дополнительные пакеты для совместимости (dnspython, requests-toolbelt)"
    "${VENV_PATH}/bin/pip" install --upgrade dnspython requests-toolbelt || log_warn "Не удалось установить дополнительные пакеты"
}

install_daemon_binary() {
    local tmp
    tmp="$(mktemp)"
    register_tmpfile "$tmp"
    cp ./mail-proxy-daemon.py "$tmp"
    chmod 0755 "$tmp"
    chown root:root "$tmp"
    mv "$tmp" "$DAEMON_TARGET"
}

verify_daemon_syntax() {
    "${VENV_PATH}/bin/python3" -m py_compile "$DAEMON_TARGET"
}

create_runtime_directories() {
    install -d -m 0750 -o root  -g vmail "$INSTALL_ROOT"
    install -d -m 0755 -o root  -g root  "$(dirname "$DAEMON_TARGET")"
    install -d -m 0700 -o vmail -g vmail "$TEMP_DIR"
}

verify_temp_dir_security() {
    local owner mode
    owner="$(stat -c '%U:%G' "$TEMP_DIR")"
    [[ "$owner" == "vmail:vmail" ]] || fatal "TEMP_DIR ownership invalid"
    mode="$(stat -c '%a' "$TEMP_DIR")"
    [[ "$mode" == "700" ]] || fatal "TEMP_DIR permissions invalid"
}

verify_crypto_access() {
    runuser -u www-data -- test -r "$CRYPTO_KEY" || fatal "www-data cannot read crypto.key"
    runuser -u vmail    -- test -r "$CRYPTO_KEY" || fatal "vmail cannot read crypto.key"
}

verify_dbconf_access() {
    runuser -u www-data -- test -r "$DB_CONF" || fatal "www-data cannot read db.conf"
    runuser -u vmail    -- test -r "$DB_CONF" || fatal "vmail cannot read db.conf"
}

write_runtime_info() {
    local file="${CONFIG_DIR}/runtime.info"
    local tmp
    tmp="$(mktemp)"
    register_tmpfile "$tmp"
    cat > "$tmp" <<EOF
DELTA-transit Runtime

Version=${SCRIPT_VERSION}

Python=$("${VENV_PATH}/bin/python3" --version)

Installed=$(date -Is)

Venv=${VENV_PATH}

Daemon=${DAEMON_TARGET}
EOF
    chmod 0644 "$tmp"
    chown root:root "$tmp"
    mv "$tmp" "$file"
}

phase_python() {

    log_info "Phase: Python"

    ensure_python_modules
    create_runtime_directories
    create_virtual_environment
    upgrade_pip
    install_python_dependencies
    verify_required_python_packages || fatal "Не удалось проверить установленные пакеты."
    install_daemon_binary
    verify_daemon_syntax
    verify_temp_dir_security
    verify_crypto_access
    verify_dbconf_access
    write_runtime_info

    PHASE_STATUS["Python"]="OK"
    log_ok "Python phase completed"
}

# =============================================================================
# ФАЗА 5: Web Panel
# =============================================================================

WEB_FILES=(
    index.php
    config.php
    monitor.php
    includes/helpers.php
    includes/Cryptor.php
    includes/auth.php
    includes/panel_migration.php
    includes/panel_auth_ui.php
    includes/oauth2.php
    includes/providers_ui.php
)

validate_web_distribution() {
    local file
    for file in "${WEB_FILES[@]}"
    do
        if [[ ! -f "./web/${file}" ]]
        then
            fatal "Missing web component: web/${file}"
        fi
    done
}

create_web_root() {
    install -d -m 0755 -o root -g root "$WEB_ROOT"
}

deploy_web_files() {
    log_info "Deploying web panel"
    rsync -a --delete ./web/ "${WEB_ROOT}/"
}

validate_public_url() {
    [[ -n "$PARAM_APP_URL" ]]                          || fatal "APP_BASE_URL not configured"
    [[ "$PARAM_APP_URL" != "https://mail-proxy.local" ]] || fatal "Placeholder URL forbidden"
    [[ "$PARAM_APP_URL" =~ ^https://  ]]               || fatal "APP_BASE_URL must use HTTPS"
}

update_php_web_config() {
    local config_file="${WEB_ROOT}/config.php"
    [[ -f "$config_file" ]] || return 0

    local tmp
    tmp="$(mktemp)"
    register_tmpfile "$tmp"
    cp "$config_file" "$tmp"

    python3 - "$tmp" "$PARAM_APP_URL" <<'PY'
import re
import sys

path = sys.argv[1]
url  = sys.argv[2]

url = url.replace("\\", "\\\\")

with open(path, "r", encoding="utf-8") as f:
    content = f.read()

pattern = (
    r"(define\(\s*['\"]APP_BASE_URL['\"]\s*,\s*['\"])"
    r"([^'\"]*)"
    r"(['\"]\s*\);)"
)

def repl(match):
    return match.group(1) + url + match.group(3)

new_content = re.sub(pattern, repl, content)

with open(path, "w", encoding="utf-8") as f:
    f.write(new_content)
PY

    chmod 0644 "$tmp"
    chown root:root "$tmp"
    mv "$tmp" "$config_file"
}

verify_app_base_url_written() {
    local config_file="${WEB_ROOT}/config.php"
    [[ -f "$config_file" ]] || return 0
    grep -F "$PARAM_APP_URL" "$config_file" >/dev/null \
        || fatal "APP_BASE_URL not written"
}

scan_for_placeholder_urls() {
    local config_file="${WEB_ROOT}/config.php"
    [[ -f "$config_file" ]] || return 0

    if grep -E "define\([[:space:]]*['\"]APP_BASE_URL['\"][[:space:]]*,[[:space:]]*['\"]https://mail-proxy\.local['\"]" "$config_file" >/dev/null 2>&1
    then
        fatal "Placeholder URL still present"
    fi
}

verify_php_syntax() {
    if ! command -v php >/dev/null 2>&1
    then
        log_warn "PHP CLI not installed"
        return 0
    fi
    local file
    while IFS= read -r file
    do
        php -l "$file" >/dev/null || fatal "PHP syntax error: $file"
    done < <(find "$WEB_ROOT" -type f -name "*.php")
}

secure_web_permissions() {
    chown -R root:root "$WEB_ROOT"
    find "$WEB_ROOT" -type d -exec chmod 0755 {} \;
    find "$WEB_ROOT" -type f -exec chmod 0644 {} \;
}

create_web_health_marker() {
    local marker="${WEB_ROOT}/.delta-transit-installed"
    cat > "$marker" <<EOF
installed=$(date -Is)
version=${SCRIPT_VERSION}
url=${PARAM_APP_URL}
EOF
    chmod 0644 "$marker"
    chown root:root "$marker"
}

phase_web() {

    log_info "Phase: Web"

    validate_public_url
    validate_web_distribution
    create_web_root
    deploy_web_files
    update_php_web_config
    verify_app_base_url_written
    verify_php_syntax
    secure_web_permissions
    create_web_health_marker

    PHASE_STATUS["Web"]="OK"
    log_ok "Web phase completed"
}

# =============================================================================
# ФАЗА 6: Postfix
# =============================================================================

POSTFIX_MESSAGE_LIMIT="209715200"
POSTFIX_MAILBOX_LIMIT="314572800"

ensure_postfix_installed() {
    command -v postconf >/dev/null 2>&1 || fatal "Postfix not installed"
}

set_postfix_parameter() {
    postconf -e "${1} = ${2}"
}

configure_postfix_limits() {
    log_info "Configuring Postfix limits"
    set_postfix_parameter message_size_limit   "$POSTFIX_MESSAGE_LIMIT"
    set_postfix_parameter mailbox_size_limit   "$POSTFIX_MAILBOX_LIMIT"
    set_postfix_parameter virtual_mailbox_limit "$POSTFIX_MAILBOX_LIMIT"
}

configure_postfix_tls_defaults() {
    set_postfix_parameter smtp_tls_security_level  may
    set_postfix_parameter smtpd_tls_security_level may
}

validate_postfix_configuration() {
    postfix check || fatal "Postfix configuration validation failed"
}

reload_postfix() {
    if systemctl is-active --quiet postfix
    then systemctl reload postfix
    else systemctl start  postfix
    fi
}

verify_postfix_value() {
    local key="$1" expected="$2" actual
    actual="$(postconf -h "$key")"
    [[ "$actual" == "$expected" ]] || fatal "Postfix parameter mismatch: $key"
}

verify_postfix_limits() {
    verify_postfix_value message_size_limit    "$POSTFIX_MESSAGE_LIMIT"
    verify_postfix_value mailbox_size_limit    "$POSTFIX_MAILBOX_LIMIT"
    verify_postfix_value virtual_mailbox_limit "$POSTFIX_MAILBOX_LIMIT"
}

phase_postfix() {

    log_info "Phase: Postfix"

    ensure_postfix_installed
    configure_postfix_limits
    configure_postfix_tls_defaults
    validate_postfix_configuration
    reload_postfix
    verify_postfix_limits

    PHASE_STATUS["Postfix"]="OK"
    log_ok "Postfix phase completed"
}

# =============================================================================
# ФАЗА 7: Dovecot
# =============================================================================

DOVECOT_MAIN_CONF="/etc/dovecot/dovecot.conf"
DOVECOT_MAIL_CONF="/etc/dovecot/conf.d/10-mail.conf"
DOVECOT_IMAP_CONF="/etc/dovecot/conf.d/20-imap.conf"

ensure_dovecot_installed() {
    command -v doveconf >/dev/null 2>&1 || fatal "Dovecot not installed"
}

ensure_dovecot_parameter() {
    local file="$1" key="$2" value="$3"
    [[ -f "$file" ]] || return 0
    if grep -Eq "^[[:space:]#]*${key}[[:space:]]*=" "$file"
    then
        sed -ri "s|^[[:space:]#]*${key}[[:space:]]*=.*|${key} = ${value}|g" "$file"
    else
        printf "\n%s = %s\n" "$key" "$value" >> "$file"
    fi
}

ensure_dovecot_protocol_parameter() {
    local file="$1" protocol="$2" key="$3" value="$4"
    [[ -f "$file" ]] || return 0
    grep -Eq "^[[:space:]]*protocol[[:space:]]+${protocol}[[:space:]]*\{" "$file" || return 0

    awk -v proto="$protocol" -v key="$key" -v val="$value" '
        BEGIN { in_block = 0; depth = 0 }
        {
            if (in_block == 0 && $0 ~ "^[[:space:]]*protocol[[:space:]]+" proto "[[:space:]]*\\{") {
                in_block = 1
                depth = 1
                print
                next
            }
            if (in_block == 1) {
                if ($0 ~ "^[[:space:]#]*" key "[[:space:]]*=") {
                    sub("^[[:space:]#]*" key "[[:space:]]*=.*", "    " key " = " val)
                    print
                    next
                }
                if ($0 ~ /\{/) depth++
                if ($0 ~ /\}/) {
                    depth--
                    if (depth == 0) in_block = 0
                }
            }
            print
        }
    ' "$file" > "${file}.tmp.$$" && mv "${file}.tmp.$$" "$file"
}

configure_dovecot_limits() {
    ensure_dovecot_parameter "$DOVECOT_MAIL_CONF" mail_max_userip_connections 50
    ensure_dovecot_parameter "$DOVECOT_IMAP_CONF" imap_max_line_length        262144

    ensure_dovecot_protocol_parameter "$DOVECOT_MAIN_CONF" imap mail_max_userip_connections 50
    ensure_dovecot_protocol_parameter "$DOVECOT_MAIN_CONF" pop3 mail_max_userip_connections 50

    ensure_dovecot_parameter "$DOVECOT_MAIN_CONF" imap_max_line_length 262144
}

validate_dovecot_configuration() {
    doveconf >/dev/null || fatal "Dovecot configuration validation failed"
}

reload_dovecot() {
    if systemctl is-active --quiet dovecot
    then
        if systemctl reload dovecot >/dev/null 2>&1
        then
            return 0
        fi
        systemctl restart dovecot
    else
        systemctl start dovecot
    fi
}

verify_dovecot_setting() {
    local key="$1" expected="$2"
    doveconf "$key" | grep -F "$expected" >/dev/null \
        || fatal "Dovecot setting mismatch: $key"
}

verify_dovecot_protocol_setting() {
    local protocol="$1" key="$2" expected="$3"
    doveconf -f "protocol=${protocol}" "$key" | grep -F "$expected" >/dev/null \
        || fatal "Dovecot setting mismatch: $key (protocol $protocol)"
}

verify_dovecot_limits() {
    verify_dovecot_protocol_setting imap mail_max_userip_connections 50
    verify_dovecot_protocol_setting pop3 mail_max_userip_connections 50
    verify_dovecot_setting imap_max_line_length "256 k"
}

phase_dovecot() {

    log_info "Phase: Dovecot"

    ensure_dovecot_installed
    configure_dovecot_limits
    validate_dovecot_configuration
    reload_dovecot
    verify_dovecot_limits

    PHASE_STATUS["Dovecot"]="OK"
    log_ok "Dovecot phase completed"
}

# =============================================================================
# ФАЗА 8: PHP
# =============================================================================

PHP_UPLOAD_LIMIT="200M"
PHP_POST_LIMIT="210M"
PHP_MEMORY_LIMIT="512M"
PHP_MAX_EXECUTION_TIME="600"
PHP_FPM_MIN_VERSION="8.1"
PHP_FPM_MAX_CHILDREN="${PHP_FPM_MAX_CHILDREN:-10}"
PHP_FPM_VERSION=""
PHP_FPM_POOL_CONF=""
PHP_FPM_SERVICE=""

find_php_ini_files() {
    find /etc/php -type f -name php.ini 2>/dev/null
}

detect_php_fpm_environment() {
    PHP_FPM_VERSION="$(
        find /etc/php -mindepth 3 -maxdepth 3 -path '*/fpm/php.ini' 2>/dev/null \
            | sed 's|/etc/php/||;s|/fpm/php.ini||' \
            | sort -V \
            | tail -1
    )"

    if [[ -z "$PHP_FPM_VERSION" ]]; then
        log_error "PHP-FPM not found: no /etc/php/*/fpm/php.ini"
        return 1
    fi

    if [[ "$(printf '%s\n' "$PHP_FPM_MIN_VERSION" "$PHP_FPM_VERSION" | sort -V | tail -1)" != "$PHP_FPM_VERSION" ]]; then
        log_error "PHP ${PHP_FPM_VERSION} is below minimum ${PHP_FPM_MIN_VERSION}"
        return 1
    fi

    PHP_FPM_POOL_CONF="/etc/php/${PHP_FPM_VERSION}/fpm/pool.d/www.conf"
    PHP_FPM_SERVICE="php${PHP_FPM_VERSION}-fpm"

    if ! systemctl list-unit-files --no-pager "${PHP_FPM_SERVICE}.service" 2>/dev/null \
        | awk '{print $1}' | grep -qx "${PHP_FPM_SERVICE}.service"
    then
        local fallback=""
        fallback="$(
            systemctl list-unit-files --no-pager 'php*-fpm.service' 2>/dev/null \
                | awk '/^php[0-9.]+\-fpm\.service/ {print $1; exit}' \
                | sed 's/\.service$//'
        )"
        if [[ -n "$fallback" ]]; then
            log_warn "Unit ${PHP_FPM_SERVICE} not found, using ${fallback}"
            PHP_FPM_SERVICE="$fallback"
            PHP_FPM_VERSION="${fallback#php}"
            PHP_FPM_VERSION="${PHP_FPM_VERSION%-fpm}"
            PHP_FPM_POOL_CONF="/etc/php/${PHP_FPM_VERSION}/fpm/pool.d/www.conf"
        else
            log_error "PHP-FPM systemd unit not found for version ${PHP_FPM_VERSION}"
            return 1
        fi
    fi

    log_info "Detected PHP-FPM ${PHP_FPM_VERSION} (service=${PHP_FPM_SERVICE})"
    return 0
}

set_php_pool_parameter() {
    local file="$1" key="$2" value="$3"
    if grep -Eq "^[[:space:]]*;?[[:space:]]*${key}[[:space:]]*=" "$file"
    then
        sed -ri "s|^[[:space:]]*;?[[:space:]]*${key}[[:space:]]*=.*|${key} = ${value}|g" "$file"
    else
        printf "\n%s = %s\n" "$key" "$value" >> "$file"
    fi
}

configure_php_fpm_pool() {
    [[ -f "$PHP_FPM_POOL_CONF" ]] || fatal "PHP-FPM pool config not found: ${PHP_FPM_POOL_CONF}"
    set_php_pool_parameter "$PHP_FPM_POOL_CONF" pm.max_children "$PHP_FPM_MAX_CHILDREN"
    log_info "PHP-FPM pool: pm.max_children=${PHP_FPM_MAX_CHILDREN} (override: PHP_FPM_MAX_CHILDREN)"
}

set_php_parameter() {
    local file="$1" key="$2" value="$3"
    if grep -Eq "^[[:space:]]*${key}[[:space:]]*=" "$file"
    then
        sed -ri "s|^[[:space:]]*${key}[[:space:]]*=.*|${key} = ${value}|g" "$file"
    else
        printf "\n%s = %s\n" "$key" "$value" >> "$file"
    fi
}

configure_php_ini() {
    local file="$1"
    set_php_parameter "$file" upload_max_filesize  "$PHP_UPLOAD_LIMIT"
    set_php_parameter "$file" post_max_size        "$PHP_POST_LIMIT"
    set_php_parameter "$file" memory_limit         "$PHP_MEMORY_LIMIT"
    set_php_parameter "$file" max_execution_time   "$PHP_MAX_EXECUTION_TIME"
}

configure_all_php_ini() {
    local file
    while IFS= read -r file
    do
        configure_php_ini "$file"
    done < <(find_php_ini_files)
}

restart_php_fpm() {
    detect_php_fpm_environment || fatal "PHP-FPM not detected"
    systemctl restart "$PHP_FPM_SERVICE"
}

verify_php_limit() {
    local file="$1" key="$2" expected="$3"
    grep -Eq "^[[:space:]]*${key}[[:space:]]*=[[:space:]]*${expected}" "$file" \
        || fatal "PHP setting mismatch: ${key}"
}

verify_php_configuration() {
    local file
    while IFS= read -r file
    do
        verify_php_limit "$file" upload_max_filesize "$PHP_UPLOAD_LIMIT"
        verify_php_limit "$file" post_max_size       "$PHP_POST_LIMIT"
    done < <(find_php_ini_files)

    [[ -f "$PHP_FPM_POOL_CONF" ]] || fatal "PHP-FPM pool config not found: ${PHP_FPM_POOL_CONF}"
    grep -Eq "^[[:space:]]*;?[[:space:]]*pm\\.max_children[[:space:]]*=[[:space:]]*${PHP_FPM_MAX_CHILDREN}" \
        "$PHP_FPM_POOL_CONF" \
        || fatal "PHP-FPM pm.max_children mismatch"
}

phase_php() {

    log_info "Phase: PHP"

    if ! command -v php >/dev/null 2>&1
    then
        log_warn "PHP not installed"
        PHASE_STATUS["PHP"]="SKIPPED"
        return 0
    fi

    detect_php_fpm_environment || fatal "PHP-FPM not detected"

    configure_all_php_ini
    configure_php_fpm_pool
    restart_php_fpm
    verify_php_configuration

    PHASE_STATUS["PHP"]="OK"
    log_ok "PHP phase completed"
}

# -----------------------------------------------------------------------------
# Проверка инфраструктуры Nginx
# -----------------------------------------------------------------------------

verify_nginx_directories() {

    local dirs=(
        /etc/nginx
        /etc/nginx/conf.d
        /etc/nginx/sites-available
        /etc/nginx/sites-enabled
    )

    local d

    for d in "${dirs[@]}"
    do
        [[ -d "$d" ]] || fatal "Nginx directory missing: $d"
        [[ -r "$d" ]] || fatal "Nginx directory not readable: $d"
        [[ -w "$d" ]] || fatal "Nginx directory not writable: $d"
    done
}

verify_nginx_binary() {

    command -v nginx >/dev/null 2>&1 \
        || fatal "nginx binary not found"

    nginx -v >/dev/null 2>&1 \
        || fatal "nginx executable validation failed"
}

# -----------------------------------------------------------------------------
# Извлечение FQDN из PARAM_APP_URL
# -----------------------------------------------------------------------------

extract_app_hostname() {

    NGINX_SERVER_NAME="$(
        printf '%s\n' "$PARAM_APP_URL" \
            | sed -E 's#^https://##' \
            | sed -E 's#/.*$##'
    )"

    [[ -n "$NGINX_SERVER_NAME" ]] \
        || fatal "Unable to extract hostname from PARAM_APP_URL"
}

# -----------------------------------------------------------------------------
# Проверка конфликта с iRedMail/Postfix
# -----------------------------------------------------------------------------

check_iredmail_hostname_conflict() {

    local postfix_hostname=""

    postfix_hostname="$(
        postconf -h myhostname 2>/dev/null || true
    )"

    if [[ -n "$postfix_hostname" ]]
    then
        if [[ "$postfix_hostname" == "$NGINX_SERVER_NAME" ]]
        then
            fatal "Conflict detected: mail-proxy hostname equals Postfix hostname (${postfix_hostname})"
        fi
    fi
}

# -----------------------------------------------------------------------------
# Проверка конфликта server_name
# -----------------------------------------------------------------------------

check_nginx_server_name_conflict() {

    local conflicts=""
    local scan_rc=0
    local own_realpath
    own_realpath="$(realpath "${NGINX_SITE_AVAILABLE}" 2>/dev/null || echo "")"

    # Under set -o pipefail, grep exit 1 ("no matches") would abort the
    # installer. Capture it: 0 = matches, 1 = none, >1 = real scan failure.
    conflicts="$(
        grep -Rnw \
            /etc/nginx/sites-available \
            /etc/nginx/sites-enabled \
            -e "server_name[[:space:]].*${NGINX_SERVER_NAME}" \
            2>/dev/null \
        | while IFS=: read -r file line rest; do
            if [[ "$file" == *.bak_* ]]; then
                continue
            fi
            if [[ -n "$own_realpath" ]] \
                && [[ "$(realpath "$file" 2>/dev/null || echo "")" == "$own_realpath" ]]
            then
                continue
            fi
            echo "$file:$line:$rest"
          done
    )" || scan_rc=$?

    if [[ "$scan_rc" -gt 1 ]]; then
        fatal "Failed to scan nginx configs for server_name conflicts (exit ${scan_rc})"
    fi

    if [[ -n "$conflicts" ]]
    then
        echo
        echo "$conflicts"
        echo

        fatal "Nginx server_name conflict detected: ${NGINX_SERVER_NAME}"
    fi

    NGINX_CONFLICTS="none"
}

# -----------------------------------------------------------------------------
# Автоопределение PHP-FPM сокета (улучшено для TCP)
# -----------------------------------------------------------------------------

detect_php_fpm_socket() {

    # 1. Попытка найти UNIX-сокет в стандартных местах
    local found_socket=""
    found_socket="$(find /run/php /var/run/php /etc/php -maxdepth 2 -type s -name "php*-fpm.sock" 2>/dev/null | head -n1)"
    if [[ -n "$found_socket" ]]; then
        PHP_FPM_SOCKET_DETECTED="$found_socket"
        log_info "PHP-FPM UNIX socket detected: $PHP_FPM_SOCKET_DETECTED"
        return 0
    fi

    # 2. Попытка найти TCP-сокет через конфигурацию www.conf
    local www_conf
    www_conf="$(find /etc/php -name www.conf 2>/dev/null | head -n1)"
    if [[ -n "$www_conf" ]]; then
        local listen_value=""
        # Under set -o pipefail, grep exit 1 (no listen= line) would abort;
        # empty listen_value is already handled below.
        listen_value="$(grep -E '^listen\s*=' "$www_conf" | head -n1 | sed -E 's/^listen\s*=\s*//' | tr -d ' ')" || true
        if [[ -n "$listen_value" ]]; then
            if [[ "$listen_value" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+:[0-9]+$ ]] || [[ "$listen_value" =~ ^[0-9]+$ ]]; then
                # Это TCP-адрес или порт
                if [[ "$listen_value" =~ ^[0-9]+$ ]]; then
                    # только порт — предполагаем localhost
                    listen_value="127.0.0.1:${listen_value}"
                fi
                PHP_FPM_SOCKET_DETECTED="$listen_value"
                log_info "PHP-FPM TCP endpoint detected: $PHP_FPM_SOCKET_DETECTED (from $www_conf)"
                return 0
            elif [[ "$listen_value" =~ ^/ ]] || [[ "$listen_value" =~ ^unix: ]]; then
                # Это UNIX-сокет (но мы его уже искали, но возможно другой путь)
                PHP_FPM_SOCKET_DETECTED="${listen_value#unix:}"
                log_info "PHP-FPM UNIX socket detected: $PHP_FPM_SOCKET_DETECTED (from $www_conf)"
                return 0
            fi
        fi
    fi

    # 3. Если ничего не нашли — запрашиваем вручную
    log_warn "PHP-FPM socket not found automatically."
    echo "Укажите путь к UNIX-сокету (например, /run/php/php8.3-fpm.sock) или TCP-адрес (например, 127.0.0.1:9999)"
    echo "Если вы не знаете, оставьте пустым и мы попробуем продолжить без веб-панели."
    read -rp "Путь к сокету или TCP-адрес: " user_input

    if [[ -n "$user_input" ]]; then
        # Проверяем, является ли введённое сокетом
        if [[ -S "$user_input" ]]; then
            PHP_FPM_SOCKET_DETECTED="$user_input"
            log_info "Using user-provided UNIX socket: $PHP_FPM_SOCKET_DETECTED"
            return 0
        elif [[ "$user_input" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+:[0-9]+$ ]] || [[ "$user_input" =~ ^[0-9]+$ ]]; then
            # TCP-адрес или порт
            if [[ "$user_input" =~ ^[0-9]+$ ]]; then
                user_input="127.0.0.1:${user_input}"
            fi
            PHP_FPM_SOCKET_DETECTED="$user_input"
            log_info "Using user-provided TCP endpoint: $PHP_FPM_SOCKET_DETECTED"
            return 0
        else
            fatal "Provided input is neither a valid socket nor TCP address: $user_input"
        fi
    else
        fatal "PHP-FPM socket is required for web panel. Please install PHP-FPM and ensure it's running."
    fi
}

# -----------------------------------------------------------------------------
# Самоподписанный сертификат
# -----------------------------------------------------------------------------

generate_self_signed_certificate() {

    if [[ -f "$NGINX_SSL_CERT" && -f "$NGINX_SSL_KEY" ]]
    then
        SSL_MODE="existing"
        return 0
    fi

    log_info "Generating self-signed certificate"

    install -d \
        -m 0755 \
        -o root \
        -g root \
        /etc/ssl/certs

    install -d \
        -m 0700 \
        -o root \
        -g root \
        /etc/ssl/private

    openssl req \
        -x509 \
        -nodes \
        -days 3650 \
        -newkey rsa:4096 \
        -keyout "$NGINX_SSL_KEY" \
        -out "$NGINX_SSL_CERT" \
        -subj "/CN=${NGINX_SERVER_NAME}"

    chmod 0600 "$NGINX_SSL_KEY"
    chmod 0644 "$NGINX_SSL_CERT"

    chown root:root "$NGINX_SSL_KEY"
    chown root:root "$NGINX_SSL_CERT"

    SSL_MODE="self-signed"
}

# -----------------------------------------------------------------------------
# Создание полноценного Virtual Host для DELTA-Транзит
# -----------------------------------------------------------------------------

create_mail_proxy_virtual_host() {

    log_info "Creating Nginx virtual host"

    # Определяем, как использовать fastcgi_pass
    local fastcgi_pass_value
    if [[ "$PHP_FPM_SOCKET_DETECTED" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+:[0-9]+$ ]] || [[ "$PHP_FPM_SOCKET_DETECTED" =~ ^[0-9]+$ ]]; then
        # TCP-адрес или порт
        if [[ "$PHP_FPM_SOCKET_DETECTED" =~ ^[0-9]+$ ]]; then
            fastcgi_pass_value="127.0.0.1:${PHP_FPM_SOCKET_DETECTED}"
        else
            fastcgi_pass_value="${PHP_FPM_SOCKET_DETECTED}"
        fi
    else
        # UNIX-сокет (путь)
        fastcgi_pass_value="unix:${PHP_FPM_SOCKET_DETECTED}"
    fi

    cat > "$NGINX_SITE_AVAILABLE" <<EOF
# =============================================================================
# DELTA-Транзит
# Автоматически создано установщиком
# =============================================================================

server {

    listen 443 ssl;
    listen [::]:443 ssl;

    server_name ${NGINX_SERVER_NAME};

    ssl_certificate     ${NGINX_SSL_CERT};
    ssl_certificate_key ${NGINX_SSL_KEY};

    root ${WEB_ROOT};
    index index.php index.html;

    # -------------------------------------------------------------------------
    # Ограничение доступа согласно руководству
    # -------------------------------------------------------------------------

    allow 10.0.0.0/8;
    allow 172.16.0.0/12;
    allow 192.168.0.0/16;
    allow 127.0.0.1;
    allow ::1;

    deny all;

    # -------------------------------------------------------------------------
    # Требование руководства
    # -------------------------------------------------------------------------

    client_max_body_size 210M;

    access_log /var/log/nginx/mail-proxy-access.log;
    error_log  /var/log/nginx/mail-proxy-error.log;

    location / {

        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {

        include snippets/fastcgi-php.conf;

        fastcgi_pass ${fastcgi_pass_value};

        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;

        include fastcgi_params;
    }

    location ~ /\. {

        deny all;
    }
}
EOF

    chmod 0644 "$NGINX_SITE_AVAILABLE"

    chown root:root "$NGINX_SITE_AVAILABLE"

    [[ -f "$NGINX_SITE_AVAILABLE" ]] \
        || fatal "Virtual host creation failed"

    NGINX_VHOST_CREATED="yes"
}

# -----------------------------------------------------------------------------
# Включение Virtual Host
# -----------------------------------------------------------------------------

enable_mail_proxy_virtual_host() {

    if [[ -L "$NGINX_SITE_ENABLED" ]]
    then
        NGINX_VHOST_ENABLED="yes"
        return 0
    fi

    ln -s \
        "$NGINX_SITE_AVAILABLE" \
        "$NGINX_SITE_ENABLED"

    if [[ -L "$NGINX_SITE_ENABLED" ]]
    then
        NGINX_VHOST_ENABLED="yes"
    else
        log_warn "Virtual host created but not enabled"
        NGINX_VHOST_ENABLED="no"
    fi
}

# -----------------------------------------------------------------------------
# Проверка итоговой конфигурации Nginx
# -----------------------------------------------------------------------------

validate_nginx_configuration() {

    nginx -t >/tmp/nginx-validation.log 2>&1 \
        || fatal "nginx configuration validation failed"

    rm -f /tmp/nginx-validation.log
}

# =============================================================================
# ФАЗА 9: Nginx
# =============================================================================

NGINX_BODY_LIMIT="210M"

install_nginx_delta_profile() {

    log_info "Installing DELTA Nginx profile"

    cat > "$NGINX_INCLUDE_CONF" <<'EOF'
# =============================================================================
# DELTA-Транзит
# Увеличение допустимого размера загружаемых файлов
# =============================================================================

client_max_body_size 210M;
EOF

    chmod 0644 "$NGINX_INCLUDE_CONF"

    chown root:root "$NGINX_INCLUDE_CONF"

    grep -q '^client_max_body_size 210M;' "$NGINX_INCLUDE_CONF" \
        || fatal "Required upload limit not configured"
}

phase_nginx() {

    log_info "Phase: Nginx"
    verify_nginx_directories
    verify_nginx_binary
    extract_app_hostname
    check_iredmail_hostname_conflict
    check_nginx_server_name_conflict
    detect_php_fpm_socket
    install_nginx_delta_profile
    generate_self_signed_certificate
    create_mail_proxy_virtual_host
    enable_mail_proxy_virtual_host
    validate_nginx_configuration
    if systemctl is-active --quiet nginx; then systemctl reload nginx; else systemctl start nginx; fi
    PHASE_STATUS["Nginx"]="OK"
}
# =============================================================================
# ФАЗА 10: systemd
# =============================================================================

SERVICE_FILE="./mail-proxy.service"
SYSTEMD_TARGET="/etc/systemd/system/mail-proxy.service"

validate_service_source() {
    [[ -f "$SERVICE_FILE" ]] || fatal "mail-proxy.service not found"
}

install_systemd_unit() {
    local tmp
    tmp="$(mktemp)"
    register_tmpfile "$tmp"
    cp "$SERVICE_FILE" "$tmp"
    chmod 0644 "$tmp"
    chown root:root "$tmp"

    sed -i '/^+[^+]/d' "$tmp"

    sed -i "s|/usr/bin/python3|${VENV_PATH}/bin/python3|g" "$tmp"

    mv "$tmp" "$SYSTEMD_TARGET"
}

verify_systemd_unit() {
    if command -v systemd-analyze >/dev/null 2>&1
    then
        systemd-analyze verify "$SYSTEMD_TARGET" >/dev/null
    else
        log_warn "systemd-analyze not available, skipping verify"
    fi
}

reload_systemd() { systemctl daemon-reload; }

enable_service() { systemctl enable mail-proxy.service; }

verify_unit_paths() {
    grep -F "${VENV_PATH}/bin/python3" "$SYSTEMD_TARGET" >/dev/null \
        || fatal "VENV python not found in unit"
}

install_logrotate() {
    if [[ ! -f ./logrotate-mail-proxy ]]
    then
        log_warn "logrotate-mail-proxy not found"
        return 0
    fi
    install -m 0644 -o root -g root \
        ./logrotate-mail-proxy /etc/logrotate.d/mail-proxy
}

phase_systemd() {

    log_info "Phase: systemd"

    validate_service_source
    install_systemd_unit
    verify_unit_paths
    verify_systemd_unit
    install_logrotate
    reload_systemd
    enable_service

    PHASE_STATUS["Systemd"]="OK"
    log_ok "systemd phase completed"
}

# =============================================================================
# ФАЗА 11: Service Validation
# =============================================================================

wait_for_service() {
    local i
    for ((i=0; i<20; i++))
    do
        if systemctl is-active --quiet mail-proxy.service
        then
            return 0
        fi
        sleep 1
    done
    return 1
}

VALIDATION_LOG_SINCE_EPOCH=""

start_mail_proxy() {
    systemctl restart mail-proxy.service
}

capture_validation_log_boundary() {
    local active_enter_ts

    active_enter_ts="$(
        systemctl show mail-proxy.service -p ActiveEnterTimestamp --value 2>/dev/null || true
    )"

    if [[ -z "$active_enter_ts" || "$active_enter_ts" == "n/a" ]]
    then
        fatal "Unable to determine mail-proxy ActiveEnterTimestamp for log validation"
    fi

    VALIDATION_LOG_SINCE_EPOCH="$(date -d "$active_enter_ts" +%s 2>/dev/null || echo 0)"
    if [[ "$VALIDATION_LOG_SINCE_EPOCH" -le 0 ]]
    then
        fatal "Unable to parse mail-proxy ActiveEnterTimestamp: ${active_enter_ts}"
    fi

    log_info "Validation log boundary: ${active_enter_ts} (epoch ${VALIDATION_LOG_SINCE_EPOCH})"
}

runtime_log_line_epoch() {
    local line_ts="$1"
    date -d "$line_ts" +%s 2>/dev/null || echo 0
}

runtime_log_line_is_error() {
    local line="$1"
    echo "$line" | grep -qE '\[(CRITICAL|ERROR)\]' \
        || echo "$line" | grep -qE 'Traceback \(most recent call last\):'
}

verify_runtime_log() {
    local errors=""
    local line line_ts line_epoch

    [[ -n "$VALIDATION_LOG_SINCE_EPOCH" ]] \
        || fatal "Validation log boundary not recorded"

    while IFS= read -r line
    do
        [[ -n "$line" ]] || continue

        line_ts="$(
            echo "$line" | grep -oE '^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}' || true
        )"
        [[ -n "$line_ts" ]] || continue

        line_epoch="$(runtime_log_line_epoch "$line_ts")"
        [[ "$line_epoch" -ge "$VALIDATION_LOG_SINCE_EPOCH" ]] || continue

        if runtime_log_line_is_error "$line"
        then
            errors+="${line}"$'\n'
        fi
    done < <(tail -n 500 "$DAEMON_LOG")

    if [[ -n "$errors" ]]
    then
        echo
        echo "$errors"
        echo
        fatal "Runtime errors detected since current service start"
    fi
}

verify_service_active() {
    wait_for_service || fatal "mail-proxy failed to start"
}

verify_log_exists() {
    [[ -f "$DAEMON_LOG" ]] || fatal "Daemon log not found"
}

verify_no_change_me() {
    grep -R "CHANGE_ME" "$CONFIG_DIR" >/dev/null 2>&1 \
        && fatal "CHANGE_ME found in configuration"
    return 0
}

verify_no_placeholder_url() {
    local config_file="${WEB_ROOT}/config.php"
    [[ -f "$config_file" ]] || return 0

    if grep -E "define\([[:space:]]*['\"]APP_BASE_URL['\"][[:space:]]*,[[:space:]]*['\"]https://mail-proxy\.local['\"]" "$config_file" >/dev/null 2>&1
    then
        fatal "Placeholder APP_BASE_URL found"
    fi
    return 0
}

verify_process_running() {
    local i
    for ((i=0; i<10; i++))
    do
        if pgrep -f mail-proxy-daemon.py >/dev/null
        then
            return 0
        fi
        sleep 1
    done
    fatal "Daemon process not found"
}

verify_database_login() {
    validate_db_connectivity
}

# -----------------------------------------------------------------------------
# Проверка существования Virtual Host
# -----------------------------------------------------------------------------

validate_nginx_virtual_host() {

    [[ -f "$NGINX_SITE_AVAILABLE" ]] \
        || fatal "Validation failed: Nginx virtual host missing"

    [[ -L "$NGINX_SITE_ENABLED" ]] \
        || fatal "Validation failed: Nginx virtual host not enabled"

    log_info "Nginx virtual host exists"
}

# -----------------------------------------------------------------------------
# Проверка server_name
# -----------------------------------------------------------------------------

validate_nginx_server_name() {

    grep -q \
        "server_name ${NGINX_SERVER_NAME};" \
        "$NGINX_SITE_AVAILABLE" \
        || fatal "Validation failed: server_name mismatch"

    log_info "server_name matches APP_URL"
}

# -----------------------------------------------------------------------------
# Проверка сертификатов
# -----------------------------------------------------------------------------

validate_nginx_ssl() {

    [[ -f "$NGINX_SSL_CERT" ]] \
        || fatal "Validation failed: SSL certificate missing"

    [[ -f "$NGINX_SSL_KEY" ]] \
        || fatal "Validation failed: SSL key missing"

    log_info "SSL certificate present"
}

# -----------------------------------------------------------------------------
# Проверка PHP-FPM сокета
# -----------------------------------------------------------------------------

validate_php_fpm_socket() {

    # Проверяем, доступен ли сокет/TCP-адрес
    if [[ "$PHP_FPM_SOCKET_DETECTED" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+:[0-9]+$ ]]; then
        # TCP-адрес — проверяем, слушает ли порт
        local host="${PHP_FPM_SOCKET_DETECTED%:*}"
        local port="${PHP_FPM_SOCKET_DETECTED#*:}"
        if ! nc -z "$host" "$port" 2>/dev/null; then
            log_warn "TCP port $port on $host does not seem to be listening"
            # Не фатально, так как может не работать netcat
        fi
    else
        # UNIX-сокет
        [[ -S "$PHP_FPM_SOCKET_DETECTED" ]] \
            || fatal "Validation failed: PHP-FPM socket missing: $PHP_FPM_SOCKET_DETECTED"
    fi

    log_info "PHP-FPM endpoint validated"
}

# -----------------------------------------------------------------------------
# Проверка обязательного лимита загрузки
# -----------------------------------------------------------------------------

validate_upload_limit() {

    grep -q \
        '^client_max_body_size 210M;' \
        "$NGINX_INCLUDE_CONF" \
        || fatal "Validation failed: upload limit missing"

    grep -q \
        'client_max_body_size 210M;' \
        "$NGINX_SITE_AVAILABLE" \
        || fatal "Validation failed: upload limit missing in virtual host"

    log_info "Upload limit configured"
}

# -----------------------------------------------------------------------------
# Повторная проверка конфликтов
# -----------------------------------------------------------------------------

validate_nginx_conflicts() {

    local conflicts=""
    local scan_rc=0
    local own_realpath
    own_realpath="$(realpath "${NGINX_SITE_AVAILABLE}" 2>/dev/null || echo "")"

    # Under set -o pipefail, grep exit 1 ("no matches") would abort the
    # installer. Capture it: 0 = matches, 1 = none, >1 = real scan failure.
    # Ищем все файлы с server_name, исключая наш собственный (по реальному пути)
    conflicts="$(
        grep -Rnw \
            /etc/nginx/sites-enabled \
            /etc/nginx/sites-available \
            -e "server_name[[:space:]].*${NGINX_SERVER_NAME}" \
            2>/dev/null \
        | while IFS=: read -r file line rest; do
            if [[ "$file" == *.bak_* ]]; then
                continue
            fi
            if [[ -n "$own_realpath" ]] && [[ "$(realpath "$file" 2>/dev/null || echo "")" != "$own_realpath" ]]; then
                echo "$file:$line:$rest"
            fi
          done
    )" || scan_rc=$?

    if [[ "$scan_rc" -gt 1 ]]; then
        fatal "Failed to scan nginx configs for server_name conflicts (exit ${scan_rc})"
    fi

    if [[ -n "$conflicts" ]]; then
        echo
        echo "$conflicts"
        echo
        fatal "Validation failed: conflicting server_name detected"
    fi

    log_info "No conflicting server_name found"
}

# -----------------------------------------------------------------------------
# Проверка итоговой конфигурации
# -----------------------------------------------------------------------------

validate_nginx_test() {

    nginx -t >/dev/null 2>&1 \
        || fatal "Validation failed: nginx configuration test failed"

    log_info "Nginx configuration test passed"
}


phase_validation() {

    log_info "Phase: Validation"

    start_mail_proxy
    verify_service_active
    capture_validation_log_boundary
    verify_process_running
    verify_log_exists
    verify_database_login
    validate_panel_auth
    verify_no_change_me
    verify_no_placeholder_url
    verify_runtime_log
    validate_nginx_virtual_host
    validate_nginx_server_name
    validate_php_fpm_socket
    validate_upload_limit
    validate_nginx_conflicts
    validate_nginx_ssl
    validate_nginx_test
    PHASE_STATUS["Validation"]="OK"
    log_ok "Validation phase completed"
}

# =============================================================================
# ФАЗА 12: Production Audit
# =============================================================================

audit_maildir_protection() {
    if id -nG www-data | tr ' ' '\n' | grep -qx vmail
    then
        fatal "www-data still member of vmail group"
    fi
}

audit_crypto_permissions() {
    [[ -f "$CRYPTO_KEY" ]] || fatal "crypto.key missing"
    [[ "$(stat -c '%a'    "$CRYPTO_KEY")" == "640"                ]] || fatal "crypto.key permissions invalid"
    [[ "$(stat -c '%U:%G' "$CRYPTO_KEY")" == "root:${GROUP_CRYPTO}" ]] || fatal "crypto.key ownership invalid"
}

audit_dbconf_permissions() {
    [[ -f "$DB_CONF" ]] || fatal "db.conf missing"
    [[ "$(stat -c '%a'    "$DB_CONF")" == "640"                ]] || fatal "db.conf permissions invalid"
    [[ "$(stat -c '%U:%G' "$DB_CONF")" == "root:${GROUP_CRYPTO}" ]] || fatal "db.conf ownership invalid"
}

audit_database_contents() {
    local tables_out db_pass
    tables_out="$(mktemp)"
    register_tmpfile "$tables_out"
    db_pass="$(read_db_pass_from_conf)"
    mysql \
        -u "${DB_USER}" \
        -p"${db_pass}" \
        "${DB_NAME}" \
        -e "SHOW TABLES;" \
        > "$tables_out"
    grep -q . "$tables_out" || fatal "Database schema appears empty"
}

audit_required_files() {
    local files=(
        "$CRYPTO_KEY"
        "$DB_CONF"
        "$SYSTEMD_TARGET"
        "$DAEMON_TARGET"
    )
    local file
    for file in "${files[@]}"
    do
        [[ -f "$file" ]] || fatal "Required file missing: $file"
    done
}

production_audit() {
    audit_maildir_protection
    audit_crypto_permissions
    audit_dbconf_permissions
    audit_database_contents
    audit_required_files
}

write_install_report() {
    local report="/root/delta-transit-install-report.txt"
    cat > "$report" <<EOF
DELTA-transit Installation Report

Date:
$(date -Is)

Version:
${SCRIPT_VERSION}

Database:
${DB_NAME}

Database User:
${DB_USER}

Application URL:
${PARAM_APP_URL}

Daemon:
${DAEMON_TARGET}

Systemd:
${SYSTEMD_TARGET}

Status:
SUCCESS
EOF
    chmod 0600 "$report"
    chown root:root "$report"
}

phase_audit() {

    log_info "Phase: Production audit"

    production_audit
    write_install_report

    PHASE_STATUS["Audit"]="OK"
    log_ok "Production audit completed"
}

# =============================================================================
# Main
# =============================================================================

initialize_phase_status() {
    PHASE_STATUS["Preflight"]="PENDING"
    PHASE_STATUS["Packages"]="PENDING"
    PHASE_STATUS["Database"]="PENDING"
    PHASE_STATUS["UsersGroups"]="PENDING"
    PHASE_STATUS["Python"]="PENDING"
    PHASE_STATUS["Web"]="PENDING"
    PHASE_STATUS["Postfix"]="PENDING"
    PHASE_STATUS["Dovecot"]="PENDING"
    PHASE_STATUS["PHP"]="PENDING"
    PHASE_STATUS["Nginx"]="PENDING"
    PHASE_STATUS["Systemd"]="PENDING"
    PHASE_STATUS["Validation"]="PENDING"
    PHASE_STATUS["Audit"]="PENDING"
}

run_all_phases() {
    phase_preflight
    phase_packages
    phase_database
    phase_users_groups
    phase_python
    phase_web
    phase_postfix
    phase_dovecot
    phase_php
    phase_nginx
    phase_systemd
    phase_validation
    phase_audit
}

main() {
    install_traps

    if [[ "${DELTA_VALIDATION_LOG_ONLY:-}" == "1" ]]
    then
        require_root
        verify_log_exists
        capture_validation_log_boundary
        verify_runtime_log
        log_ok "Runtime log validation passed"
        exit 0
    fi

    if [[ "${DELTA_VALIDATION_ONLY:-}" == "1" ]]
    then
        require_root
        initialize_phase_status
        phase_validation
        render_report
        log_ok "Validation-only run completed"
        exit 0
    fi

    initialize_phase_status
    run_all_phases
    render_report

    echo
    log_ok "DELTA-transit installation completed"
    echo
    echo "Secrets file:"
    echo "$SECRETS_FILE"
    echo
    echo "Report file:"
    echo "/root/delta-transit-install-report.txt"
    echo
    # -----------------------------------------------------------------------------
    # NGINX Virtual Host
    # -----------------------------------------------------------------------------

    echo "NGINX_VHOST_CREATED=${NGINX_VHOST_CREATED}"

    echo "NGINX_VHOST_ENABLED=${NGINX_VHOST_ENABLED}"

    echo "NGINX_SERVER_NAME=${NGINX_SERVER_NAME}"

    echo "NGINX_CONFLICTS=${NGINX_CONFLICTS}"

    echo "NGINX_UPLOAD_LIMIT=210M"

    echo "PHP_FPM_SOCKET=${PHP_FPM_SOCKET_DETECTED}"

    echo "SSL_MODE=${SSL_MODE}"

    echo "SSL_CERTIFICATE=${NGINX_SSL_CERT}"

    echo "SSL_KEY=${NGINX_SSL_KEY}"
}

main "$@"