#!/usr/bin/env bash
# PHP-FPM SAPI intl verification helpers (issue #151).
# Source from verify-install-regression.sh and delta-transit-install.sh.

PHP_FPM_INTL_READY_MAX_WAIT="${PHP_FPM_INTL_READY_MAX_WAIT:-30}"
PHP_FPM_INTL_READY_INTERVAL="${PHP_FPM_INTL_READY_INTERVAL:-1}"

php_fpm_intl_detect_panel_environment() {
    PHP_FPM_VERSION=""
    PHP_FPM_SERVICE=""
    PHP_FPM_POOL_CONF=""
    PHP_FPM_INI=""
    PHP_FPM_BINARY=""

    PHP_FPM_VERSION="$(
        find /etc/php -mindepth 3 -maxdepth 3 -path '*/fpm/php.ini' 2>/dev/null \
            | sed 's|/etc/php/||;s|/fpm/php.ini||' \
            | sort -V \
            | tail -1
    )"

    if [[ -z "$PHP_FPM_VERSION" ]]; then
        return 1
    fi

    PHP_FPM_POOL_CONF="/etc/php/${PHP_FPM_VERSION}/fpm/pool.d/www.conf"
    PHP_FPM_SERVICE="php${PHP_FPM_VERSION}-fpm"
    PHP_FPM_INI="/etc/php/${PHP_FPM_VERSION}/fpm/php.ini"

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
            PHP_FPM_SERVICE="$fallback"
            PHP_FPM_VERSION="${fallback#php}"
            PHP_FPM_VERSION="${PHP_FPM_VERSION%-fpm}"
            PHP_FPM_POOL_CONF="/etc/php/${PHP_FPM_VERSION}/fpm/pool.d/www.conf"
            PHP_FPM_INI="/etc/php/${PHP_FPM_VERSION}/fpm/php.ini"
        else
            return 1
        fi
    fi

    [[ -f "$PHP_FPM_POOL_CONF" && -f "$PHP_FPM_INI" ]] || return 1
    return 0
}

php_fpm_intl_fpm_binary_path() {
    if [[ -n "${PHP_FPM_INTL_VERIFY_FPM_BINARY:-}" ]]; then
        echo "$PHP_FPM_INTL_VERIFY_FPM_BINARY"
        return 0
    fi
    if [[ -n "${PHP_FPM_INTL_VERIFY_RESOLVE_FPM_BINARY_CMD:-}" ]]; then
        PHP_FPM_INTL_VERIFY_RESOLVE_FPM_BINARY_PATH=""
        local resolve_rc=0
        eval "$PHP_FPM_INTL_VERIFY_RESOLVE_FPM_BINARY_CMD" || resolve_rc=$?
        if [[ $resolve_rc -eq 0 && -n "${PHP_FPM_INTL_VERIFY_RESOLVE_FPM_BINARY_PATH:-}" ]]; then
            echo "$PHP_FPM_INTL_VERIFY_RESOLVE_FPM_BINARY_PATH"
            return 0
        fi
        return 1
    fi

    local ver="${PHP_FPM_VERSION:-}"
    local candidate path=""
    if [[ -n "$ver" ]]; then
        for candidate in "php-fpm${ver}" "/usr/sbin/php-fpm${ver}"; do
            if command -v "$candidate" >/dev/null 2>&1; then
                path="$(command -v "$candidate")"
                PHP_FPM_BINARY="$path"
                echo "$path"
                return 0
            fi
            if [[ -x "$candidate" ]]; then
                PHP_FPM_BINARY="$candidate"
                echo "$candidate"
                return 0
            fi
        done
    fi

    local match
    for match in /usr/sbin/php-fpm[0-9]*.[0-9]*; do
        [[ -e "$match" ]] || continue
        if [[ -z "$ver" ]] || [[ "$match" == *"${ver}"* ]]; then
            PHP_FPM_BINARY="$match"
            echo "$match"
            return 0
        fi
    done
    return 1
}

