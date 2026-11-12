#!/usr/bin/env bash
# Behavioral harness for issue #151 FPM intl verification (no real php-fpm required).
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
LIB="${PHP_FPM_INTL_HARNESS_LIB:-${ROOT}/scripts/php_fpm_intl_verify.sh}"

# shellcheck source=/dev/null disable=SC1091
source "$LIB"

PHP_FPM_INTL_READY_MAX_WAIT=3
PHP_FPM_INTL_READY_INTERVAL=1

failures=0
assert_ok() {
    local label="$1"
    shift
    if "$@"; then
        echo "OK: $label"
    else
        echo "FAIL: $label"
        failures=$((failures + 1))
    fi
}
assert_not_ok() {
    local label="$1"
    shift
    if "$@"; then
        echo "FAIL (expected false): $label"
        failures=$((failures + 1))
    else
        echo "OK: $label"
    fi
}

_common_env() {
    export PHP_FPM_VERSION="8.3"
    export PHP_FPM_SERVICE="php8.3-fpm"
    export PHP_FPM_POOL_CONF="/etc/php/8.3/fpm/pool.d/www.conf"
    export PHP_FPM_INI="/etc/php/8.3/fpm/php.ini"
    export PHP_FPM_INTL_VERIFY_SOCKET_PATH="/run/php/php8.3-fpm.sock"
    export PHP_FPM_INTL_VERIFY_ENDPOINT_READY_CMD='[[ -n "$PHP_FPM_INTL_VERIFY_ENDPOINT" ]]'
}

scenario_intl_present_pass() {
    _common_env
    export PHP_FPM_INTL_VERIFY_IS_ACTIVE_CMD='[[ "$PHP_FPM_INTL_VERIFY_SERVICE_NAME" == "php8.3-fpm" ]]'
    export PHP_FPM_INTL_VERIFY_MODULE_CMD='printf "%s\n" intl mbstring'
    assert_ok "intl present in FPM module list" php_fpm_intl_fpm_sapi_has_intl
}

scenario_intl_absent_fail() {
    _common_env
    export PHP_FPM_INTL_VERIFY_MODULE_CMD='printf "%s\n" mbstring'
    assert_not_ok "intl absent in FPM module list" php_fpm_intl_fpm_sapi_has_intl
}

scenario_not_ready_then_ready() {
    _common_env
    export PHP_FPM_INTL_HARNESS_ACTIVE_CALLS=0
    export PHP_FPM_INTL_VERIFY_IS_ACTIVE_CMD='
        PHP_FPM_INTL_HARNESS_ACTIVE_CALLS=$((PHP_FPM_INTL_HARNESS_ACTIVE_CALLS + 1))
        [[ "$PHP_FPM_INTL_HARNESS_ACTIVE_CALLS" -ge 2 ]]
    '
    export PHP_FPM_INTL_VERIFY_MODULE_CMD='printf "%s\n" intl'
    assert_ok "FPM becomes ready within window" php_fpm_intl_wait_panel_ready
}

scenario_never_ready() {
    _common_env
    export PHP_FPM_INTL_VERIFY_IS_ACTIVE_CMD='false'
    assert_not_ok "FPM never ready" php_fpm_intl_wait_panel_ready
}

scenario_intl_present_pass
scenario_intl_absent_fail
scenario_not_ready_then_ready
scenario_never_ready

if [[ "$failures" -ne 0 ]]; then
    echo "Harness failures: $failures"
    exit 1
fi
echo "Harness: all scenarios passed"
