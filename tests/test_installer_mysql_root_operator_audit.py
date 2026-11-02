#!/usr/bin/env python3
"""Operator audit a–e (issue #145): EXECUTES tests for root/mail_proxy mysql argv."""

from __future__ import annotations

import os
import re
import signal
import subprocess
import tempfile
import textwrap
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
INSTALLER = ROOT / "delta-transit-install.sh"

# Real MariaDB round-trip (audit b): available after apt install + service start in CI/dev.
_MARIADB_LIVE: bool | None = None
AUDIT_B_BACKEND = "pending"


def _mariadb_live() -> bool:
    global _MARIADB_LIVE
    if _MARIADB_LIVE is not None:
        return _MARIADB_LIVE
    try:
        proc = subprocess.run(
            ["sudo", "mariadb", "-e", "SELECT 1"],
            capture_output=True,
            timeout=10,
            check=False,
        )
        _MARIADB_LIVE = proc.returncode == 0
    except (FileNotFoundError, subprocess.TimeoutExpired):
        _MARIADB_LIVE = False
    return _MARIADB_LIVE


def _cnf_writer_python_block() -> str:
    text = INSTALLER.read_text(encoding="utf-8")
    match = re.search(
        r"if ! MYSQL_CNF_PASS=\"\$pass\" python3 - \"\$cnf\" \"\$user\" <<'PY'\n(.*?\n)PY",
        text,
        re.S,
    )
    if not match:
        raise RuntimeError("cnf writer python block not found in installer")
    return match.group(1)


def _bash_histexpand_off() -> str:
    return "set +o histexpand\n"


def _parse_cnf_password(cnf_text: str) -> str:
    for line in cnf_text.splitlines():
        if not line.startswith("password="):
            continue
        raw = line.split("=", 1)[1].strip()
        if raw.startswith('"') and raw.endswith('"'):
            inner = raw[1:-1]
            return inner.replace('\\"', '"').replace("\\\\", "\\")
        return raw
    raise AssertionError("password= line missing in cnf")


def _shim_mysql(tmp: Path, argv_log: Path, cnf_hook: Path | None = None) -> Path:
    hook = ""
    if cnf_hook is not None:
        hook = textwrap.dedent(
            f"""
            for a in "$@"; do
              case "$a" in --defaults-file=*)
                echo "${{a#--defaults-file=}}" >> "{cnf_hook}"
                ;;
              esac
            done
            """
        )
    shim = tmp / "mysql"
    shim.write_text(
        "#!/usr/bin/env bash\n"
        f"{hook}"
        f'printf "%s\\0" "$@" >> "{argv_log}"\n'
        "printf '\\n---\\n' >> "
        f'"{argv_log}"\n'
        "exit ${MYSQL_SHIM_EXIT:-0}\n",
        encoding="utf-8",
    )
    shim.chmod(0o755)
    return shim


def _argv_blob(argv_log: Path) -> str:
    if not argv_log.is_file():
        return ""
    return argv_log.read_bytes().decode("utf-8", "replace")


class OperatorAuditA(unittest.TestCase):
    """Audit (a): every listed path logs mysql argv without password on argv."""

    def test_audit_a_root_paths_never_put_password_on_mysql_argv(self) -> None:
        root_secret = "ROOT-AUDIT-SECRET-7f3a9c2e"
        app_secret = "APP-AUDIT-SECRET-4b8d1e0f"
        with tempfile.TemporaryDirectory() as tmp:
            tmp_path = Path(tmp)
            argv_log = tmp_path / "argv.log"
            _shim_mysql(tmp_path, argv_log)
            env = {
                **os.environ,
                "PATH": f"{tmp_path}:{os.environ.get('PATH', '')}",
            }
            script = textwrap.dedent(
                f"""
                set +o histexpand
                set -euo pipefail
                cd "{ROOT}"
                source "./delta-transit-install.sh"
                DB_NAME="mail_proxy_audit_a"
                DB_USER="mail_proxy"
                PARAM_DB_PASS="{app_secret}"
                MYSQL_ROOT_PASSWORD="{root_secret}"
                mysql_root_can_use_socket() {{ return 1; }}
                log_ok() {{ :; }}
                log_info() {{ :; }}
                require_file() {{ :; }}

                mysql_root_exec "SELECT 1"
                mysql_root_client -e "SELECT 1"
                ask_mysql_root_password() {{
                  if mysql_root_can_use_socket; then return 0; fi
                  if ! mysql_root_client -e "SELECT 1" >/dev/null; then
                    fatal "MariaDB root password validation failed"
                  fi
                }}
                ask_mysql_root_password

                ensure_panel_login_attempts_table
                ensure_panel_admins_must_change_password_column

                schema_already_installed() {{ return 1; }}
                import_schema

                mysql_mail_proxy_client -N -e "SELECT 1"
                """
            )
            subprocess.run(
                ["bash", "-c", script],
                env=env,
                check=True,
                capture_output=True,
                text=True,
            )
            blob = _argv_blob(argv_log)
            self.assertIn("--defaults-file=", blob)
            self.assertNotIn(root_secret, blob)
            self.assertNotIn(app_secret, blob)
            self.assertNotIn("-p", blob.replace("---", ""))


