#!/usr/bin/env python3
"""Issue #173 — php -m | grep -q* false negatives under pipefail (capture-then-grep)."""

from __future__ import annotations

import os
import re
import subprocess
import tempfile
import textwrap
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
INSTALLER = ROOT / "delta-transit-install.sh"
VERIFY_SCRIPT = ROOT / "scripts" / "verify-install-regression.sh"
INTL_LIB = ROOT / "scripts" / "php_fpm_intl_verify.sh"

_SCAN_PATHS = (INSTALLER, INTL_LIB, VERIFY_SCRIPT)

# Forbid live grep-on-pipe after php module-list producers (M5 guard must stay defined).
_FORBIDDEN_PIPE_AFTER_MODULE_LIST = re.compile(
    r"(?:"
    r"php\s+-m"
    r"|php-fpm[0-9./]*\s+-m"
    r'|"\$fpm_bin"\s+-m'
    r"|php_fpm_intl_fpm_sapi_module_list"
    r")"
    r"(?:[^\n|]|\\\n[^\n|])*"
    r"\|\s*grep\s+-q",
    re.MULTILINE,
)


def _decontinued(text: str) -> str:
    return re.sub(r"\\\s*\n\s*", " ", text)


def _slow_php_stub_body(
    *,
    with_intl: bool = True,
    intl_substring_only: bool = False,
) -> str:
    """Bash stub: slow php -m list; exits 255 on failed write (EPIPE)."""
    if intl_substring_only:
        mid = '  "mbstring" "intl_foo" "openssl"\n'
    elif with_intl:
        mid = '  "mbstring" "intl" "openssl"\n'
    else:
        mid = '  "mbstring" "openssl"\n'
    return textwrap.dedent(
        f"""\
        #!/usr/bin/env bash
        trap '' PIPE
        if [[ "${{1:-}}" != "-m" ]]; then exit 1; fi
        mods=(
          "Core" "date" "dom" "fileinfo" "filter" "hash" "json" "libxml"
        {mid}  "pcre" "PDO" "pdo_mysql" "session" "mysqli" "pcntl" "posix"
          "random" "readline" "Reflection" "shmop" "SimpleXML" "soap"
          "sockets" "sodium" "SPL" "standard" "sysvmsg" "sysvsem" "sysvshm"
          "tokenizer" "xml" "xmlreader" "xmlwriter" "zip" "zlib" "curl"
          "gettext" "igbinary" "imagick" "ldap"
        )
        for mod in "${{mods[@]}}"; do
          if ! printf '%s\\n' "$mod"; then
            exit 255
          fi
          sleep 0.005
        done
        exit 0
        """
    )


def _write_slow_stub(
    path: Path,
    *,
    no_intl: bool = False,
    intl_substring: bool = False,
) -> None:
    body = _slow_php_stub_body(
        with_intl=not no_intl and not intl_substring,
        intl_substring_only=intl_substring,
    )
    path.write_text(body, encoding="utf-8")
    path.chmod(0o755)


def _bash_cli_check_new() -> str:
    return textwrap.dedent(
        f"""\
        fatal() {{ echo "CLI_INTL_MISSING"; exit 1; }}
        validate_php_intl_cli_only() {{
        {_installer_cli_intl_body()}
        }}
        validate_php_intl_cli_only
        echo "CLI_INTL_OK"
        """
    )


def _bash_cli_check_old_pipeline() -> str:
    return textwrap.dedent(
        """\
        if ! command -v php >/dev/null 2>&1; then
          echo "PHP CLI missing"
          exit 2
        fi
        if ! php -m 2>/dev/null | grep -qx intl; then
          echo "CLI_INTL_MISSING"
          exit 1
        fi
        echo "CLI_INTL_OK"
        """
    )


def _bash_fpm_check_new() -> str:
    return textwrap.dedent(
        f"""\
        set -Eeuo pipefail
        source "{INTL_LIB}"
        export PHP_FPM_INTL_VERIFY_MODULE_CMD='php -m'
        if php_fpm_intl_fpm_sapi_has_intl; then
          echo "FPM_INTL_OK"
        else
          echo "FPM_INTL_MISSING"
          exit 1
        fi
        """
    )


def _bash_fpm_check_old_pipeline() -> str:
    return textwrap.dedent(
        f"""\
        set -Eeuo pipefail
        source "{INTL_LIB}"
        export PHP_FPM_INTL_VERIFY_MODULE_CMD='php -m'
        if php_fpm_intl_fpm_sapi_module_list | grep -qx intl; then
          echo "FPM_INTL_OK"
        else
          echo "FPM_INTL_MISSING"
          exit 1
        fi
        """
    )


