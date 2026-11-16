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

_FAKE_PASS_SPECIAL = 'pa$`"s s\'\\'
_FAKE_PASS_PLAIN = "MailProxyPlain9z"

_PASS_CASES = (
    ("special", _FAKE_PASS_SPECIAL),
    ("plain", _FAKE_PASS_PLAIN),
)

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


def _escape_sql_string(value: str) -> str:
    return value.replace("'", "''")


def _quote_free_prefix(password: str) -> str:
    if "'" in password:
        return password.split("'", 1)[0]
    return password


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


def _assert_argv_has_no_secret_sql(argv: str, password: str) -> None:
    blob = argv.replace("---", "")
    escaped = _escape_sql_string(password)
    prefix = _quote_free_prefix(password)
    forbidden = (
        "IDENTIFIED BY",
        "CREATE USER",
        "ALTER USER",
        password,
        escaped,
        prefix,
        "--init-command",
        "--execute",
    )
    for needle in forbidden:
        if needle:
            assert needle not in blob, f"argv must not contain {needle!r}"


def _assert_stdin_has_user_sql(stdin: str, password: str) -> None:
    escaped = _escape_sql_string(password)
    prefix = _quote_free_prefix(password)
    assert stdin.count("IDENTIFIED BY") == 2, stdin
    assert "CREATE USER" in stdin, stdin
    assert "ALTER USER" in stdin, stdin
    assert password in stdin or escaped in stdin, "password literal on stdin"
    assert prefix in stdin, "password prefix on stdin"


