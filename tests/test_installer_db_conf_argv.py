#!/usr/bin/env python3
"""Issue #175 — write_db_config keeps mail_proxy password off python3 argv."""

from __future__ import annotations

import configparser
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

# M6: this guard must stay defined and non-empty.
_FORBIDDEN_SECRET_ON_EXTERNAL_ARGV = re.compile(
    r"(?:"
    r'python3\s+(?:-\\|\n\s*)?["\']?\$PARAM_DB_PASS'
    r"|python3\s+(?:-\\|\n\s*)?[\"']?\$\{PARAM_DB_PASS\}"
    r"|python3\s+(?:-\\|\n\s*)?[\"']?\$db_pass"
    r"|python3\s+(?:-\\|\n\s*)?[\"']?\$\{db_pass\}"
    r"|python3\s+(?:-\\|\n\s*)?[\"']?\$_MP_PASS"
    r"|python3\s+(?:-\\|\n\s*)?[\"']?\$MYSQL_ROOT_PASSWORD"
    r"|python3\s+(?:-\\|\n\s*)?[\"']?\$\{MYSQL_ROOT_PASSWORD\}"
    r"|php\s+(?:-\\|\n\s*)?[\"']?\$PARAM_DB_PASS"
    r"|php\s+(?:-\\|\n\s*)?[\"']?\$\{PARAM_DB_PASS\}"
    r"|php\s+(?:-\\|\n\s*)?[\"']?\$db_pass"
    r"|php\s+(?:-\\|\n\s*)?[\"']?\$\{db_pass\}"
    r"|php\s+(?:-\\|\n\s*)?[\"']?\$_MP_PASS"
    r"|php\s+(?:-\\|\n\s*)?[\"']?\$MYSQL_ROOT_PASSWORD"
    r"|php\s+(?:-\\|\n\s*)?[\"']?\$\{MYSQL_ROOT_PASSWORD\}"
    r"|openssl[^\n]*[\"']?\$PARAM_DB_PASS"
    r"|openssl[^\n]*[\"']?\$\{PARAM_DB_PASS\}"
    r"|openssl[^\n]*[\"']?\$MYSQL_ROOT_PASSWORD"
    r")",
    re.MULTILINE,
)

_ALLOWED_EXCEPTIONS: tuple[tuple[str, str], ...] = (
    (
        "delta-transit-install.sh",
        "DB_CONF_PASS=\"$PARAM_DB_PASS\" python3 - \"$tmp_file\" uses env prefix only "
        "(password not on python3 argv); same pattern as MYSQL_CNF_PASS.",
    ),
    (
        "delta-transit-install.sh",
        "MYSQL_CNF_PASS=\"$pass\" / \"$_MP_PASS\" python3 - \"$cnf\" \"$user\" — password in env only.",
    ),
    (
        "delta-transit-install.sh",
        "PANEL_MASTER_PASSWORD=… php -r — password via environment, not argv.",
    ),
)

_PROBE_FILE_RE = re.compile(r'probe_path\s*=\s*outfile\s*\+\s*["\']\.probe["\']')
_FORBIDDEN_PASS_FILE_TO_PYTHON = re.compile(
    r'printf\s+[\'"]%s[\'"]\s+"\$PARAM_DB_PASS"\s*>',
    re.MULTILINE,
)


def _read_installer() -> str:
    return INSTALLER.read_text(encoding="utf-8")


def _decontinued(text: str) -> str:
    return re.sub(r"\\\s*\n\s*", " ", text)


def _named_function_body(text: str, name: str) -> str:
    pattern = rf"(?m)^{re.escape(name)}\(\) \{{\n(?P<body>.*?)\n\}}"
    found = re.search(pattern, text, re.S)
    if not found:
        raise AssertionError(f"function {name} not found")
    return found.group("body")


def _write_db_config_python_heredoc(text: str | None = None) -> str:
    src = text if text is not None else _read_installer()
    m = re.search(
        r"write_db_config\(\) \{.*?<<'PY'\n(.*?)\nPY",
        src,
        re.S,
    )
    if not m:
        raise AssertionError("write_db_config Python heredoc not found")
    return m.group(1)


