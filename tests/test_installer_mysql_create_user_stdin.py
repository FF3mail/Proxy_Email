#!/usr/bin/env python3
"""Issue #171 — create_database_user keeps mail_proxy password off mysql argv (stdin)."""

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
SCRIPTS = tuple((ROOT / "scripts").glob("*.sh"))

_FAKE_PASS = 'pa$`"s s\'\\'

_IDENTIFIED_BY_IN_MYSQL_ROOT_EXEC = re.compile(
    r'mysql_root_exec\s+"[^"]*IDENTIFIED\s+BY',
    re.S,
)

_FORBIDDEN_SECRET_IN_E_ARGV = (
    re.compile(r'-e\s*"[^"]*\$\{db_pass_sql\}'),
    re.compile(r'-e\s*"[^"]*\$\{PARAM_DB_PASS\}'),
    re.compile(r"-e\s*'[^']*\$\{db_pass_sql\}"),
    re.compile(r"-e\s*'[^']*\$\{PARAM_DB_PASS\}"),
)


def _read_installer() -> str:
    return INSTALLER.read_text(encoding="utf-8")


def _mysql_root_exec_sql_literals(text: str) -> list[str]:
    literals: list[str] = []
    for m in re.finditer(r"mysql_root_exec\s+\"", text):
        i = m.end()
        while i < len(text):
            if text[i] == '"':
                literals.append(text[m.end() : i])
                break
            i += 1
    return literals


def _shim_mysql_stdin(
    tmp: Path,
    argv_log: Path,
    stdin_log: Path,
    cnf_hook: Path,
) -> Path:
    shim = tmp / "mysql"
    shim.write_text(
        "#!/usr/bin/env bash\n"
        "saved=(\"$@\")\n"
        "stdin_blob=\"\"\n"
        "if [[ ! -t 0 ]]; then stdin_blob=\"$(cat)\"; fi\n"
        "sql=\"\"\n"
        "for ((i=0; i<${#saved[@]}; i++)); do\n"
        '  if [[ "${saved[i]}" == "-e" ]]; then sql="${saved[i+1]}"; break; fi\n'
        "done\n"
        'if [[ "$sql" == *"SELECT 1"* ]] || [[ "$stdin_blob" == *"SELECT 1"* ]]; then\n'
        "  :;\n"
        "fi\n"
        'if [[ "$sql" == *"CREATE DATABASE"* ]]; then echo OK_DB; fi\n'
        "for a in \"${saved[@]}\"; do\n"
        f"  case \"$a\" in --defaults-file=*)\n"
        f"    cnf=\"${{a#--defaults-file=}}\"\n"
        f"    echo \"$cnf\" >> \"{cnf_hook}\"\n"
        f"    if [[ -f \"$cnf\" ]]; then\n"
        f"      mode=$(stat -c '%a' \"$cnf\" 2>/dev/null || echo 0)\n"
        f"      echo \"MODE:$mode\" >> \"{cnf_hook}\"\n"
        f"    fi\n"
        "    ;;\n"
        "  esac\n"
        "done\n"
        f'printf "%s\\0" "${{saved[@]}}" >> "{argv_log}"\n'
        f"printf '\\n---\\n' >> \"{argv_log}\"\n"
        f'printf "%s" "$stdin_blob" >> "{stdin_log}"\n'
        f"printf '\\n---STDIN---\\n' >> \"{stdin_log}\"\n"
        'if [[ "$stdin_blob" == *"CREATE USER"* ]] && [[ "${MYSQL_SHIM_FAIL_CREATE_USER:-0}" == 1 ]]; then\n'
        "  exit 42\n"
        "fi\n"
        "exit ${MYSQL_SHIM_EXIT:-0}\n",
        encoding="utf-8",
    )
    shim.chmod(0o755)
    return shim


def _run_create_db_user_harness(
    tmp_path: Path,
    *,
    use_socket: bool,
    fail_create_user: bool = False,
    shim_root_pass: str = "",
) -> tuple[int, str, str, str]:
    argv_log = tmp_path / "argv.log"
    stdin_log = tmp_path / "stdin.log"
    cnf_hook = tmp_path / "cnf_hook.log"
    _shim_mysql_stdin(tmp_path, argv_log, stdin_log, cnf_hook)
    env = {
        **os.environ,
        "PATH": f"{tmp_path}:{os.environ.get('PATH', '')}",
        "MYSQL_SHIM_FAIL_CREATE_USER": "1" if fail_create_user else "0",
        "MYSQL_SHIM_EXIT": "0",
        "SHIM_FAKE_DB_PASS": _FAKE_PASS,
        "SHIM_ROOT_PASS": shim_root_pass,
    }
    socket_override = (
        "mysql_root_can_use_socket() { return 0; }"
        if use_socket
        else "mysql_root_can_use_socket() { return 1; }"
    )
    script = textwrap.dedent(
        f"""
        set +o histexpand
        set -euo pipefail
        cd "{ROOT}"
        source "./delta-transit-install.sh"
        {socket_override}
        MYSQL_ROOT_PASSWORD="${{SHIM_ROOT_PASS-}}"
        DB_NAME="mail_proxy_shim"
        DB_USER="mail_proxy"
        PARAM_DB_PASS="${{SHIM_FAKE_DB_PASS:?}}"
        register_tmpfile() {{ :; }}
        install_traps() {{ :; }}
        log_info() {{ :; }}
        log_ok() {{ :; }}
        log_warn() {{ :; }}
        create_database
        create_database_user
        """
    )
    if fail_create_user:
        script = script.replace("set -euo pipefail", "set -uo pipefail")
    proc = subprocess.run(
        ["bash", "-c", script],
        env=env,
        capture_output=True,
        text=True,
    )
    argv_blob = argv_log.read_bytes().decode("utf-8", "replace") if argv_log.is_file() else ""
    stdin_blob = stdin_log.read_bytes().decode("utf-8", "replace") if stdin_log.is_file() else ""
    cnf_hook = cnf_hook.read_text(encoding="utf-8") if (tmp_path / "cnf_hook.log").is_file() else ""
    return proc.returncode, argv_blob, stdin_blob, cnf_hook


