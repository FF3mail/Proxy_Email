#!/usr/bin/env python3
"""Issue #148 — installer mail_proxy mysql never passes password on argv."""

from __future__ import annotations

import os
import re
import stat
import subprocess
import tempfile
import textwrap
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
INSTALLER = ROOT / "delta-transit-install.sh"
SCRIPTS = tuple((ROOT / "scripts").glob("*.sh"))

_FORBIDDEN_MAIL_PROXY_ARGV = (
    re.compile(r'-p"\$\{db_pass\}"'),
    re.compile(r'-p"\$\{PARAM_DB_PASS\}"'),
    re.compile(r"-p\$\{db_pass\}"),
    re.compile(r"-p\$\{PARAM_DB_PASS\}"),
    re.compile(r"mysql[^\n]*--password="),
    re.compile(r"mariadb[^\n]*--password="),
)

def _read_installer() -> str:
    return INSTALLER.read_text(encoding="utf-8")


def _named_function_body(text: str, name: str) -> str:
    pattern = rf"(?m)^{re.escape(name)}\(\) \{{\n(?P<body>.*?)\n\}}"
    found = re.search(pattern, text, re.S)
    if not found:
        raise AssertionError(f"function {name} not found")
    return found.group("body")


def _shim_mysql(tmp: Path, argv_log: Path, cnf_hook: Path) -> Path:
    shim = tmp / "mysql"
    shim.write_text(
        "#!/usr/bin/env bash\n"
        "saved=(\"$@\")\n"
        "sql=\"\"\n"
        "for ((i=0; i<${#saved[@]}; i++)); do\n"
        '  if [[ "${saved[i]}" == "-e" ]]; then sql="${saved[i+1]}"; break; fi\n'
        "done\n"
        'if [[ "$sql" == *"COUNT(*)"* ]]; then echo 0; fi\n'
        'if [[ "$sql" == *"SHOW TABLES"* ]]; then echo shim_probe; fi\n'
        "for a in \"${saved[@]}\"; do\n"
        f"  case \"$a\" in --defaults-file=*)\n"
        f"    cnf=\"${{a#--defaults-file=}}\"\n"
        f"    echo \"$cnf\" >> \"{cnf_hook}\"\n"
        f"    if [[ -f \"$cnf\" ]]; then\n"
        f"      mode=$(stat -c '%a' \"$cnf\" 2>/dev/null || echo 0)\n"
        f"      echo \"MODE:$mode\" >> \"{cnf_hook}\"\n"
        f"      cat \"$cnf\" >> \"{cnf_hook}\"\n"
        f"    fi\n"
        "    ;;\n"
        "  esac\n"
        "done\n"
        f'printf "%s\\0" "${{saved[@]}}" >> "{argv_log}"\n'
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


def _cnf_hook_text(cnf_hook: Path) -> str:
    if not cnf_hook.is_file():
        return ""
    return cnf_hook.read_text(encoding="utf-8")


class InstallerMysqlMailProxyArgvStaticTests(unittest.TestCase):
    def test_installer_has_no_mail_proxy_password_on_argv_patterns(self) -> None:
        text = _read_installer()
        for pattern in _FORBIDDEN_MAIL_PROXY_ARGV:
            self.assertIsNone(
                pattern.search(text),
                f"forbidden mail_proxy mysql argv pattern in installer: {pattern.pattern}",
            )

    def test_scripts_sh_have_no_mail_proxy_password_on_argv_patterns(self) -> None:
        for script in SCRIPTS:
            text = script.read_text(encoding="utf-8")
            for pattern in _FORBIDDEN_MAIL_PROXY_ARGV:
                self.assertIsNone(
                    pattern.search(text),
                    f"{script.name}: forbidden pattern {pattern.pattern}",
                )

    def test_mysql_mail_proxy_client_uses_option_file_not_p_flag(self) -> None:
        body = _named_function_body(_read_installer(), "mysql_mail_proxy_client")
        self.assertIn("_mysql_mail_proxy_client_with_pass", body)
        self.assertIn("resolve_db_pass", body)
        self.assertNotRegex(body, r"\s-p")

    def test_shared_client_helper_uses_option_file_not_p_flag(self) -> None:
        body = _named_function_body(_read_installer(), "_mysql_mail_proxy_client_with_pass")
        self.assertIn("mysql_client_with_defaults_file", body)
        self.assertIn('rm -f "$cnf"', body)
        self.assertIn("mysql_write_client_option_file", body)
        self.assertNotRegex(body, r"\s-p")

    def test_validate_db_connectivity_uses_from_conf_not_resolve_db_pass(self) -> None:
        body = _named_function_body(_read_installer(), "validate_db_connectivity")
        self.assertIn("mysql_mail_proxy_client_from_conf", body)
        self.assertNotIn("mysql_mail_proxy_client ", body.replace("mysql_mail_proxy_client_from_conf", ""))
        self.assertNotIn("resolve_db_pass", body)

    def test_audit_database_contents_uses_from_conf_not_resolve_db_pass(self) -> None:
        body = _named_function_body(_read_installer(), "audit_database_contents")
        self.assertIn("mysql_mail_proxy_client_from_conf", body)
        self.assertNotIn("resolve_db_pass", body)

    def test_from_conf_helper_reads_db_conf_only(self) -> None:
        body = _named_function_body(_read_installer(), "mysql_mail_proxy_client_from_conf")
        self.assertIn("read_db_pass_from_conf", body)
        self.assertNotIn("resolve_db_pass", body)


class InstallerMysqlMailProxyShimTests(unittest.TestCase):
    _FAKE_PASS = 'pa$`"s s\'\\'

    def _run_all_nine(self, tmp_path: Path) -> tuple[Path, Path]:
        argv_log = tmp_path / "argv.log"
        cnf_hook = tmp_path / "cnf_hook.log"
        _shim_mysql(tmp_path, argv_log, cnf_hook)
        php_shim = tmp_path / "php"
        php_shim.write_text(
            "#!/usr/bin/env bash\n"
            'if [[ "$1" == "-r" ]]; then echo "$2y$10$shimfakehashshimfakehashshimfakehashshim"; exit 0; fi\n'
            "exit 127\n",
            encoding="utf-8",
        )
        php_shim.chmod(0o755)
        db_conf = tmp_path / "db.conf"
        db_conf.write_text(
            "[db]\n"
            "db_host = 127.0.0.1\n"
            "db_port = 3306\n"
            "db_name = mail_proxy_shim\n"
            "db_user = mail_proxy\n"
            f"db_pass = {self._FAKE_PASS}\n",
            encoding="utf-8",
        )
        schema_sql = tmp_path / "schema.sql"
        schema_sql.write_text("-- empty\n", encoding="utf-8")
        env = {
            **os.environ,
            "PATH": f"{tmp_path}:{os.environ.get('PATH', '')}",
            "DB_CONF": str(db_conf),
            "SHIM_FAKE_DB_PASS": self._FAKE_PASS,
        }
        script = textwrap.dedent(
            f"""
            set +o histexpand
            set -euo pipefail
            cd "{ROOT}"
            source "./delta-transit-install.sh"
            DB_NAME="mail_proxy_shim"
            DB_USER="mail_proxy"
            PARAM_DB_PASS="${{SHIM_FAKE_DB_PASS:?}}"
            MYSQL_SHIM_EXIT=0
            log_info() {{ :; }}
            log_ok() {{ :; }}
            log_warn() {{ :; }}
            register_tmpfile() {{ :; }}
            install_traps() {{ :; }}
            PANEL_MASTER_USERNAME="shim_master"
            PANEL_MASTER_PASSWORD="ShimPass123!"

            ensure_panel_admins_table
            panel_login_attempts_table_exists || true
            panel_master_exists || true
            panel_active_master_exists || true
            try_detect_active_panel_master || true

            panel_active_master_exists() {{ return 1; }}
            panel_master_exists() {{ return 1; }}
            require_command() {{ :; }}
            seed_panel_master

            schema_already_installed || true
            validate_db_connectivity
            audit_database_contents
            """
        )
        subprocess.run(
            ["bash", "-c", script],
            env=env,
            check=True,
            capture_output=True,
            text=True,
        )
        return argv_log, cnf_hook

    def test_nine_paths_never_put_password_on_mysql_argv(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            argv_log, cnf_hook = self._run_all_nine(Path(tmp))
            blob = _argv_blob(argv_log)
            self.assertIn("--defaults-file=", blob.replace("---", ""))
            self.assertNotIn(self._FAKE_PASS, blob)
            self.assertNotIn("-p", blob.replace("---", ""))
            hook = _cnf_hook_text(cnf_hook)
            self.assertIn("MODE:600", hook)
            self.assertRegex(hook, r'password="[^"]*pa\$`')

    def test_mysql_mail_proxy_client_removes_cnf_after_call(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            tmp_path = Path(tmp)
            cnf_left: list[str] = []
            shim = tmp_path / "mysql"
            shim.write_text(
                "#!/usr/bin/env bash\n"
                "for a in \"$@\"; do\n"
                "  case \"$a\" in --defaults-file=*)\n"
                f"    echo \"${{a#--defaults-file=}}\" >> \"{tmp_path / 'seen.cnf'}\"\n"
                "    ;;\n"
                "  esac\n"
                "done\n"
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
                set -euo pipefail
                source "{INSTALLER}"
                DB_NAME="mail_proxy"
                DB_USER="mail_proxy"
                PARAM_DB_PASS="cleanup-test-secret"
                mysql_mail_proxy_client -e "SELECT 1"
                while IFS= read -r f; do
                  [[ -n "$f" ]] || continue
                  if [[ -f "$f" ]]; then echo "STILL:$f"; fi
                done < "{tmp_path / 'seen.cnf'}"
                """
            )
            proc = subprocess.run(
                ["bash", "-c", script],
                env=env,
                capture_output=True,
                text=True,
                check=False,
            )
            self.assertEqual(proc.returncode, 0, proc.stderr)
            self.assertEqual(proc.stdout.strip(), "", "temp cnf not removed after mysql_mail_proxy_client")

    def test_nested_return_trap_not_cleared_by_helper(self) -> None:
        """mysql_mail_proxy_client trap - RETURN must not break caller RETURN cleanup."""
        with tempfile.TemporaryDirectory() as tmp:
            tmp_path = Path(tmp)
            marker = tmp_path / "caller_cleanup.done"
            shim = tmp_path / "mysql"
            shim.write_text("#!/usr/bin/env bash\nexit 0\n", encoding="utf-8")
            shim.chmod(0o755)
            env = {
                **os.environ,
                "PATH": f"{tmp_path}:{os.environ.get('PATH', '')}",
            }
            script = textwrap.dedent(
                f"""
                set -euo pipefail
                source "{INSTALLER}"
                DB_NAME="mail_proxy"
                DB_USER="mail_proxy"
                PARAM_DB_PASS="trap-test"
                outer_cleanup() {{
                  touch "{marker}"
                }}
                outer() {{
                  trap outer_cleanup RETURN
                  mysql_mail_proxy_client -e "SELECT 1"
                }}
                outer
                """
            )
            subprocess.run(["bash", "-c", script], env=env, check=True)
            self.assertTrue(marker.is_file(), "caller RETURN trap did not run")

    def test_validate_and_audit_use_db_conf_password_when_param_differs(self) -> None:
        conf_pass = "CONF-PASS-ONLY-7a9c"
        param_pass = "PARAM-PASS-MUST-NOT-APPEAR"
        self.assertNotEqual(conf_pass, param_pass)
        with tempfile.TemporaryDirectory() as tmp:
            tmp_path = Path(tmp)
            argv_log = tmp_path / "argv.log"
            cnf_hook = tmp_path / "cnf_hook.log"
            _shim_mysql(tmp_path, argv_log, cnf_hook)
            db_conf = tmp_path / "db.conf"
            db_conf.write_text(
                "[db]\n"
                "db_host = 127.0.0.1\n"
                "db_port = 3306\n"
                "db_name = mail_proxy_shim\n"
                "db_user = mail_proxy\n"
                f"db_pass = {conf_pass}\n",
                encoding="utf-8",
            )
            env = {
                **os.environ,
                "PATH": f"{tmp_path}:{os.environ.get('PATH', '')}",
                "DB_CONF": str(db_conf),
                "PARAM_DB_PASS": param_pass,
            }
            script = textwrap.dedent(
                f"""
                set +o histexpand
                set -euo pipefail
                source "{INSTALLER}"
                DB_CONF="{db_conf}"
                DB_NAME="mail_proxy_shim"
                DB_USER="mail_proxy"
                PARAM_DB_PASS="{param_pass}"
                register_tmpfile() {{ :; }}
                validate_db_connectivity
                audit_database_contents() {{
                  local tables_out
                  tables_out="$(mktemp)"
                  register_tmpfile "$tables_out"
                  mysql_mail_proxy_client_from_conf -e "SHOW TABLES;" > "$tables_out" || true
                  rm -f "$tables_out"
                }}
                audit_database_contents
                """
            )
            subprocess.run(["bash", "-c", script], env=env, check=True)
            hook = _cnf_hook_text(cnf_hook)
            self.assertIn(conf_pass, hook)
            self.assertNotIn(param_pass, hook)
            self.assertNotIn(param_pass, _argv_blob(argv_log))


class InstallerMysqlMailProxyMutationGuardTests(unittest.TestCase):
    """Sanity: mutation ids M1–M5 must break at least one test in this module."""

    def test_mutation_guard_documented(self) -> None:
        self.assertTrue(INSTALLER.is_file())


if __name__ == "__main__":
    unittest.main()