def _write_db_config_block(text: str | None = None) -> str:
    src = text if text is not None else _read_installer()
    m = re.search(
        r"(?ms)^write_db_config\(\) \{(.*?)^\}\n\n# Read-only",
        src,
    )
    if not m:
        raise AssertionError("write_db_config block not found")
    return m.group(1)


def _quote_free_prefix(password: str, n: int = 8) -> str:
    cleaned = password.replace("'", "").replace('"', "")
    return cleaned[:n]


def _write_python3_shim(tmp: Path, argv_log: Path) -> Path:
    real = subprocess.check_output(["bash", "-c", "command -v python3"], text=True).strip()
    shim = tmp / "python3"
    shim.write_text(
        textwrap.dedent(
            f"""\
            #!/usr/bin/env bash
            saved=("$@")
            printf '%s\\0' "${{saved[@]}}" >> "{argv_log}"
            printf '\\n---\\n' >> "{argv_log}"
            exec "{real}" "$@"
            """
        ),
        encoding="utf-8",
    )
    shim.chmod(0o755)
    return shim


def _write_env_logging_shims(tmp: Path, env_log: Path) -> None:
    chmod_shim = tmp / "chmod"
    chmod_shim.write_text(
        textwrap.dedent(
            f"""\
            #!/usr/bin/env bash
            {{
              echo "CMD=chmod"
              env | sort
              echo "---"
            }} >> "{env_log}"
            /bin/chmod "$@"
            """
        ),
        encoding="utf-8",
    )
    chmod_shim.chmod(0o755)
    chown_shim = tmp / "chown"
    chown_shim.write_text(
        textwrap.dedent(
            f"""\
            #!/usr/bin/env bash
            {{
              echo "CMD=chown"
              env | sort
              echo "---"
            }} >> "{env_log}"
            exit 0
            """
        ),
        encoding="utf-8",
    )
    chown_shim.chmod(0o755)
    mv_shim = tmp / "mv"
    mv_shim.write_text(
        textwrap.dedent(
            f"""\
            #!/usr/bin/env bash
            {{
              echo "CMD=mv"
              env | sort
              echo "---"
            }} >> "{env_log}"
            /bin/mv "$@"
            """
        ),
        encoding="utf-8",
    )
    mv_shim.chmod(0o755)


def _argv_blob(argv_log: Path) -> str:
    if not argv_log.is_file():
        return ""
    return argv_log.read_bytes().decode("utf-8", "replace")


def _run_write_db_config(
    tmp_path: Path,
    password: str,
) -> tuple[Path, Path, Path, subprocess.CompletedProcess[str]]:
    argv_log = tmp_path / "argv.log"
    env_log = tmp_path / "env.log"
    scratch = tmp_path / "config"
    scratch.mkdir()
    db_conf = scratch / "db.conf"
    _write_python3_shim(tmp_path, argv_log)
    _write_env_logging_shims(tmp_path, env_log)

    env = {
        **os.environ,
        "PATH": f"{tmp_path}:{os.environ.get('PATH', '')}",
        "DB_CONF": str(db_conf),
        "GROUP_CRYPTO": "mail-proxy-crypto-shim",
        "CONFIG_DIR": str(scratch),
        "PARAM_DB_PASS": password,
    }
    script = textwrap.dedent(
        f"""
        set +o histexpand
        set -euo pipefail
        cd "{ROOT}"
        _SHIM_DB_PASS="${{PARAM_DB_PASS:?}}"
        source "./delta-transit-install.sh"
        PARAM_DB_PASS="$_SHIM_DB_PASS"
        DB_CONF="{db_conf}"
        CONFIG_DIR="{scratch}"
        GROUP_CRYPTO="mail-proxy-crypto-shim"
        log_info() {{ :; }}
        log_ok() {{ :; }}
        log_warn() {{ :; }}
        register_tmpfile() {{ :; }}
        install_traps() {{ :; }}
        write_db_config
        """
    )
    proc = subprocess.run(
        ["bash", "-c", script],
        env=env,
        capture_output=True,
        text=True,
    )
    return db_conf, argv_log, env_log, proc