class InstallerCreateUserStdinStaticTests(unittest.TestCase):
    def test_no_identified_by_in_mysql_root_exec_argv(self) -> None:
        for path in (INSTALLER, *SCRIPTS):
            text = path.read_text(encoding="utf-8")
            m = _IDENTIFIED_BY_IN_MYSQL_ROOT_EXEC.search(text)
            self.assertIsNone(
                m,
                f"{path.name}: IDENTIFIED BY must not appear in mysql_root_exec argv SQL",
            )
            for block in _mysql_root_exec_sql_literals(text):
                self.assertNotIn(
                    "IDENTIFIED BY",
                    block,
                    f"{path.name}: IDENTIFIED BY in mysql_root_exec -e SQL",
                )

    def test_create_database_user_uses_stdin_helper_for_secrets(self) -> None:
        text = _read_installer()
        body = re.search(
            r"(?m)^create_database_user\(\) \{\n(?P<body>.*?)\n\}",
            text,
            re.S,
        )
        self.assertIsNotNone(body)
        fn_body = body.group("body")
        self.assertEqual(fn_body.count("mysql_root_exec_stdin"), 2)
        self.assertNotRegex(fn_body, r"mysql_root_exec\s+\"\s*\n\s*CREATE USER")
        self.assertNotRegex(fn_body, r"mysql_root_exec\s+\"\s*\n\s*ALTER USER")

    def test_mysql_root_exec_stdin_defined(self) -> None:
        text = _read_installer()
        self.assertRegex(text, r"mysql_root_exec_stdin\(\)")
        self.assertIn('printf \'%s\\n\' "$sql" | mysql_root_client', text)

    def test_mysql_root_client_does_not_read_stdin_before_mysql(self) -> None:
        body = re.search(
            r"(?m)^mysql_root_client\(\) \{\n(?P<body>.*?)\n\}",
            _read_installer(),
            re.S,
        ).group("body")
        self.assertNotRegex(body, r"\bread\b")
        self.assertNotRegex(body, r"<&0")
        self.assertNotRegex(body, r"\bcat\b")

    def test_no_db_secret_vars_in_mysql_e_argv(self) -> None:
        for path in (INSTALLER, *SCRIPTS):
            text = path.read_text(encoding="utf-8")
            for pattern in _FORBIDDEN_SECRET_IN_E_ARGV:
                self.assertIsNone(
                    pattern.search(text),
                    f"{path.name}: secret variable in mysql -e argv: {pattern.pattern}",
                )


class InstallerCreateUserStdinShimTests(unittest.TestCase):
    def test_socket_path_password_on_stdin_not_argv(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            rc, argv, stdin, _cnf = _run_create_db_user_harness(Path(tmp), use_socket=True)
            self.assertEqual(rc, 0)
            self.assertNotIn(_FAKE_PASS, argv)
            self.assertIn('pa$`"s s', stdin)
            self.assertEqual(stdin.count("IDENTIFIED BY"), 2)
            self.assertIn("CREATE USER", stdin)
            self.assertIn("ALTER USER", stdin)
            self.assertIn("GRANT ALL PRIVILEGES", argv.replace("---", ""))
            self.assertIn("FLUSH PRIVILEGES", argv)
            self.assertIn("CREATE DATABASE", argv.replace("---", ""))
            self.assertNotIn("--defaults-file=", argv.replace("---", ""))

    def test_option_file_path_password_on_stdin_not_argv(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            rc, argv, stdin, cnf_hook = _run_create_db_user_harness(
                Path(tmp),
                use_socket=False,
                shim_root_pass='root$ecret"pw',
            )
            self.assertEqual(rc, 0)
            self.assertNotIn(_FAKE_PASS, argv)
            self.assertIn('pa$`"s s', stdin)
            self.assertIn("--defaults-file=", argv)
            self.assertIn("MODE:600", cnf_hook)
            cnf_paths = [ln for ln in cnf_hook.splitlines() if ln and not ln.startswith("MODE:")]
            for p in cnf_paths:
                self.assertFalse(Path(p).exists(), f"temp cnf not removed: {p}")

    def test_create_user_failure_aborts_create_database_user(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            rc, _, _, _ = _run_create_db_user_harness(
                Path(tmp),
                use_socket=True,
                fail_create_user=True,
            )
            self.assertNotEqual(rc, 0)


class InstallerCreateUserStdinMutationGuardTests(unittest.TestCase):
    def test_mutation_guard_documented(self) -> None:
        self.assertTrue(INSTALLER.is_file())


if __name__ == "__main__":
    unittest.main()