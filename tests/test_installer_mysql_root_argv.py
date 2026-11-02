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
        self.assertEqual(text.count('f"--defaults-file={cnf_path}"'), 2)
        self.assertNotIn("--no-defaults", text)
        self.assertNotIn("--defaults-extra-file=", text)


if __name__ == "__main__":
    unittest.main()
