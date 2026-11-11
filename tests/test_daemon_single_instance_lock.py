#!/usr/bin/env python3
"""DMN-11 / DEV-30 — single-instance daemon lock and systemd exit status."""

from __future__ import annotations

import importlib.util
import os
import subprocess
import sys
import tempfile
import time
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
DAEMON_PY = ROOT / 'mail-proxy-daemon.py'
SERVICE = (ROOT / 'mail-proxy.service').read_text(encoding='utf-8')


def _load_daemon_via_c(*extra_lines: str) -> str:
    lines = [
        'import importlib.util',
        'import os',
        'import sys',
        f'spec = importlib.util.spec_from_file_location("mail_proxy_daemon", {DAEMON_PY!r})',
        'mod = importlib.util.module_from_spec(spec)',
        'spec.loader.exec_module(mod)',
        *extra_lines,
    ]
    return '\n'.join(lines) + '\n'


class DaemonSingleInstanceLockTests(unittest.TestCase):
    def test_systemd_unit_prevents_restart_on_lock_exit(self) -> None:
        self.assertIn('RestartPreventExitStatus=75', SERVICE)
        self.assertIn('Restart=on-failure', SERVICE)

    def test_exit_constant_matches_unit(self) -> None:
        spec = importlib.util.spec_from_file_location('mail_proxy_daemon', DAEMON_PY)
        mod = importlib.util.module_from_spec(spec)
        assert spec.loader is not None
        spec.loader.exec_module(mod)
        self.assertEqual(mod.EXIT_LOCK_HELD, 75)

    def test_first_lock_acquisition_succeeds(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            lock_path = os.path.join(tmp, 'mail-proxy.lock')
            os.environ['MAIL_PROXY_LOCK_FILE'] = lock_path
            spec = importlib.util.spec_from_file_location('mail_proxy_daemon', DAEMON_PY)
            mod = importlib.util.module_from_spec(spec)
            assert spec.loader is not None
            spec.loader.exec_module(mod)
            fd = mod.acquire_single_instance_lock(lock_path)
            self.assertGreater(fd, 0)
            os.close(fd)

    def test_second_acquisition_exits_75(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            lock_path = os.path.join(tmp, 'mail-proxy.lock')
            holder = _load_daemon_via_c(
                f'mod.acquire_single_instance_lock({lock_path!r})',
                'import time',
                'time.sleep(30)',
            )
            holder_proc = subprocess.Popen(
                [sys.executable, '-c', holder],
                env={**os.environ, 'MAIL_PROXY_LOCK_FILE': lock_path},
            )
            try:
                time.sleep(5)
                dup = _load_daemon_via_c(
                    f'mod.acquire_single_instance_lock({lock_path!r})',
                )
                dup_proc = subprocess.run(
                    [sys.executable, '-c', dup],
                    env={**os.environ, 'MAIL_PROXY_LOCK_FILE': lock_path},
                    capture_output=True,
                    text=True,
                )
                self.assertEqual(dup_proc.returncode, 75, dup_proc.stderr + dup_proc.stdout)
            finally:
                holder_proc.terminate()
                holder_proc.wait(timeout=10)

    def test_lock_released_after_holder_exits(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            lock_path = os.path.join(tmp, 'mail-proxy.lock')
            holder = _load_daemon_via_c(
                f'mod.acquire_single_instance_lock({lock_path!r})',
            )
            subprocess.run(
                [sys.executable, '-c', holder],
                env={**os.environ, 'MAIL_PROXY_LOCK_FILE': lock_path},
                check=True,
            )
            spec = importlib.util.spec_from_file_location('mail_proxy_daemon', DAEMON_PY)
            mod = importlib.util.module_from_spec(spec)
            assert spec.loader is not None
            spec.loader.exec_module(mod)
            fd = mod.acquire_single_instance_lock(lock_path)
            self.assertGreater(fd, 0)
            os.close(fd)

    def test_unrelated_lock_error_exits_1_not_75(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            blocker = os.path.join(tmp, 'blocker')
            with open(blocker, 'w', encoding='ascii'):
                pass
            lock_path = os.path.join(blocker, 'mail-proxy.lock')
            script = _load_daemon_via_c(
                f'mod.acquire_single_instance_lock({lock_path!r})',
            )
            proc = subprocess.run(
                [sys.executable, '-c', script],
                env={**os.environ, 'MAIL_PROXY_LOCK_FILE': lock_path},
                capture_output=True,
                text=True,
            )
            self.assertEqual(proc.returncode, 1)
            self.assertNotEqual(proc.returncode, 75)


if __name__ == '__main__':
    unittest.main()
