#!/usr/bin/env python3
"""Issue #136 — panel login lockout keyed by login+IP (SEC-10 / DAT-18). Offline."""

from __future__ import annotations

import ipaddress
import sqlite3
import unittest
from dataclasses import dataclass
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

MAX_FAILURES = 5
WINDOW = 900
LOCK = 900
MAX_AGE = 86400
PURGE_BATCH = 500


def get_client_ip(server: dict[str, str]) -> str:
    """Same rule as web/includes/helpers.php getClientIp()."""
    remote_addr = server.get("REMOTE_ADDR", "")
    if remote_addr not in ("127.0.0.1", "::1"):
        return remote_addr
    candidate = None
    real_ip = server.get("HTTP_X_REAL_IP")
    forwarded = server.get("HTTP_X_FORWARDED_FOR")
    if real_ip:
        candidate = real_ip.split(",")[0].strip()
    elif forwarded:
        candidate = forwarded.split(",")[0].strip()
    if candidate:
        try:
            return str(ipaddress.ip_address(candidate))
        except ValueError:
            pass
    return remote_addr


@dataclass
class Attempt:
    row_id: int
    login: str
    ip: str
    ts: int
    result: str


class LoginLockoutStore:
    """In-memory store matching panel_login_attempts semantics."""

    def __init__(self) -> None:
        self.rows: list[Attempt] = []
        self._next_id = 1

    def purge(self, now: int) -> None:
        cutoff = now - MAX_AGE
        expired = [r for r in self.rows if r.ts < cutoff]
        expired.sort(key=lambda r: r.ts)
        drop = set(id(r) for r in expired[:PURGE_BATCH])
        self.rows = [r for r in self.rows if id(r) not in drop]

    def record(self, login: str, ip: str, result: str, now: int) -> None:
        self.rows.append(Attempt(self._next_id, login, ip, now, result))
        self._next_id += 1

    def _cluster_after_success(self, login: str, ip: str) -> list[Attempt]:
        last_success_id = max(
            (
                r.row_id
                for r in self.rows
                if r.login == login and r.ip == ip and r.result == "success"
            ),
            default=0,
        )
        fails = [
            r
            for r in self.rows
            if r.login == login
            and r.ip == ip
            and r.result == "failure"
            and r.row_id > last_success_id
        ]
        if not fails:
            return []
        last = max(r.ts for r in fails)
        return [r for r in fails if r.ts >= last - WINDOW]

    def is_locked(self, login: str, ip: str, now: int) -> bool:
        self.purge(now)
        cluster = self._cluster_after_success(login, ip)
        if len(cluster) < MAX_FAILURES:
            return False
        last = max(r.ts for r in cluster)
        return (now - last) < LOCK

    def allow(self, login: str, ip: str, now: int) -> bool:
        return not self.is_locked(login, ip, now)

    def register_failure(self, login: str, ip: str, now: int) -> None:
        self.record(login, ip, "failure", now)

    def register_success(self, login: str, ip: str, now: int) -> None:
        self.record(login, ip, "success", now)