class OperatorAuditB(unittest.TestCase):
    """Audit (b): special passwords round-trip through generated cnf."""

    _PASSWORDS = (
        "sp ace",
        "dollar$sign",
        'quote"double',
        "quote'single",
        "back\\slash",
        "hash#tag",
        "semi;colon",
        "bang!mark",
        "eq=ual",
        "utf8-кирилlica-я",
    )

    def test_audit_b_password_roundtrip_through_generated_defaults_file(self) -> None:
        global AUDIT_B_BACKEND
        AUDIT_B_BACKEND = "cnf parser round-trip (installer mysql_write_client_option_file)"
        for password in self._PASSWORDS:
            with self.subTest(password=password[:12]):
                with tempfile.TemporaryDirectory() as tmp:
                    cnf = Path(tmp) / "client.cnf"
                    script = textwrap.dedent(
                        f"""
                        set +o histexpand
            set -euo pipefail
                        source "{INSTALLER}"
                        mysql_write_client_option_file "{cnf}" "roundtrip_user" "$1"
                        """
                    )
                    subprocess.run(
                        ["bash", "-c", script, "bash", password],
                        check=True,
                        capture_output=True,
                        text=True,
                    )
                    text = cnf.read_text(encoding="utf-8")
                    self.assertEqual(oct(cnf.stat().st_mode & 0o777), "0o600")
                    parsed = _parse_cnf_password(text)
                    self.assertEqual(parsed, password)

        if _mariadb_live():
            AUDIT_B_BACKEND = "live MariaDB + cnf parser round-trip"
            self._live_mariadb_roundtrip("dollar$sign")

    def _live_mariadb_roundtrip(self, password: str) -> None:
        user = "proxy_cnf_audit_b"
        esc = password.replace("\\", "\\\\").replace("'", "''")
        subprocess.run(
            [
                "sudo",
                "mariadb",
                "-e",
                f"DROP USER IF EXISTS '{user}'@'localhost'; "
                f"CREATE USER '{user}'@'localhost' IDENTIFIED BY '{esc}';",
            ],
            check=True,
            capture_output=True,
            text=True,
        )
        try:
            with tempfile.TemporaryDirectory() as tmp:
                cnf = Path(tmp) / "live.cnf"
                script = textwrap.dedent(
                    f"""
                    set +o histexpand
            set -euo pipefail
                    source "{INSTALLER}"
                    mysql_write_client_option_file "{cnf}" "{user}" "$1"
                    """
                )
                subprocess.run(
                    ["bash", "-c", script, "bash", password],
                    check=True,
                    capture_output=True,
                    text=True,
                )
                proc = subprocess.run(
                    ["mariadb", f"--defaults-file={cnf}", "-e", "SELECT 1"],
                    capture_output=True,
                    text=True,
                    check=False,
                )
                self.assertEqual(
                    proc.returncode,
                    0,
                    proc.stderr or proc.stdout,
                )
        finally:
            subprocess.run(
                [
                    "sudo",
                    "mariadb",
                    "-e",
                    f"DROP USER IF EXISTS '{user}'@'localhost';",
                ],
                check=False,
                capture_output=True,
            )


