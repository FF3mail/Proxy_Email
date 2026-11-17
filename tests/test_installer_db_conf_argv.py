#!/usr/bin/env python3
"""Issue #175 — write_db_config keeps mail_proxy password off python3 argv."""

from __future__ import annotations

import configparser
import os
import re
import shlex
import stat
import subprocess
import tempfile
import textwrap
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
INSTALLER = ROOT / "delta-transit-install.sh"
CONFIGURE_LIMITS = ROOT / "configure_limits.sh"
SCRIPTS = tuple(sorted((ROOT / "scripts").glob("*.sh")))

# M9 mutation target: must stay non-empty or synthetic + repo scans lose coverage.
GUARDED_EXTERNAL_COMMANDS: frozenset[str] = frozenset(
    {
        "python3",
        "python",
        "php",
        "openssl",
        "curl",
        "wget",
        "logger",
        "ssh",
        "sshpass",
        "scp",
        "gpg",
        "htpasswd",
        "mail",
        "mutt",
        "sendmail",
    }
)

_EXPLICIT_SECRET_VARS: frozenset[str] = frozenset(
    {
        "PARAM_DB_PASS",
        "db_pass",
        "_MP_PASS",
        "pass",
        "pass1",
        "pass2",
        "MYSQL_ROOT_PASSWORD",
        "PANEL_MASTER_PASSWORD",
        "MYSQL_CNF_PASS",
        "DB_CONF_PASS",
        "APP_PASS",
    }
)

_SECRET_VAR_NAME_RE = re.compile(r"(?i)(pass|passwd|password|secret|token|key)")

_NON_SECRET_ARG_VARS: frozenset[str] = frozenset(
    {
        "tmp_file",
        "tmp",
        "cnf",
        "DB_CONF",
        "PARAM_APP_URL",
        "outfile",
        "user",
        "path",
        "url",
        # openssl self-signed cert paths (material is generated, not a DB/install secret)
        "NGINX_SSL_KEY",
        "NGINX_SSL_CERT",
        "NGINX_SERVER_NAME",
        "san_ext",
    }
)

_HEREDOC_START_RE = re.compile(
    r"(?<![<])<<(?![<])-?\s*'([^']+)'"
    r"|(?<![<])<<(?![<])-?\s*\"([^\"]+)\""
    r"|(?<![<])<<(?![<])-?\s*([A-Za-z_][A-Za-z0-9_]*)"
)

_ASSIGNMENT_PREFIX_RE = re.compile(
    r"^(?:export\s+)?[A-Za-z_][A-Za-z0-9_]*=(?:'[^']*'|\"[^\"]*\"|\S+)$"
)

_WRAPPER_WORDS = frozenset({"sudo", "env", "command", "exec", "nice"})


def _is_secret_var_name(name: str) -> bool:
    if name in _NON_SECRET_ARG_VARS:
        return False
    if name in _EXPLICIT_SECRET_VARS:
        return True
    return _SECRET_VAR_NAME_RE.search(name) is not None


def _collect_var_names_from_text(fragment: str) -> set[str]:
    names: set[str] = set()
    for m in re.finditer(r"\$\{([A-Za-z_][A-Za-z0-9_]*)(?::[^}]*)?\}", fragment):
        names.add(m.group(1))
    for m in re.finditer(r"\$([A-Za-z_][A-Za-z0-9_]*)", fragment):
        names.add(m.group(1))
    for m in re.finditer(r"\$\(([^)]*)\)", fragment):
        names |= _collect_var_names_from_text(m.group(1))
    for m in re.finditer(r"`([^`]*)`", fragment):
        names |= _collect_var_names_from_text(m.group(1))
    return names


def _argument_tokens_contain_secret(args: list[str]) -> bool:
    for tok in args:
        for name in _collect_var_names_from_text(tok):
            if _is_secret_var_name(name):
                return True
    return False


def _command_basename(word: str) -> str:
    word = word.strip()
    if word.startswith("("):
        return ""
    return Path(word).name


def _join_line_continuations(text: str) -> list[tuple[int, str]]:
    raw_lines = text.splitlines()
    physical: list[tuple[int, str]] = [(i + 1, ln) for i, ln in enumerate(raw_lines)]
    joined: list[tuple[int, str]] = []
    idx = 0
    while idx < len(physical):
        start_lineno, acc = physical[idx]
        while acc.rstrip().endswith("\\") and idx + 1 < len(physical):
            acc = acc.rstrip()[:-1].rstrip() + " " + physical[idx + 1][1].lstrip()
            idx += 1
        joined.append((start_lineno, acc))
        idx += 1
    return joined