class InstallerDbConfArgvStaticTests(unittest.TestCase):
    def test_forbidden_secret_on_external_argv_guard_pattern_required(self) -> None:
        """M6: removing _FORBIDDEN_SECRET_ON_EXTERNAL_ARGV must fail tests."""
        self.assertIsNotNone(_FORBIDDEN_SECRET_ON_EXTERNAL_ARGV)
        self.assertGreater(
            len(_FORBIDDEN_SECRET_ON_EXTERNAL_ARGV.pattern),
            80,
            "static argv guard pattern must stay substantive",
        )

    def test_installer_and_scripts_no_secret_on_python_php_openssl_argv(self) -> None:
        paths = (INSTALLER, *SCRIPTS)
        for path in paths:
            text = _decontinued(path.read_text(encoding="utf-8"))
            hit = _FORBIDDEN_SECRET_ON_EXTERNAL_ARGV.search(text)
            self.assertIsNone(
                hit,
                f"{path.name}: forbidden secret on external argv: {hit.group(0) if hit else ''}",
            )

    def test_write_db_config_uses_db_conf_pass_env_prefix(self) -> None:
        body = _write_db_config_block()
        self.assertRegex(body, r'DB_CONF_PASS="\$PARAM_DB_PASS"\s+python3\s+-\s+"\$tmp_file"')
        self.assertIn('os.environ.pop("DB_CONF_PASS"', body)
        self.assertNotRegex(body, r'python3\s+-\s+"\$PARAM_DB_PASS"')

    def test_write_db_config_no_pass_file_staged_for_python_argv(self) -> None:
        body = _write_db_config_block()
        self.assertIsNone(
            _FORBIDDEN_PASS_FILE_TO_PYTHON.search(body),
            "must not write password to a temp file for python argv",
        )

    def test_write_db_config_no_probe_file_on_disk(self) -> None:
        body = _write_db_config_block()
        self.assertIsNone(_PROBE_FILE_RE.search(body), "must not write outfile + .probe to disk")
        self.assertIn("io.StringIO", body)

    def test_allowed_exceptions_documented(self) -> None:
        self.assertGreaterEqual(len(_ALLOWED_EXCEPTIONS), 1)
        for where, why in _ALLOWED_EXCEPTIONS:
            self.assertTrue(where.endswith(".sh"), where)
            self.assertTrue(why.strip(), "exception reason required")


