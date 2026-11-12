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
    unset PHP_FPM_INTL_VERIFY_FPM_BINARY
    unset PHP_FPM_INTL_VERIFY_MODULE_CMD
    unset PHP_FPM_INTL_VERIFY_RESOLVE_FPM_BINARY_CMD
}

_with_stub_fpm_binary() {
    export PHP_FPM_INTL_VERIFY_FPM_BINARY="/stub/php-fpm8.3"
}

scenario_intl_present_pass() {
    _common_env
    _with_stub_fpm_binary
    export PHP_FPM_INTL_VERIFY_MODULE_CMD='printf "%s\n" intl mbstring'
    assert_ok "intl present in FPM module list" php_fpm_intl_fpm_sapi_has_intl
}

scenario_intl_absent_fail_despite_cli_stubs() {
    _common_env
    _with_stub_fpm_binary
    export PHP_FPM_INTL_VERIFY_MODULE_CMD='printf "%s\n" mbstring'
    assert_not_ok "intl absent in FPM module list (CLI would lie)" php_fpm_intl_fpm_sapi_has_intl
}

scenario_not_ready_then_ready() {
    _common_env
    PHP_FPM_INTL_HARNESS_RESOLVE_CALLS=0
    export PHP_FPM_INTL_HARNESS_RESOLVE_CALLS
    export PHP_FPM_INTL_VERIFY_RESOLVE_FPM_BINARY_CMD='
        PHP_FPM_INTL_HARNESS_RESOLVE_CALLS=$((PHP_FPM_INTL_HARNESS_RESOLVE_CALLS + 1))
        if [[ "$PHP_FPM_INTL_HARNESS_RESOLVE_CALLS" -ge 2 ]]; then
            PHP_FPM_INTL_VERIFY_RESOLVE_FPM_BINARY_PATH="/stub/php-fpm8.3"
            return 0
        fi
        return 1
    '
    export PHP_FPM_INTL_VERIFY_MODULE_CMD='printf "%s\n" intl'
    assert_ok "FPM binary becomes detectable within window" php_fpm_intl_wait_fpm_binary_ready
}

scenario_never_ready() {
    _common_env
    export PHP_FPM_INTL_VERIFY_RESOLVE_FPM_BINARY_CMD='return 1'
    assert_not_ok "FPM binary never detectable" php_fpm_intl_wait_fpm_binary_ready
}

scenario_substring_trap() {
    _common_env
    _with_stub_fpm_binary
    export PHP_FPM_INTL_VERIFY_MODULE_CMD='printf "%s\n" intlx php-intl-foo mbstring'
    assert_not_ok "intl substring must not match" php_fpm_intl_fpm_sapi_has_intl
}

scenario_intl_present_pass
scenario_intl_absent_fail_despite_cli_stubs
scenario_not_ready_then_ready
scenario_never_ready
scenario_substring_trap

if [[ "$failures" -ne 0 ]]; then
    echo "Harness failures: $failures"
    exit 1
fi
echo "Harness: all scenarios passed"