def _heredoc_skip_lines(lines: list[tuple[int, str]]) -> set[int]:
    skip: set[int] = set()
    i = 0
    while i < len(lines):
        lineno, content = lines[i]
        if lineno in skip:
            i += 1
            continue
        m = _HEREDOC_START_RE.search(content)
        if not m:
            i += 1
            continue
        tag = m.group(1) or m.group(2) or m.group(3)
        i += 1
        while i < len(lines):
            term_lineno, term_line = lines[i]
            skip.add(term_lineno)
            if term_line.strip() == tag:
                i += 1
                break
            i += 1
    return skip


def _split_outside_quotes(line: str, seps: tuple[str, ...]) -> list[str]:
    parts: list[str] = []
    buf: list[str] = []
    i = 0
    in_single = False
    in_double = False
    n = len(line)

    def flush() -> None:
        parts.append("".join(buf).strip())
        buf.clear()

    while i < n:
        ch = line[i]
        if in_single:
            buf.append(ch)
            if ch == "'":
                in_single = False
            i += 1
            continue
        if in_double:
            buf.append(ch)
            if ch == "\\" and i + 1 < n:
                buf.append(line[i + 1])
                i += 2
                continue
            if ch == '"':
                in_double = False
            i += 1
            continue
        if ch == "'":
            in_single = True
            buf.append(ch)
            i += 1
            continue
        if ch == '"':
            in_double = True
            buf.append(ch)
            i += 1
            continue
        if i + 1 < n and line[i : i + 2] == "||":
            flush()
            i += 2
            continue
        if i + 1 < n and line[i : i + 2] == "&&":
            flush()
            i += 2
            continue
        if ch in seps:
            flush()
            i += 1
            continue
        buf.append(ch)
        i += 1
    flush()
    return [p for p in parts if p]


def _strip_redirections_simple(cmd: str) -> str:
    try:
        tokens = shlex.split(cmd, posix=True)
    except ValueError:
        tokens = cmd.split()
    out: list[str] = []
    i = 0
    while i < len(tokens):
        t = tokens[i]
        if t in {"<", ">", ">>", "&>", ">&", "2>", "2>>", "2>&1"}:
            i += 1
            if i < len(tokens):
                i += 1
            continue
        if t in {"<<", "<<<"}:
            i += 1
            if i < len(tokens):
                i += 1
            continue
        if t.startswith("<") or t.startswith(">") or (
            len(t) > 1 and t[0] in "0123456789" and ">" in t
        ):
            i += 1
            continue
        out.append(t)
        i += 1
    return " ".join(out)


def _parse_simple_command(segment: str) -> tuple[str, list[str]] | None:
    segment = _strip_redirections_simple(segment.strip())
    if not segment:
        return None
    try:
        words = shlex.split(segment, posix=True)
    except ValueError:
        words = segment.split()
    if not words:
        return None
    while words and (
        _ASSIGNMENT_PREFIX_RE.match(words[0])
        or re.match(r"^[A-Za-z_][A-Za-z0-9_]*=", words[0])
    ):
        words.pop(0)
    while words:
        w = words[0]
        if w in _WRAPPER_WORDS:
            words.pop(0)
            continue
        if w == "timeout" and len(words) >= 2 and words[1].isdigit():
            words = words[2:]
            continue
        break
    if not words:
        return None
    for i, w in enumerate(words):
        cmd_word = _command_basename(w)
        if cmd_word in GUARDED_EXTERNAL_COMMANDS:
            return cmd_word, words[i + 1 :]
    cmd_word = _command_basename(words[0])
    return cmd_word, words[1:]


def find_secret_on_external_argv(text: str) -> list[tuple[int, str, str]]:
    """Return (lineno, command, snippet) for guarded commands with secret args.

    Deliberately excludes mysql/mariadb (covered by #145/#148/#171 tests).
    """
    joined = _join_line_continuations(text)
    heredoc_skip = _heredoc_skip_lines(joined)
    hits: list[tuple[int, str, str]] = []
    for lineno, line in joined:
        if lineno in heredoc_skip:
            continue
        stripped = line.strip()
        if not stripped or stripped.startswith("#"):
            continue
        for segment in _split_outside_quotes(line, ("|", ";", "&")):
            parsed = _parse_simple_command(segment)
            if not parsed:
                continue
            cmd_word, args = parsed
            if cmd_word not in GUARDED_EXTERNAL_COMMANDS:
                continue
            if _argument_tokens_contain_secret(args):
                snippet = segment.strip()
                if len(snippet) > 160:
                    snippet = snippet[:157] + "..."
                hits.append((lineno, cmd_word, snippet))
    return hits

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
    (
        "delta-transit-install.sh",
        "openssl req -keyout/-out $NGINX_SSL_* — certificate paths, not installer DB/root secrets.",
    ),
)