def _installer_cli_intl_body() -> str:
    text = INSTALLER.read_text(encoding="utf-8")
    block = re.search(
        r"validate_php_intl_extensions\(\) \{\n(?P<body>.*?)\n\}",
        text,
        re.S,
    )
    if not block:
        raise AssertionError("validate_php_intl_extensions not found")
    body = block.group("body")
    cli: list[str] = []
    capture = False
    for line in body.splitlines():
        if line.strip().startswith("if ! command -v php"):
            capture = True
        if capture:
            cli.append(line)
        if capture and "Normalizer required for panel login throttle" in line:
            cli.append("    fi")
            break
    return "\n".join(cli)


def _run_validate_cli_only(
    tmp: Path,
    *,
    no_intl: bool = False,
    php_missing: bool = False,
) -> subprocess.CompletedProcess[str]:
    bindir = tmp / "bin"
    bindir.mkdir()
    php = bindir / "php"
    system_path = "/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"
    if php_missing:
        env_path = f"{tmp / 'empty_bin'}:{system_path}"
        (tmp / "empty_bin").mkdir(exist_ok=True)
    else:
        _write_slow_stub(php, no_intl=no_intl)
        env_path = f"{bindir}:{system_path}"

    cli_body = _installer_cli_intl_body()
    script = textwrap.dedent(
        f"""\
        set -Eeuo pipefail
        source "{INSTALLER}"
        fatal() {{ echo "FATAL:$*"; exit 99; }}
        validate_php_intl_cli_only() {{
        {cli_body}
        }}
        validate_php_intl_cli_only
        echo "VALIDATE_OK"
        """
    )
    env = {**os.environ, "PATH": env_path}
    return subprocess.run(
        ["bash", "-c", script],
        env=env,
        capture_output=True,
        text=True,
        check=False,
    )


class InstallerIntlPipefailStaticTests(unittest.TestCase):
    def test_forbidden_pipe_guard_pattern_required(self) -> None:
        """M5: removing _FORBIDDEN_PIPE_AFTER_MODULE_LIST must fail tests."""
        source = Path(__file__).read_text(encoding="utf-8")
        self.assertRegex(
            source,
            r"_FORBIDDEN_PIPE_AFTER_MODULE_LIST\s*=\s*re\.compile\(",
            "forbidden pipeline guard must stay defined",
        )
        self.assertIn("_FORBIDDEN_PIPE_AFTER_MODULE_LIST.search", source)

    def test_no_live_grep_pipe_after_module_list_commands(self) -> None:
        guard = _FORBIDDEN_PIPE_AFTER_MODULE_LIST
        for path in _SCAN_PATHS:
            text = _decontinued(path.read_text(encoding="utf-8"))
            m = guard.search(text)
            self.assertIsNone(
                m,
                f"{path}: forbidden php -m | grep -q pipeline at: {m.group(0)!r}"
                if m
                else f"{path}: unexpected pipeline",
            )

    def test_verify_regression_uses_capture_then_grep(self) -> None:
        text = VERIFY_SCRIPT.read_text(encoding="utf-8")
        self.assertIn('mods="$(php -m 2>/dev/null || true)"', text)
        self.assertIn('grep -qx intl <<<"$mods"', text)