php_fpm_intl_panel_pool_listen() {
    local pool_conf="$1"
    local listen=""
    listen="$(
        awk -F= '
            /^[[:space:]]*;[[:space:]]*listen[[:space:]]*=/ { next }
            /^[[:space:]]*listen[[:space:]]*=/ {
                sub(/^[^=]*=/, "", $0)
                gsub(/^[[:space:]]+|[[:space:]]+$/, "", $0)
                print $0
                exit
            }
        ' "$pool_conf"
    )"
    listen="${listen#unix:}"
    echo "$listen"
}

php_fpm_intl_pool_endpoint_ready() {
    local endpoint="$1"
    if [[ -n "${PHP_FPM_INTL_VERIFY_ENDPOINT_READY_CMD:-}" ]]; then
        PHP_FPM_INTL_VERIFY_ENDPOINT="$endpoint"
        eval "$PHP_FPM_INTL_VERIFY_ENDPOINT_READY_CMD"
        return $?
    fi
    [[ -n "$endpoint" ]] || return 1
    if [[ "$endpoint" =~ ^/ ]]; then
        [[ -S "$endpoint" ]]
        return $?
    fi
    if [[ "$endpoint" =~ ^127\.0\.0\.1:([0-9]+)$ ]]; then
        local port="${BASH_REMATCH[1]}"
        (echo >/dev/tcp/127.0.0.1/"$port") >/dev/null 2>&1
        return $?
    fi
    return 1
}

php_fpm_intl_service_active() {
    local service="${1:-$PHP_FPM_SERVICE}"
    if [[ -n "${PHP_FPM_INTL_VERIFY_IS_ACTIVE_CMD:-}" ]]; then
        PHP_FPM_INTL_VERIFY_SERVICE_NAME="$service"
        eval "$PHP_FPM_INTL_VERIFY_IS_ACTIVE_CMD"
        return $?
    fi
    systemctl is-active --quiet "$service" 2>/dev/null
}

php_fpm_intl_wait_panel_ready() {
    local endpoint deadline="$((SECONDS + PHP_FPM_INTL_READY_MAX_WAIT))"
    if [[ -n "${PHP_FPM_INTL_VERIFY_SOCKET_PATH:-}" ]]; then
        endpoint="$PHP_FPM_INTL_VERIFY_SOCKET_PATH"
    else
        endpoint="$(php_fpm_intl_panel_pool_listen "$PHP_FPM_POOL_CONF")"
    fi

    while (( SECONDS < deadline )); do
        if php_fpm_intl_service_active "$PHP_FPM_SERVICE" \
            && php_fpm_intl_pool_endpoint_ready "$endpoint"
        then
            return 0
        fi
        sleep "$PHP_FPM_INTL_READY_INTERVAL"
    done
    return 1
}

php_fpm_intl_wait_fpm_binary_ready() {
    local deadline="$((SECONDS + PHP_FPM_INTL_READY_MAX_WAIT))"
    while (( SECONDS < deadline )); do
        if php_fpm_intl_fpm_binary_path >/dev/null 2>&1; then
            return 0
        fi
        sleep "$PHP_FPM_INTL_READY_INTERVAL"
    done
    return 1
}

php_fpm_intl_fpm_sapi_module_list() {
    if [[ -n "${PHP_FPM_INTL_VERIFY_MODULE_CMD:-}" ]]; then
        # shellcheck disable=SC2086
        local mod_rc=0
        eval "$PHP_FPM_INTL_VERIFY_MODULE_CMD" || mod_rc=$?
        return "$mod_rc"
    fi

    # Use the PHP-FPM SAPI binary module list (php-fpmVER -m). Do not use php -m or
    # php -c /etc/php/VER/fpm/php.ini -m: on Debian/Ubuntu those invocations still
    # load the CLI SAPI and scan cli/conf.d, so FPM-only intl state is invisible.
    local fpm_bin=""
    if ! fpm_bin="$(php_fpm_intl_fpm_binary_path)"; then
        return 1
    fi
    "$fpm_bin" -m 2>/dev/null
}

php_fpm_intl_fpm_sapi_has_intl() {
    php_fpm_intl_fpm_sapi_module_list | grep -qx intl
}