class InstallerDbConfArgvShimTests(unittest.TestCase):
    _SPECIAL_PASS = 'pa$`"s s\'\\#x'
    _PLAIN_PASS = "PlainAlpha9z"

    def _assert_password_absent_from_argv(self, blob: str, password: str) -> None:
        self.assertNotIn(password, blob)
        prefix = _quote_free_prefix(password)
        if len(prefix) >= 8:
            self.assertNotIn(prefix, blob)

    def _assert_db_conf(self, db_conf: Path, password: str) -> None:
        self.assertTrue(db_conf.is_file(), "db.conf missing after write_db_config")
        mode = stat.S_IMODE(db_conf.stat().st_mode)
        self.assertEqual(mode, 0o640, f"db.conf mode expected 0640, got {oct(mode)}")
        cfg = configparser.ConfigParser()
        cfg.read(db_conf, encoding="utf-8")
        got = cfg.get("db", "db_pass")
        self.assertEqual(got, password)

    def _assert_no_probe_files(self, tmp_path: Path) -> None:
        probes = list(tmp_path.rglob("*.probe"))
        self.assertEqual(probes, [], f"unexpected .probe files: {probes}")

    def _assert_no_loose_secret_files(self, tmp_path: Path, db_conf: Path, password: str) -> None:
        for path in tmp_path.rglob("*"):
            if not path.is_file() or path == db_conf:
                continue
            if path.name in ("argv.log", "env.log"):
                continue
            data = path.read_bytes()
            if password.encode() in data:
                self.fail(f"password bytes found in leftover file {path}")
            prefix = _quote_free_prefix(password).encode()
            if len(prefix) >= 8 and prefix in data:
                self.fail(f"password prefix found in leftover file {path}")

    def _assert_db_conf_pass_not_leaked_to_later_commands(self, env_log: Path) -> None:
        text = env_log.read_text(encoding="utf-8") if env_log.is_file() else ""
        self.assertNotIn("DB_CONF_PASS=", text, "DB_CONF_PASS leaked to chmod/mv/chown environment")

    def test_write_db_config_special_password_off_argv_and_round_trip(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            tmp_path = Path(tmp)
            db_conf, argv_log, env_log, proc = _run_write_db_config(
                tmp_path, self._SPECIAL_PASS
            )
            self.assertEqual(proc.returncode, 0, proc.stderr or proc.stdout)
            blob = _argv_blob(argv_log)
            self._assert_password_absent_from_argv(blob, self._SPECIAL_PASS)
            self._assert_db_conf(db_conf, self._SPECIAL_PASS)
            self._assert_no_probe_files(tmp_path)
            self._assert_no_loose_secret_files(tmp_path, db_conf, self._SPECIAL_PASS)
            self._assert_db_conf_pass_not_leaked_to_later_commands(env_log)

    def test_write_db_config_plain_password_off_argv_and_round_trip(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            tmp_path = Path(tmp)
            db_conf, argv_log, env_log, proc = _run_write_db_config(
                tmp_path, self._PLAIN_PASS
            )
            self.assertEqual(proc.returncode, 0, proc.stderr or proc.stdout)
            blob = _argv_blob(argv_log)
            self._assert_password_absent_from_argv(blob, self._PLAIN_PASS)
            self._assert_db_conf(db_conf, self._PLAIN_PASS)
            self._assert_no_probe_files(tmp_path)
            self._assert_no_loose_secret_files(tmp_path, db_conf, self._PLAIN_PASS)
            self._assert_db_conf_pass_not_leaked_to_later_commands(env_log)

    def test_round_trip_failure_is_loud_when_write_corrupted(self) -> None:
        """Installer Python block must abort when on-disk db_pass does not round-trip."""
        py_body = _write_db_config_python_heredoc()
        tail = py_body.split("# Round-trip:", 1)[-1]
        tail = textwrap.dedent(tail)
        verify_only = textwrap.dedent(
            """\
            import configparser
            import os
            import sys

            password = os.environ.pop("DB_CONF_PASS", "")
            outfile = sys.argv[1]
            """
        ) + "# Round-trip:" + tail
        with tempfile.TemporaryDirectory() as tmp:
            tmp_path = Path(tmp)
            outfile = tmp_path / "db.conf"
            cfg = configparser.ConfigParser()
            cfg["db"] = {
                "db_host": "127.0.0.1",
                "db_user": "mail_proxy",
                "db_pass": "CORRUPT-NOT-MATCHING",
                "db_name": "mail_proxy",
                "db_port": "3306",
            }
            with outfile.open("w", encoding="utf-8") as f:
                cfg.write(f)
            proc = subprocess.run(
                ["bash", "-c", f'python3 - "{outfile}" <<\'PY\'\n{verify_only}\nPY'],
                env={**os.environ, "DB_CONF_PASS": self._PLAIN_PASS},
                capture_output=True,
                text=True,
                check=False,
            )
            self.assertNotEqual(proc.returncode, 0)
            combined = (proc.stdout or "") + (proc.stderr or "")
            self.assertIn("db.conf round-trip verification failed", combined)


class InstallerDbConfMutationGuardTests(unittest.TestCase):
    def test_mutation_guard_module_present(self) -> None:
        self.assertTrue(INSTALLER.is_file())


if __name__ == "__main__":
    unittest.main()