_PROBE_FILE_RE = re.compile(r'probe_path\s*=\s*outfile\s*\+\s*["\']\.probe["\']')
_FORBIDDEN_PASS_FILE_TO_PYTHON = re.compile(
    r'printf\s+[\'"]%s[\'"]\s+"\$PARAM_DB_PASS"\s*>',
    re.MULTILINE,
)


def _read_installer() -> str:
    return INSTALLER.read_text(encoding="utf-8")


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


class SecretOnExternalArgvCheckerTests(unittest.TestCase):
    """Unit tests for find_secret_on_external_argv (synthetic snippets)."""

    def _assert_detects(self, snippet: str, cmd: str | None = None) -> None:
        hits = find_secret_on_external_argv(snippet)
        self.assertTrue(hits, f"expected detection in: {snippet!r}")
        if cmd is not None:
            self.assertEqual(hits[0][1], cmd)

    def _assert_clean(self, snippet: str) -> None:
        hits = find_secret_on_external_argv(snippet)
        self.assertEqual(hits, [], f"unexpected hits {hits!r} in: {snippet!r}")

    def test_detects_original_write_db_config_argv_leak(self) -> None:
        self._assert_detects('python3 - "$PARAM_DB_PASS" "$tmp_file" <<\'PY\'', "python3")

    def test_detects_leak_probe_python_c_pass(self) -> None:
        self._assert_detects('leak_probe() { python3 -c "pass" "${db_pass}"; }', "python3")

    def test_detects_leak_probe2_php_continuation(self) -> None:
        snippet = 'leak_probe2() {\n    php -r "echo 1;" \\\n        "$PARAM_DB_PASS"\n}'
        self._assert_detects(snippet, "php")

    def test_detects_leak_probe3_openssl(self) -> None:
        self._assert_detects(
            'leak_probe3() { openssl passwd "${MYSQL_ROOT_PASSWORD}"; }', "openssl"
        )

    def test_detects_logger_secret(self) -> None:
        self._assert_detects('logger -t x "$PARAM_DB_PASS"', "logger")

    def test_detects_sshpass(self) -> None:
        self._assert_detects('sshpass -p "$pass" ssh host', "sshpass")

    def test_detects_curl_user_pass(self) -> None:
        self._assert_detects('curl -u "user:$pass" url', "curl")

    def test_allowed_db_conf_pass_env_prefix_python(self) -> None:
        self._assert_clean('DB_CONF_PASS="$PARAM_DB_PASS" python3 - "$tmp_file" <<\'PY\'')

    def test_allowed_mysql_cnf_pass_env_prefix(self) -> None:
        self._assert_clean('MYSQL_CNF_PASS="$pass" python3 - "$cnf" "$user" <<\'PY\'')

    def test_allowed_panel_master_password_php_getenv(self) -> None:
        self._assert_clean(
            'PANEL_MASTER_PASSWORD="${PANEL_MASTER_PASSWORD}" php -r '
            '\'echo password_hash(getenv("PANEL_MASTER_PASSWORD"), PASSWORD_DEFAULT);\''
        )

    def test_allowed_python_path_and_app_url_only(self) -> None:
        self._assert_clean('python3 - "$tmp" "$PARAM_APP_URL" <<\'PY\'')

    def test_comment_line_mentioning_leak_not_detected(self) -> None:
        self._assert_clean('# python3 - "$PARAM_DB_PASS" was the old form')

    def test_heredoc_body_with_fake_leak_not_detected(self) -> None:
        snippet = textwrap.dedent(
            """\
            python3 - "$tmp_file" <<'PY'
            python3 "$PARAM_DB_PASS"
            PY
            """
        )
        self._assert_clean(snippet)

    def test_guarded_commands_list_nonempty(self) -> None:
        """M6/M9: guarded command list must stay substantive."""
        self.assertGreater(len(GUARDED_EXTERNAL_COMMANDS), 10)


class InstallerDbConfArgvStaticTests(unittest.TestCase):
    def test_installer_scripts_configure_limits_no_secret_on_guarded_argv(self) -> None:
        paths = (INSTALLER, CONFIGURE_LIMITS, *SCRIPTS)
        all_hits: list[tuple[str, tuple[int, str, str]]] = []
        for path in paths:
            text = path.read_text(encoding="utf-8")
            for hit in find_secret_on_external_argv(text):
                all_hits.append((path.name, hit))
        self.assertEqual(
            all_hits,
            [],
            "find_secret_on_external_argv hits (mysql/mariadb excluded by design): "
            + repr(all_hits),
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