class LoginLockoutTests(unittest.TestCase):
    def test_lockout_per_login_and_ip(self) -> None:
        store = LoginLockoutStore()
        t0 = 1_700_000_000
        for i in range(MAX_FAILURES):
            self.assertTrue(store.allow("master", "10.0.0.8", t0 + i))
            store.register_failure("master", "10.0.0.8", t0 + i)
        self.assertFalse(store.allow("master", "10.0.0.8", t0 + MAX_FAILURES))
        self.assertFalse(store.allow("master", "10.0.0.8", t0 + MAX_FAILURES + 10))

    def test_other_ip_not_locked(self) -> None:
        store = LoginLockoutStore()
        t0 = 1_700_000_000
        for i in range(MAX_FAILURES):
            store.register_failure("master", "10.0.0.8", t0 + i)
        self.assertFalse(store.allow("master", "10.0.0.8", t0 + 5))
        self.assertTrue(store.allow("master", "10.0.0.9", t0 + 5))
        self.assertTrue(store.allow("other", "10.0.0.8", t0 + 5))

    def test_lock_expires_after_fifteen_minutes(self) -> None:
        store = LoginLockoutStore()
        t0 = 1_700_000_000
        last = t0 + 4
        for i in range(MAX_FAILURES):
            store.register_failure("master", "10.0.0.8", t0 + i)
        self.assertFalse(store.allow("master", "10.0.0.8", last + LOCK - 1))
        self.assertTrue(store.allow("master", "10.0.0.8", last + LOCK))

    def test_successful_login_resets_counter(self) -> None:
        store = LoginLockoutStore()
        t0 = 1_700_000_000
        for i in range(4):
            store.register_failure("master", "10.0.0.8", t0 + i)
        self.assertTrue(store.allow("master", "10.0.0.8", t0 + 4))
        store.register_success("master", "10.0.0.8", t0 + 4)
        for i in range(4):
            store.register_failure("master", "10.0.0.8", t0 + 10 + i)
        self.assertTrue(store.allow("master", "10.0.0.8", t0 + 20))
        store.register_failure("master", "10.0.0.8", t0 + 20)
        self.assertFalse(store.allow("master", "10.0.0.8", t0 + 21))

    def test_spoofed_x_forwarded_for_ignored_when_remote_not_localhost(self) -> None:
        spoofed = get_client_ip(
            {
                "REMOTE_ADDR": "203.0.113.10",
                "HTTP_X_FORWARDED_FOR": "10.0.0.1",
                "HTTP_X_REAL_IP": "10.0.0.1",
            }
        )
        self.assertEqual(spoofed, "203.0.113.10")
        store = LoginLockoutStore()
        t0 = 1_700_000_000
        for i in range(MAX_FAILURES):
            store.register_failure("master", spoofed, t0 + i)
        self.assertFalse(store.allow("master", spoofed, t0 + 5))
        self.assertTrue(store.allow("master", "10.0.0.1", t0 + 5))

    def test_localhost_may_use_forwarded_header(self) -> None:
        self.assertEqual(
            get_client_ip(
                {
                    "REMOTE_ADDR": "127.0.0.1",
                    "HTTP_X_FORWARDED_FOR": "10.1.2.3",
                }
            ),
            "10.1.2.3",
        )

    def test_purge_is_bounded(self) -> None:
        store = LoginLockoutStore()
        now = 2_000_000_000
        for i in range(PURGE_BATCH + 20):
            store.record("master", "10.0.0.8", "failure", now - MAX_AGE - 10 - i)
        store.purge(now)
        self.assertEqual(len(store.rows), 20)

    def test_sqlite_counts_per_pair(self) -> None:
        conn = sqlite3.connect(":memory:")
        conn.execute(
            """CREATE TABLE panel_login_attempts (
                login TEXT, ip TEXT, attempted_at INTEGER, result TEXT
            )"""
        )
        t0 = 1000
        for i in range(5):
            conn.execute(
                "INSERT INTO panel_login_attempts VALUES (?,?,?,?)",
                ("master", "10.0.0.8", t0 + i, "failure"),
            )
        conn.execute(
            "INSERT INTO panel_login_attempts VALUES (?,?,?,?)",
            ("master", "10.0.0.9", t0, "failure"),
        )
        n_a = conn.execute(
            "SELECT COUNT(*) FROM panel_login_attempts WHERE login=? AND ip=? AND result='failure'",
            ("master", "10.0.0.8"),
        ).fetchone()[0]
        n_b = conn.execute(
            "SELECT COUNT(*) FROM panel_login_attempts WHERE login=? AND ip=? AND result='failure'",
            ("master", "10.0.0.9"),
        ).fetchone()[0]
        self.assertEqual(n_a, 5)
        self.assertEqual(n_b, 1)

    def test_same_second_success_clears_failures(self) -> None:
        store = LoginLockoutStore()
        t0 = 1_700_000_000
        for _ in range(MAX_FAILURES):
            store.register_failure("master", "10.0.0.8", t0)
        store.register_success("master", "10.0.0.8", t0)
        self.assertTrue(store.allow("master", "10.0.0.8", t0))

    def test_same_second_failure_after_success_counts(self) -> None:
        store = LoginLockoutStore()
        t0 = 1_700_000_000
        store.register_success("master", "10.0.0.8", t0)
        for _ in range(MAX_FAILURES):
            self.assertTrue(store.allow("master", "10.0.0.8", t0))
            store.register_failure("master", "10.0.0.8", t0)
        self.assertFalse(store.allow("master", "10.0.0.8", t0))