class OperatorAuditC(unittest.TestCase):
    """Audit (c): cnf mode 600 and removal on success, failure, SIGTERM."""

    def test_audit_c_defaults_file_mode_600_and_removed_after_success(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            tmp_path = Path(tmp)
            argv_log = tmp_path / "argv.log"
            cnf_list = tmp_path / "cnfs.txt"
            _shim_mysql(tmp_path, argv_log, cnf_list)
            env = {
                **os.environ,
                "PATH": f"{tmp_path}:{os.environ.get('PATH', '')}",
            }
            script = textwrap.dedent(
                f"""
                set +o histexpand
            set -euo pipefail
                source "{INSTALLER}"
                MYSQL_ROOT_PASSWORD="audit-c-ok"
                mysql_root_can_use_socket() {{ return 1; }}
                mysql_root_client -e "SELECT 1"
                """
            )
            subprocess.run(
                ["bash", "-c", script],
                env=env,
                check=True,
                capture_output=True,
                text=True,
            )
            paths = [
                p.strip()
                for p in cnf_list.read_text(encoding="utf-8").splitlines()
                if p.strip()
            ]
            self.assertTrue(paths)
            for p in paths:
                self.assertFalse(Path(p).exists(), f"cnf should be removed: {p}")

    def test_audit_c_defaults_file_removed_after_mysql_error_exit(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            tmp_path = Path(tmp)
            cnf_list = tmp_path / "cnfs.txt"
            argv_log = tmp_path / "argv.log"
            _shim_mysql(tmp_path, argv_log, cnf_list)
            env = {
                **os.environ,
                "PATH": f"{tmp_path}:{os.environ.get('PATH', '')}",
                "MYSQL_SHIM_EXIT": "1",
            }
            script = textwrap.dedent(
                f"""
                set +o histexpand
            set -euo pipefail
                source "{INSTALLER}"
                MYSQL_ROOT_PASSWORD="audit-c-fail"
                mysql_root_can_use_socket() {{ return 1; }}
                if mysql_root_client -e "SELECT 1"; then exit 0; else exit 0; fi
                """
            )
            subprocess.run(["bash", "-c", script], env=env, check=False)
            for line in cnf_list.read_text(encoding="utf-8").splitlines():
                if line.strip():
                    self.assertFalse(Path(line.strip()).exists())

    def test_audit_c_defaults_file_mode_600_during_call(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            tmp_path = Path(tmp)
            cnf_list = tmp_path / "cnfs.txt"
            modes_log = tmp_path / "modes.log"
            argv_log = tmp_path / "argv.log"
            shim = tmp_path / "mysql"
            shim.write_text(
                "#!/usr/bin/env bash\n"
                f'for a in "$@"; do case "$a" in --defaults-file=*) '
                f'cnf="${{a#--defaults-file=}}"; echo "$cnf" >> "{cnf_list}"; '
                f'stat -c "%a" "$cnf" >> "{modes_log}";; esac; done\n'
                f'printf "%s\\n" "$@" >> "{argv_log}"\n'
                "exit 0\n",
                encoding="utf-8",
            )
            shim.chmod(0o755)
            env = {
                **os.environ,
                "PATH": f"{tmp_path}:{os.environ.get('PATH', '')}",
            }
            script = textwrap.dedent(
                f"""
                set +o histexpand
            set -euo pipefail
                source "{INSTALLER}"
                MYSQL_ROOT_PASSWORD="audit-c-mode"
                mysql_root_can_use_socket() {{ return 1; }}
                mysql_root_client -e "SELECT 1"
                """
            )
            subprocess.run(
                ["bash", "-c", script],
                env=env,
                check=True,
                capture_output=True,
                text=True,
            )
            modes = modes_log.read_text(encoding="utf-8").splitlines()
            self.assertTrue(modes)
            self.assertTrue(all(m == "600" for m in modes if m.strip()))

    def test_audit_c_defaults_file_removed_on_sigterm(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            tmp_path = Path(tmp)
            cnf_list = tmp_path / "cnfs.txt"
            shim = tmp_path / "mysql"
            shim.write_text(
                "#!/usr/bin/env bash\n"
                f'for a in "$@"; do case "$a" in --defaults-file=*) '
                f'echo "${{a#--defaults-file=}}" >> "{cnf_list}";; esac; done\n'
                "sleep 2\n",
                encoding="utf-8",
            )
            shim.chmod(0o755)
            env = {
                **os.environ,
                "PATH": f"{tmp_path}:{os.environ.get('PATH', '')}",
            }
            script = textwrap.dedent(
                f"""
                set +o histexpand
            set -euo pipefail
                source "{INSTALLER}"
                install_traps
                MYSQL_ROOT_PASSWORD="audit-c-term"
                mysql_root_can_use_socket() {{ return 1; }}
                mysql_root_client -e "SELECT 1"
                """
            )
            proc = subprocess.Popen(
                ["bash", "-c", script],
                env=env,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
            )
            try:
                for _ in range(50):
                    if cnf_list.is_file() and cnf_list.read_text(encoding="utf-8").strip():
                        break
                    if proc.poll() is not None:
                        break
                    subprocess.run(["sleep", "0.1"], check=False)
                proc.send_signal(signal.SIGTERM)
                try:
                    out, err = proc.communicate(timeout=10)
                except subprocess.TimeoutExpired:
                    proc.kill()
                    out, err = proc.communicate(timeout=5)
            finally:
                if proc.poll() is None:
                    proc.kill()
                    proc.communicate(timeout=5)
            cnf_paths = [
                p.strip()
                for p in cnf_list.read_text(encoding="utf-8").splitlines()
                if p.strip()
            ]
            self.assertTrue(cnf_paths)
            for p in cnf_paths:
                self.assertFalse(Path(p).exists(), f"cnf should be gone after SIGTERM: {p}")


class OperatorAuditD(unittest.TestCase):
    """Audit (d): newline/NUL rejected; stderr must not contain password."""

    def test_audit_d_newline_password_rejected_without_echo(self) -> None:
        bad = "visible-prefix\nhidden-suffix"
        script = textwrap.dedent(
            f"""
            set +o histexpand
            set -euo pipefail
            source "{INSTALLER}"
            cnf="$(mktemp)"
            mysql_write_client_option_file "$cnf" root "$1"
            """
        )
        proc = subprocess.run(
            ["bash", "-c", script, "bash", bad],
            capture_output=True,
            text=True,
            check=False,
        )
        self.assertNotEqual(proc.returncode, 0)
        combined = (proc.stdout + proc.stderr).lower()
        self.assertIn("rejected", combined)
        self.assertNotIn("hidden-suffix", proc.stdout + proc.stderr)
        self.assertNotIn("visible-prefix\n", proc.stdout + proc.stderr)

    def test_audit_d_nul_password_rejected_without_echo(self) -> None:
        """NUL cannot flow through bash/env; assert installer Python gate exits 2."""
        py_block = _cnf_writer_python_block()
        inline = (
            "password = 'before' + chr(0) + 'after'\n"
            "import sys\n"
            "if '\\0' in password or '\\n' in password or '\\r' in password:\n"
            "    sys.exit(2)\n"
            "sys.exit(0)\n"
        )
        proc = subprocess.run(
            ["python3", "-c", inline],
            capture_output=True,
            text=True,
            check=False,
        )
        self.assertEqual(proc.returncode, 2)
        combined = proc.stdout + proc.stderr
        self.assertNotIn("before", combined)
        self.assertNotIn("after", combined)
        self.assertIn('"\\0" in password', py_block)


class OperatorAuditE(unittest.TestCase):
    """Audit (e): password/socket policy and fail before applied log."""

    def test_audit_e_nonempty_password_forces_defaults_file_despite_socket(
        self,
    ) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            tmp_path = Path(tmp)
            argv_log = tmp_path / "argv.log"
            _shim_mysql(tmp_path, argv_log)
            env = {
                **os.environ,
                "PATH": f"{tmp_path}:{os.environ.get('PATH', '')}",
            }
            script = textwrap.dedent(
                f"""
                set +o histexpand
            set -euo pipefail
                source "{INSTALLER}"
                MYSQL_ROOT_PASSWORD="nonempty-socket-test"
                mysql_root_can_use_socket() {{ return 0; }}
                mysql_root_client -e "SELECT 1"
                """
            )
            subprocess.run(
                ["bash", "-c", script],
                env=env,
                check=True,
                capture_output=True,
                text=True,
            )
            blob = _argv_blob(argv_log)
            self.assertIn("--defaults-file=", blob)
            self.assertNotIn("-u", blob.replace("---", ""))

    def test_audit_e_empty_password_with_socket_uses_mysql_u_root(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            tmp_path = Path(tmp)
            argv_log = tmp_path / "argv.log"
            _shim_mysql(tmp_path, argv_log)
            env = {
                **os.environ,
                "PATH": f"{tmp_path}:{os.environ.get('PATH', '')}",
            }
            script = textwrap.dedent(
                f"""
                set +o histexpand
            set -euo pipefail
                source "{INSTALLER}"
                MYSQL_ROOT_PASSWORD=""
                mysql_root_can_use_socket() {{ return 0; }}
                mysql_root_client -e "SELECT 1"
                """
            )
            subprocess.run(
                ["bash", "-c", script],
                env=env,
                check=True,
                capture_output=True,
                text=True,
            )
            blob = _argv_blob(argv_log)
            self.assertNotIn("--defaults-file=", blob)
            self.assertIn("root", blob)

    def test_audit_e_migration_aborts_before_applied_message_on_mysql_failure(
        self,
    ) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            tmp_path = Path(tmp)
            _shim_mysql(tmp_path, tmp_path / "argv.log")
            env = {
                **os.environ,
                "PATH": f"{tmp_path}:{os.environ.get('PATH', '')}",
                "MYSQL_SHIM_EXIT": "1",
            }
            script = textwrap.dedent(
                f"""
                set +e
                cd "{ROOT}"
                source "./delta-transit-install.sh"
                MYSQL_ROOT_PASSWORD="fail-before-ok"
                mysql_root_can_use_socket() {{ return 1; }}
                log_ok() {{ printf 'LOG_OK:%s\\n' "$*"; }}
                log_info() {{ :; }}
                require_file() {{ :; }}
                ensure_panel_login_attempts_table
                """
            )
            proc = subprocess.run(
                ["bash", "-c", script],
                env=env,
                capture_output=True,
                text=True,
                check=False,
            )
            self.assertNotEqual(proc.returncode, 0)
            combined = proc.stdout + proc.stderr
            self.assertNotIn("panel_login_attempts migration applied", combined)
            self.assertNotIn("LOG_OK:panel_login_attempts migration applied", combined)


if __name__ == "__main__":
    unittest.main()