class InstallerIntlPipefailExecutedTests(unittest.TestCase):
    ITERATIONS = 20

    def test_slow_stub_new_cli_check_passes_repeatedly(self) -> None:
        for _ in range(self.ITERATIONS):
            with tempfile.TemporaryDirectory() as td:
                tmp = Path(td)
                bindir = tmp / "bin"
                bindir.mkdir()
                _write_slow_stub(bindir / "php")
                env = {**os.environ, "PATH": f"{bindir}:{os.environ.get('PATH', '')}"}
                proc = subprocess.run(
                    ["bash", "-c", f"set -Eeuo pipefail\n{_bash_cli_check_new()}"],
                    env=env,
                    capture_output=True,
                    text=True,
                    check=False,
                )
                self.assertEqual(
                    proc.returncode,
                    0,
                    proc.stderr or proc.stdout,
                )
                self.assertIn("CLI_INTL_OK", proc.stdout)

    def test_slow_stub_old_pipeline_false_negative(self) -> None:
        for _ in range(self.ITERATIONS):
            with tempfile.TemporaryDirectory() as td:
                tmp = Path(td)
                bindir = tmp / "bin"
                bindir.mkdir()
                _write_slow_stub(bindir / "php")
                env = {**os.environ, "PATH": f"{bindir}:{os.environ.get('PATH', '')}"}
                proc = subprocess.run(
                    ["bash", "-c", f"set -Eeuo pipefail\n{_bash_cli_check_old_pipeline()}"],
                    env=env,
                    capture_output=True,
                    text=True,
                    check=False,
                )
                self.assertNotEqual(
                    proc.returncode,
                    0,
                    "old pipeline must reproduce false negative under pipefail",
                )

    def test_validate_php_intl_extensions_cli_slow_stub(self) -> None:
        for _ in range(self.ITERATIONS):
            with tempfile.TemporaryDirectory() as td:
                proc = _run_validate_cli_only(Path(td))
                self.assertEqual(proc.returncode, 0, proc.stderr or proc.stdout)
                self.assertIn("VALIDATE_OK", proc.stdout)

    def test_cli_check_fails_without_intl(self) -> None:
        with tempfile.TemporaryDirectory() as td:
            tmp = Path(td)
            bindir = tmp / "bin"
            bindir.mkdir()
            _write_slow_stub(bindir / "php", no_intl=True)
            env = {**os.environ, "PATH": f"{bindir}:{os.environ.get('PATH', '')}"}
            proc = subprocess.run(
                ["bash", "-c", f"set -Eeuo pipefail\n{_bash_cli_check_new()}"],
                env=env,
                capture_output=True,
                text=True,
                check=False,
            )
            self.assertEqual(proc.returncode, 1)

    def test_validate_fails_without_intl(self) -> None:
        with tempfile.TemporaryDirectory() as td:
            proc = _run_validate_cli_only(Path(td), no_intl=True)
            self.assertEqual(proc.returncode, 99)
            self.assertIn("FATAL:PHP CLI intl extension missing", proc.stdout)

    def test_php_missing_path(self) -> None:
        with tempfile.TemporaryDirectory() as td:
            proc = _run_validate_cli_only(Path(td), php_missing=True)
            self.assertEqual(proc.returncode, 99)
            self.assertIn("FATAL:PHP CLI missing", proc.stdout)

    def test_capture_or_true_survives_php_exit_255(self) -> None:
        with tempfile.TemporaryDirectory() as td:
            tmp = Path(td)
            bindir = tmp / "bin"
            bindir.mkdir()
            php = bindir / "php"
            _write_slow_stub(php)
            text = php.read_text(encoding="utf-8")
            php.write_text(text.replace("exit 0", "exit 255"), encoding="utf-8")
            system_path = "/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"
            env = {**os.environ, "PATH": f"{bindir}:{system_path}"}
            proc = subprocess.run(
                ["bash", "-c", f"set -Eeuo pipefail\n{_bash_cli_check_new()}"],
                env=env,
                capture_output=True,
                text=True,
                check=False,
            )
            self.assertEqual(
                proc.returncode,
                0,
                "|| true on php -m capture must ignore producer exit 255 when intl present",
            )

    def test_fpm_has_intl_slow_stub_passes(self) -> None:
        for _ in range(self.ITERATIONS):
            with tempfile.TemporaryDirectory() as td:
                tmp = Path(td)
                bindir = tmp / "bin"
                bindir.mkdir()
                _write_slow_stub(bindir / "php")
                env = {**os.environ, "PATH": f"{bindir}:{os.environ.get('PATH', '')}"}
                proc = subprocess.run(
                    ["bash", "-c", _bash_fpm_check_new()],
                    env=env,
                    capture_output=True,
                    text=True,
                    check=False,
                )
                self.assertEqual(proc.returncode, 0, proc.stderr or proc.stdout)

    def test_fpm_old_pipeline_false_negative(self) -> None:
        for _ in range(self.ITERATIONS):
            with tempfile.TemporaryDirectory() as td:
                tmp = Path(td)
                bindir = tmp / "bin"
                bindir.mkdir()
                _write_slow_stub(bindir / "php")
                env = {**os.environ, "PATH": f"{bindir}:{os.environ.get('PATH', '')}"}
                proc = subprocess.run(
                    ["bash", "-c", _bash_fpm_check_old_pipeline()],
                    env=env,
                    capture_output=True,
                    text=True,
                    check=False,
                )
                self.assertNotEqual(proc.returncode, 0)

    def test_fpm_fails_without_intl(self) -> None:
        with tempfile.TemporaryDirectory() as td:
            tmp = Path(td)
            bindir = tmp / "bin"
            bindir.mkdir()
            _write_slow_stub(bindir / "php", no_intl=True)
            env = {**os.environ, "PATH": f"{bindir}:{os.environ.get('PATH', '')}"}
            proc = subprocess.run(
                ["bash", "-c", _bash_fpm_check_new()],
                env=env,
                capture_output=True,
                text=True,
                check=False,
            )
            self.assertNotEqual(proc.returncode, 0)

    def test_grep_qx_not_q_for_intl_substring_module(self) -> None:
        """grep -q intl would match intl_foo; grep -qx requires exact line intl."""
        with tempfile.TemporaryDirectory() as td:
            tmp = Path(td)
            bindir = tmp / "bin"
            bindir.mkdir()
            _write_slow_stub(bindir / "php", no_intl=True, intl_substring=True)
            env = {**os.environ, "PATH": f"{bindir}:{os.environ.get('PATH', '')}"}
            proc = subprocess.run(
                ["bash", "-c", f"set -Eeuo pipefail\n{_bash_cli_check_new()}"],
                env=env,
                capture_output=True,
                text=True,
                check=False,
            )
            self.assertEqual(proc.returncode, 1, "intl_foo must not satisfy grep -qx intl")


if __name__ == "__main__":
    unittest.main()