def _assert_no_password_in_tree(root: Path, password: str) -> None:
    escaped = _escape_sql_string(password)
    for path in root.rglob("*"):
        if not path.is_file():
            continue
        if path.name in ("argv.log", "stdin.log", "cnf_hook.log", "reg.log"):
            continue
        text = path.read_bytes().decode("utf-8", "replace")
        assert password not in text, f"password in unexpected file {path}"
        assert escaped not in text, f"escaped password in unexpected file {path}"


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
    fake_pass: str,
    fail_create_user: bool = False,
    shim_root_pass: str = "",
    stub_register: bool = True,
    real_install_traps: bool = False,
) -> tuple[int, str, str, str, str]:
    argv_log = tmp_path / "argv.log"
    stdin_log = tmp_path / "stdin.log"
    cnf_hook = tmp_path / "cnf_hook.log"
    reg_log = tmp_path / "reg.log"
    _shim_mysql_stdin(tmp_path, argv_log, stdin_log, cnf_hook)
    env = {
        **os.environ,
        "PATH": f"{tmp_path}:{os.environ.get('PATH', '')}",
        "MYSQL_SHIM_FAIL_CREATE_USER": "1" if fail_create_user else "0",
        "MYSQL_SHIM_EXIT": "0",
        "SHIM_FAKE_DB_PASS": fake_pass,
        "SHIM_ROOT_PASS": shim_root_pass,
        "SHIM_REG_LOG": str(reg_log),
    }
    socket_override = (
        "mysql_root_can_use_socket() { return 0; }"
        if use_socket
        else "mysql_root_can_use_socket() { return 1; }"
    )
    register_stub = (
        "register_tmpfile() { :; }"
        if stub_register
        else textwrap.dedent(
            """
            register_tmpfile() {
                TMP_FILES+=("$1")
                echo "$1" >> "${SHIM_REG_LOG:?}"
            }
            """
        ).strip()
    )
    traps_line = (
        "install_traps"
        if real_install_traps
        else textwrap.dedent(
            """
            install_traps() { :; }
            """
        ).strip()
    )
    track_block = ""
    if not stub_register:
        track_block = textwrap.dedent(
            f"""
            printf 'REGISTERED_COUNT=%s\\n' "$(wc -l < "{reg_log}" 2>/dev/null || echo 0)"
            """
        )
    script = textwrap.dedent(
        f"""
        set +o histexpand
        set -euo pipefail
        cd "{ROOT}"
        source "./delta-transit-install.sh"
        {socket_override}
        {register_stub}
        MYSQL_ROOT_PASSWORD="${{SHIM_ROOT_PASS-}}"
        DB_NAME="mail_proxy_shim"
        DB_USER="mail_proxy"
        PARAM_DB_PASS="${{SHIM_FAKE_DB_PASS:?}}"
        {traps_line}
        log_info() {{ :; }}
        log_ok() {{ :; }}
        log_warn() {{ :; }}
        create_database
        create_database_user
        {track_block}
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
    cnf_blob = cnf_hook.read_text(encoding="utf-8") if cnf_hook.is_file() else ""
    reg_blob = reg_log.read_text(encoding="utf-8") if reg_log.is_file() else ""
    return proc.returncode, proc.stdout, argv_blob, stdin_blob, cnf_blob + ("\n" + reg_blob if reg_blob else "")


class InstallerCreateUserStdinStaticTests(unittest.TestCase):
    def test_identified_by_argv_guard_pattern_required(self) -> None:
        """M5: removing this module's mysql_root_exec/IDENTIFIED BY regex must fail tests."""
        source = Path(__file__).read_text(encoding="utf-8")
        self.assertRegex(
            source,
            r"_IDENTIFIED_BY_IN_MYSQL_ROOT_EXEC\s*=\s*re\.compile\(",
            "static argv guard pattern _IDENTIFIED_BY_IN_MYSQL_ROOT_EXEC must stay defined",
        )
        self.assertIn(
            'r\'mysql_root_exec\\s+"[^"]*IDENTIFIED\\s+BY\'',
            source,
            "argv guard must keep mysql_root_exec … IDENTIFIED BY regex literal",
        )
        self.assertIn(
            "_IDENTIFIED_BY_IN_MYSQL_ROOT_EXEC.search(text)",
            source,
            "test_no_identified_by must call the argv guard regex",
        )

    def test_no_identified_by_in_mysql_root_exec_argv(self) -> None:
        guard = _IDENTIFIED_BY_IN_MYSQL_ROOT_EXEC
        self.assertIsNotNone(guard, "argv IDENTIFIED BY guard pattern must be wired")
        for path in (INSTALLER, *SCRIPTS):
            text = path.read_text(encoding="utf-8")
            m = guard.search(text)
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
        body = re.search(
            r"(?m)^mysql_root_exec_stdin\(\) \{\n(?P<body>.*?)\n\}",
            text,
            re.S,
        )
        self.assertIsNotNone(body)
        fn = body.group("body")
        self.assertIn("mysql_root_client < <(printf '%s\\n' \"$sql\")", fn)
        self.assertNotIn("| mysql_root_client", fn)
        self.assertNotIn("--init-command", fn)
        self.assertNotIn("--execute", fn)

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
    def _shim_case(self, *, use_socket: bool, fake_pass: str) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            tmp_path = Path(tmp)
            rc, _out, argv, stdin, meta = _run_create_db_user_harness(
                tmp_path,
                use_socket=use_socket,
                fake_pass=fake_pass,
                shim_root_pass='root$ecret"pw' if not use_socket else "",
            )
            self.assertEqual(rc, 0, meta)
            _assert_argv_has_no_secret_sql(argv, fake_pass)
            _assert_stdin_has_user_sql(stdin, fake_pass)
            self.assertIn("GRANT ALL PRIVILEGES", argv.replace("---", ""))
            self.assertIn("FLUSH PRIVILEGES", argv)
            self.assertIn("CREATE DATABASE", argv.replace("---", ""))
            if use_socket:
                self.assertNotIn("--defaults-file=", argv.replace("---", ""))
            else:
                self.assertIn("--defaults-file=", argv)
                self.assertIn("MODE:600", meta)
                cnf_paths = [
                    ln
                    for ln in meta.splitlines()
                    if ln and not ln.startswith("MODE:") and ln.startswith("/")
                ]
                for p in cnf_paths:
                    self.assertFalse(Path(p).exists(), f"temp cnf not removed: {p}")
            _assert_no_password_in_tree(tmp_path, fake_pass)

    def test_socket_path_two_passwords(self) -> None:
        for _label, pw in _PASS_CASES:
            with self.subTest(password=_label):
                self._shim_case(use_socket=True, fake_pass=pw)

    def test_option_file_path_two_passwords(self) -> None:
        for _label, pw in _PASS_CASES:
            with self.subTest(password=_label):
                self._shim_case(use_socket=False, fake_pass=pw)

    def test_create_user_failure_aborts_create_database_user(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            rc, _, _, _, _ = _run_create_db_user_harness(
                Path(tmp),
                use_socket=True,
                fake_pass=_FAKE_PASS_SPECIAL,
                fail_create_user=True,
            )
            self.assertNotEqual(rc, 0)


class InstallerCreateUserStdinRegisterTmpfileTests(unittest.TestCase):
    def test_option_file_registers_cnf_in_parent_shell(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            tmp_path = Path(tmp)
            rc, out, _argv, _stdin, meta = _run_create_db_user_harness(
                tmp_path,
                use_socket=False,
                fake_pass=_FAKE_PASS_PLAIN,
                shim_root_pass="root-only-for-cnf",
                stub_register=False,
            )
            self.assertEqual(rc, 0, meta)
            reg_lines = [ln for ln in meta.splitlines() if ln.startswith("/")]
            self.assertGreaterEqual(len(reg_lines), 1, "register_tmpfile did not record cnf in parent shell")
            self.assertIn("REGISTERED_COUNT=", out)

    def test_abort_on_create_user_leaves_no_cnf_files(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            tmp_path = Path(tmp)
            rc, _out, argv, _stdin, meta = _run_create_db_user_harness(
                tmp_path,
                use_socket=False,
                fake_pass=_FAKE_PASS_PLAIN,
                shim_root_pass="root-abort-cnf",
                fail_create_user=True,
                stub_register=False,
                real_install_traps=True,
            )
            self.assertNotEqual(rc, 0)
            leftover = []
            for path in tmp_path.rglob("tmp.*"):
                if path.is_file():
                    leftover.append(str(path))
            cnf_paths = [
                ln
                for ln in meta.splitlines()
                if ln.startswith("/") and "tmp." in ln
            ]
            for p in cnf_paths:
                if Path(p).exists():
                    leftover.append(p)
            self.assertFalse(leftover, f"leftover cnf/tmp files after abort: {leftover}")
            self.assertNotIn(_FAKE_PASS_PLAIN, argv)


if __name__ == "__main__":
    unittest.main()