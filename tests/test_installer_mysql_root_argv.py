#!/usr/bin/env python3
"""Issue #145 — installer root mysql never passes password on argv."""

from __future__ import annotations

import os
import re
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
INSTALLER = ROOT / "delta-transit-install.sh"
VERIFY_SCRIPT = ROOT / "scripts" / "verify-install-regression.sh"

_FORBIDDEN_ROOT_ARGV = (
    re.compile(r'mysql\s+-u\s+root[^\n]*-p"\$\{MYSQL_ROOT_PASSWORD\}"'),
    re.compile(r"MYSQL_ROOT_PASSWORD:\+-p"),
    re.compile(r'-p"\$\{MYSQL_ROOT_PASSWORD\}"'),
    re.compile(r"-p\$\{MYSQL_ROOT_PASSWORD\}"),
)


def _read_installer() -> str:
    return INSTALLER.read_text(encoding="utf-8")


def _named_function_body(text: str, name: str) -> str:
    pattern = rf"(?m)^{re.escape(name)}\(\) \{{\n(?P<body>.*?)\n\}}"
    found = re.search(pattern, text, re.S)
    if not found:
        raise AssertionError(f"function {name} not found")
    return found.group("body")


class InstallerMysqlRootArgvTests(unittest.TestCase):
    def test_installer_has_no_root_password_on_argv_patterns(self) -> None:
        text = _read_installer()
        for pattern in _FORBIDDEN_ROOT_ARGV:
            self.assertIsNone(
                pattern.search(text),
                f"forbidden root mysql argv pattern: {pattern.pattern}",
            )

    def test_mysql_root_client_uses_option_file_not_p_flag(self) -> None:
        body = _named_function_body(_read_installer(), "mysql_root_client")
        self.assertIn("mysql_client_with_defaults_file", body)
        self.assertIn('rm -f "$cnf"', body)
        self.assertIn("mysql_write_client_option_file", body)
        self.assertNotIn("-p", body)

    def test_mysql_client_with_defaults_file_uses_defaults_file_only(self) -> None:
        body = _named_function_body(_read_installer(), "mysql_client_with_defaults_file")
        self.assertIn("--defaults-file=", body)
        self.assertNotIn("--no-defaults", body)
        self.assertNotIn("--defaults-extra-file=", body)

    def test_installer_and_verify_no_no_defaults_with_defaults_file(self) -> None:
        installer = _read_installer()
        verify = VERIFY_SCRIPT.read_text(encoding="utf-8")
        self.assertNotIn("--no-defaults", installer)
        self.assertNotIn("--no-defaults", verify)
        self.assertIn("mysql_client_with_defaults_file", installer)

    def test_mysql_root_client_socket_only_when_password_empty(self) -> None:
        body = _named_function_body(_read_installer(), "mysql_root_client")
        self.assertIn('[[ -z "${MYSQL_ROOT_PASSWORD}" ]]', body)
        self.assertRegex(
            body,
            r'\[\[ -z "\$\{MYSQL_ROOT_PASSWORD\}" \]\] && mysql_root_can_use_socket',
        )

    def test_mysql_root_client_with_password_uses_defaults_file_not_socket(
        self,
    ) -> None:
        """Non-empty MYSQL_ROOT_PASSWORD must not use passwordless mysql -u root."""
        with tempfile.TemporaryDirectory() as tmp:
            tmp_path = Path(tmp)
            argv_log = tmp_path / "argv.log"
            shim = tmp_path / "mysql"
            shim.write_text(
                "#!/usr/bin/env bash\n"
                f'printf "%s\\n" "$@" >> "{argv_log}"\n'
                "exit 0\n",
                encoding="utf-8",
            )
            shim.chmod(0o755)
            env = {**os.environ, "PATH": f"{tmp}:{os.environ.get('PATH', '')}"}
            script = f"""
set -euo pipefail
source "{INSTALLER}"
MYSQL_ROOT_PASSWORD="$1"
mysql_root_can_use_socket() {{ return 0; }}
mysql_root_client -e "SELECT 1"
"""
            subprocess.run(
                ["bash", "-c", script, "bash", "throwaway-secret"],
                env=env,
                check=True,
                capture_output=True,
                text=True,
            )
            joined = argv_log.read_text(encoding="utf-8")
            self.assertIn("--defaults-file=", joined)
            lines = joined.splitlines()
            self.assertNotIn("-u", lines)
            self.assertNotIn("root", lines)

    def test_mysql_root_client_empty_password_may_use_socket(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            tmp_path = Path(tmp)
            argv_log = tmp_path / "argv.log"
            shim = tmp_path / "mysql"
            shim.write_text(
                "#!/usr/bin/env bash\n"
                f'printf "%s\\n" "$@" >> "{argv_log}"\n'
                "exit 0\n",
                encoding="utf-8",
            )
            shim.chmod(0o755)
            env = {**os.environ, "PATH": f"{tmp}:{os.environ.get('PATH', '')}"}
            script = f"""
set -euo pipefail
source "{INSTALLER}"
MYSQL_ROOT_PASSWORD=""
mysql_root_can_use_socket() {{ return 0; }}
mysql_root_client -e "SELECT 1"
"""
            subprocess.run(
                ["bash", "-c", script, "bash"],
                env=env,
                check=True,
                capture_output=True,
                text=True,
            )
            joined = argv_log.read_text(encoding="utf-8")
            self.assertNotIn("--defaults-file=", joined)
            lines = joined.splitlines()
            self.assertIn("-u", lines)
            self.assertIn("root", lines)

    def test_mysql_root_exec_delegates_to_mysql_root_client(self) -> None:
        body = _named_function_body(_read_installer(), "mysql_root_exec")
        self.assertIn("mysql_root_client", body)
        self.assertNotIn("-p", body)

    def test_ask_mysql_root_password_validates_via_mysql_root_client(self) -> None:
        body = _named_function_body(_read_installer(), "ask_mysql_root_password")
        self.assertIn("mysql_root_client", body)
        self.assertIn("if ! mysql_root_client", body)
        self.assertIn("fatal", body)
        self.assertNotIn("-p", body)

    def test_migration_helpers_abort_on_mysql_failure(self) -> None:
        text = _read_installer()
        for func in (
            "ensure_panel_login_attempts_table",
            "ensure_panel_admins_must_change_password_column",
            "import_schema",
        ):
            body = _named_function_body(text, func)
            self.assertIn("if ! mysql_root_client", body)
            self.assertIn("fatal", body)

    def test_cnf_writer_preserves_dollar_sign_password(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            cnf = Path(tmp) / "client.cnf"
            password = 'pa$HOMEword!"#\\'
            script = f"""
set -euo pipefail
source "{INSTALLER}"
cnf="{cnf}"
mysql_write_client_option_file "$cnf" root "$1"
"""
            subprocess.run(
                ["bash", "-c", script, "bash", password],
                check=True,
                capture_output=True,
                text=True,
            )
            text = cnf.read_text(encoding="utf-8")
            self.assertIn("pa$HOMEword!", text)
            self.assertIn("password=", text)

    def test_mysql_root_client_removes_cnf_after_call(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            tmp_path = Path(tmp)
            argv_log = tmp_path / "argv.log"
            cnf_seen = tmp_path / "cnf_seen"
            shim = tmp_path / "mysql"
            shim.write_text(
                "#!/usr/bin/env bash\n"
                "for a in \"$@\"; do\n"
                "  case \"$a\" in --defaults-file=*) echo \"${a#--defaults-file=}\" > "
                f"\"{cnf_seen}\";; esac\n"
                "done\n"
                f'printf "%s\\n" "$@" >> "{argv_log}"\n'
                "exit 0\n",
                encoding="utf-8",
            )
            shim.chmod(0o755)
            env = {**os.environ, "PATH": f"{tmp}:{os.environ.get('PATH', '')}"}
            script = f"""
set -euo pipefail
source "{INSTALLER}"
MYSQL_ROOT_PASSWORD="$1"
mysql_root_can_use_socket() {{ return 1; }}
mysql_root_client -e "SELECT 1"
test ! -f "$(cat {cnf_seen})"
"""
            subprocess.run(
                ["bash", "-c", script, "bash", "secret"],
                env=env,
                check=True,
                capture_output=True,
                text=True,
            )

    def test_mysql_root_client_argv_shim(self) -> None:
        """Fake mysql in PATH: root client must not receive -p on argv."""
        with tempfile.TemporaryDirectory() as tmp:
            tmp_path = Path(tmp)
            argv_log = tmp_path / "argv.log"
            shim = tmp_path / "mysql"
            shim.write_text(
                "#!/usr/bin/env bash\n"
                f'printf "%s\\n" "$@" >> "{argv_log}"\n'
                "exit 0\n",
                encoding="utf-8",
            )
            shim.chmod(0o755)
            env = {**os.environ, "PATH": f"{tmp}:{os.environ.get('PATH', '')}"}
            script = f"""
set -euo pipefail
source "{INSTALLER}"
MYSQL_ROOT_PASSWORD="$1"
mysql_root_can_use_socket() {{ return 1; }}
mysql_root_client -e "SELECT 1"
"""
            subprocess.run(
                ["bash", "-c", script, "bash", "secr$et\"pw"],
                env=env,
                check=True,
                capture_output=True,
                text=True,
            )
            lines = argv_log.read_text(encoding="utf-8").splitlines()
            self.assertTrue(lines, "shim mysql was not invoked")
            joined = " ".join(lines)
            self.assertNotIn("-p", joined)
            self.assertIn("--defaults-file=", joined)
            self.assertNotIn("--no-defaults", joined)

    def test_verify_regression_mysql_uses_defaults_file_only(self) -> None:
        text = VERIFY_SCRIPT.read_text(encoding="utf-8")
        self.assertEqual(text.count('f"--defaults-file={cnf_path}"'), 3)
        self.assertNotIn("--no-defaults", text)
        self.assertNotIn("--defaults-extra-file=", text)

    def test_mysql_client_with_defaults_file_ignores_home_my_cnf(self) -> None:
        """--defaults-file alone must not merge with $HOME/.my.cnf (issue #145)."""
        with tempfile.TemporaryDirectory() as tmp:
            tmp_path = Path(tmp)
            home = tmp_path / "home"
            home.mkdir()
            (home / ".my.cnf").write_text(
                "[client]\nuser=wrong\npassword=wrong\n",
                encoding="utf-8",
            )
            argv_log = tmp_path / "argv.log"
            shim = tmp_path / "mysql"
            shim.write_text(
                "#!/usr/bin/env bash\n"
                f'printf "%s\\n" "$@" >> "{argv_log}"\n'
                "exit 0\n",
                encoding="utf-8",
            )
            shim.chmod(0o755)
            env = {
                **os.environ,
                "HOME": str(home),
                "PATH": f"{tmp_path}:{os.environ.get('PATH', '')}",
            }
            script = f"""
set -euo pipefail
source "{INSTALLER}"
cnf="$(mktemp)"
chmod 600 "$cnf"
mysql_write_client_option_file "$cnf" root "good-secret"
mysql_client_with_defaults_file "$cnf" -e "SELECT 1"
rm -f "$cnf"
"""
            subprocess.run(
                ["bash", "-c", script],
                env=env,
                cwd=ROOT,
                check=True,
                capture_output=True,
                text=True,
            )
            joined = argv_log.read_text(encoding="utf-8")
            self.assertIn("--defaults-file=", joined)
            self.assertNotIn("-p", joined)
            self.assertNotIn("wrong", joined)

    def test_import_schema_runs_mysql_root_client_without_p(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            tmp_path = Path(tmp)
            argv_log = tmp_path / "argv.log"
            shim = tmp_path / "mysql"
            shim.write_text(
                "#!/usr/bin/env bash\n"
                f'printf "%s\\n" "$@" >> "{argv_log}"\n'
                "exit 0\n",
                encoding="utf-8",
            )
            shim.chmod(0o755)
            env = {
                **os.environ,
                "PATH": f"{tmp_path}:{os.environ.get('PATH', '')}",
            }
            script = f"""
set -euo pipefail
cd "{ROOT}"
source "./delta-transit-install.sh"
DB_NAME="mail_proxy_test_import"
MYSQL_ROOT_PASSWORD="import-test-secret"
schema_already_installed() {{ return 1; }}
mysql_root_can_use_socket() {{ return 1; }}
import_schema
"""
            subprocess.run(
                ["bash", "-c", script],
                env=env,
                check=True,
                capture_output=True,
                text=True,
            )
            joined = argv_log.read_text(encoding="utf-8")
            self.assertIn("--defaults-file=", joined)
            self.assertNotIn("-p", joined)

    def test_install_traps_cleans_up_on_int_and_term(self) -> None:
        body = _named_function_body(_read_installer(), "install_traps")
        self.assertIn("trap cleanup EXIT", body)
        self.assertIn("trap 'cleanup; exit 130' INT", body)
        self.assertIn("trap 'cleanup; exit 143' TERM", body)

    def test_cleanup_is_idempotent_and_removes_registered_temps(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            tmp_path = Path(tmp)
            f1 = tmp_path / "one"
            f2 = tmp_path / "two"
            f1.write_text("x", encoding="utf-8")
            f2.write_text("y", encoding="utf-8")
            script = f"""
set -euo pipefail
source "{INSTALLER}"
TMP_FILES=()
_CLEANUP_DONE=0
register_tmpfile "{f1}"
register_tmpfile "{f2}"
cleanup
cleanup
test ! -f "{f1}"
test ! -f "{f2}"
"""
            subprocess.run(
                ["bash", "-c", script],
                check=True,
                capture_output=True,
                text=True,
            )

    def test_install_traps_int_and_term_exit_codes(self) -> None:
        for sig, code in (("INT", 130), ("TERM", 143)):
            with self.subTest(signal=sig, code=code):
                with tempfile.TemporaryDirectory() as tmp:
                    tmp_path = Path(tmp)
                    marker = tmp_path / "tmpfile"
                    marker.write_text("keep", encoding="utf-8")
                    script = f"""
set -euo pipefail
source "{INSTALLER}"
TMP_FILES=()
_CLEANUP_DONE=0
register_tmpfile "{marker}"
install_traps
kill -s {sig} $$
"""
                    proc = subprocess.run(
                        ["bash", "-c", script],
                        check=False,
                        capture_output=True,
                        text=True,
                    )
                    self.assertEqual(proc.returncode, code)
                    self.assertFalse(marker.exists())

    def test_cnf_writer_always_double_quotes_values(self) -> None:
        for password in ("plain", "sp ace", 'quote"double'):
            with self.subTest(password=password):
                with tempfile.TemporaryDirectory() as tmp:
                    cnf = Path(tmp) / "client.cnf"
                    script = f"""
set -euo pipefail
source "{INSTALLER}"
mysql_write_client_option_file "{cnf}" "roundtrip_user" "$1"
"""
                    subprocess.run(
                        ["bash", "-c", script, "bash", password],
                        check=True,
                        capture_output=True,
                        text=True,
                    )
                    text = cnf.read_text(encoding="utf-8")
                    self.assertIn('user="roundtrip_user"', text)
                    self.assertRegex(text, r'(?m)^password=".*"$')

    def test_verify_regression_cnf_writer_always_double_quotes(self) -> None:
        text = VERIFY_SCRIPT.read_text(encoding="utf-8")
        blocks = re.findall(
            r"def _cnf_value\(value: str\) -> str:\n(.*?)\n\n",
            text,
            re.S,
        )
        self.assertEqual(len(blocks), 2)
        for block in blocks:
            self.assertIn('return f\'"{escaped}"\'', block)
            self.assertNotIn("if re.search", block)

    def test_migrate_panel_auth_installs_traps_before_mysql_helpers(self) -> None:
        body = _named_function_body(_read_installer(), "migrate_panel_auth")
        self.assertIn("install_traps", body)
        self.assertLess(body.find("install_traps"), body.find("ensure_panel_admins_table"))

    def test_mysql_root_client_return_trap_for_temp_cnf(self) -> None:
        body = _named_function_body(_read_installer(), "mysql_root_client")
        self.assertIn("trap - RETURN", body)
        self.assertRegex(body, r'trap "rm -f \$\(printf \'%q\' "\$cnf"\)" RETURN')

    def test_mysql_mail_proxy_client_return_trap_for_temp_cnf(self) -> None:
        body = _named_function_body(_read_installer(), "mysql_mail_proxy_client")
        self.assertIn("trap - RETURN", body)
        self.assertRegex(body, r'trap "rm -f \$\(printf \'%q\' "\$cnf"\)" RETURN')

    def test_installer_and_verify_never_mutate_root_my_cnf(self) -> None:
        """Issue #145 anomaly: operator /root/.my.cnf must never be touched by repo scripts."""
        mutate_line = re.compile(
            r'(?i)(?:\brm\s+|\bmv\s+|\bcp\s+|\btruncate\b|\btouch\s+|\brename\b|>>|>)'
            r"[^#\n]*\.my\.cnf"
        )
        for path in (INSTALLER, VERIFY_SCRIPT):
            text = path.read_text(encoding="utf-8")
            for line_no, line in enumerate(text.splitlines(), start=1):
                if ".my.cnf" not in line:
                    continue
                if mutate_line.search(line):
                    self.fail(f"{path.name}:{line_no} may mutate .my.cnf: {line.strip()}")
                if "/root/.my.cnf" in line and re.search(
                    r'(?i)(?:\brm\s+|\bmv\s+|\bcp\s+|\btruncate\b|\btouch\s+|\brename\b|>>|>)',
                    line,
                ):
                    self.fail(
                        f"{path.name}:{line_no} may mutate /root/.my.cnf: {line.strip()}"
                    )


if __name__ == "__main__":
    unittest.main()