class SourceContractTests(unittest.TestCase):
    def test_php_lockout_is_database_not_session(self) -> None:
        auth = (ROOT / "web/includes/auth.php").read_text(encoding="utf-8")
        lockout = (ROOT / "web/includes/panel_login_lockout.php").read_text(encoding="utf-8")
        ui = (ROOT / "web/includes/panel_auth_ui.php").read_text(encoding="utf-8")
        helpers = (ROOT / "web/includes/helpers.php").read_text(encoding="utf-8")
        self.assertIn("panel_login_attempts", lockout)
        self.assertIn("PANEL_LOGIN_MAX_FAILURES = 5", lockout)
        self.assertIn("PANEL_LOGIN_WINDOW_SECONDS = 900", lockout)
        self.assertIn("PANEL_LOGIN_LOCK_SECONDS = 900", lockout)
        self.assertIn("LIMIT {$limit}", lockout)
        self.assertNotIn("$_SESSION['login_fail_count']", lockout)
        self.assertNotIn("login_fail_count", ui)
        self.assertIn("panelLoginThrottleAllow($username, $ip)", ui)
        self.assertIn("panelLoginThrottleClear($username, $ip)", ui)
        self.assertIn("panelLoginThrottleBegin($username, $ip)", ui)
        handle_fn = ui[ui.find("function handleLoginSubmit") : ui.find("function handleLogout")]
        self.assertIn("panelLoginThrottleClear($username, $ip)", handle_fn)
        self.assertIn("try {", handle_fn)
        self.assertIn("} finally {", handle_fn)
        self.assertIn("panelLoginThrottleEnd($username, $ip)", handle_fn)
        session_fn = auth[auth.find("function establishPanelSession") : auth.find("function clearPanelSession")]
        self.assertNotIn("panelLoginThrottleClear", session_fn)
        self.assertIn("AND id > COALESCE", lockout)
        self.assertIn("getClientIp()", ui)
        self.assertIn("auth.login_locked", ui)
        self.assertIn("panel_login_lockout.php", auth)
        self.assertIn("REMOTE_ADDR", helpers)
        self.assertIn("127.0.0.1", helpers)
        idx = helpers.find("function getClientIp()")
        body = helpers[idx : idx + 900]
        self.assertLess(
            body.find("return $remoteAddr"),
            body.find("HTTP_X_FORWARDED_FOR"),
        )
        schema = (ROOT / "schema.sql").read_text(encoding="utf-8")
        migration = (ROOT / "migrations/007_panel_login_attempts.sql").read_text(encoding="utf-8")
        self.assertIn("CREATE TABLE IF NOT EXISTS panel_login_attempts", schema)
        self.assertIn("CREATE TABLE IF NOT EXISTS panel_login_attempts", migration)
        self.assertNotIn("DROP TABLE", migration)
        installer = (ROOT / "delta-transit-install.sh").read_text(encoding="utf-8")
        self.assertIn("ensure_panel_login_attempts_table", installer)
        self.assertIn("./migrations/007_panel_login_attempts.sql", installer)
        lockout = (ROOT / "web/includes/panel_login_lockout.php").read_text(encoding="utf-8")
        self.assertIn("login path does not CREATE", lockout)
        en = (ROOT / "web/lang/en.php").read_text(encoding="utf-8")
        ru = (ROOT / "web/lang/ru.php").read_text(encoding="utf-8")
        self.assertIn("'auth.login_locked'", en)
        self.assertIn("'auth.login_locked'", ru)


if __name__ == "__main__":
    unittest.main()
