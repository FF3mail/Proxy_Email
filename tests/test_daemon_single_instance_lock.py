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
_LOCK_ENV = 'MAIL_PROXY_LOCK_FILE'


def _load_daemon_via_c(*extra_lines: str) -> str:
    lines = [
        'import importlib.util',
        'import os',
        'import sys',
        f'spec = importlib.util.spec_from_file_location("mail_proxy_daemon", {str(DAEMON_PY)!r})',
        'mod = importlib.util.module_from_spec(spec)',
        'spec.loader.exec_module(mod)',
        *extra_lines,
    ]
    return '\n'.join(lines) + '\n'


def _load_daemon_module() -> object:
    spec = importlib.util.spec_from_file_location('mail_proxy_daemon', DAEMON_PY)
    mod = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(mod)
    return mod


def _release_mod_lock(mod: object) -> None:
    fd = getattr(mod, '_instance_lock_fd', None)
    if fd is not None:
        try:
            os.close(fd)
        except OSError:
            pass
        mod._instance_lock_fd = None


class DaemonSingleInstanceLockTests(unittest.TestCase):
    def _set_lock_env(self, lock_path: str) -> None:
        prior = os.environ.get(_LOCK_ENV)
        os.environ[_LOCK_ENV] = lock_path

        def _restore_lock_env() -> None:
            if prior is None:
                os.environ.pop(_LOCK_ENV, None)
            else:
                os.environ[_LOCK_ENV] = prior

        self.addCleanup(_restore_lock_env)

    def _wait_for_holder_locked(self, proc: subprocess.Popen[str], timeout: float = 15.0) -> None:
        assert proc.stdout is not None
        deadline = time.monotonic() + timeout
        locked = False
        while time.monotonic() < deadline:
            line = proc.stdout.readline()
            if line.strip() == 'LOCKED':
                locked = True
                break
            if proc.poll() is not None:
                break
            if not line:
                time.sleep(0.05)
        self.assertTrue(
            locked,
            f'holder did not print LOCKED within {timeout}s (rc={proc.poll()})',
        )

    def test_systemd_unit_prevents_restart_on_lock_exit(self) -> None:
        self.assertIn('RestartPreventExitStatus=75', SERVICE)
        self.assertIn('Restart=on-failure', SERVICE)

    def test_exit_constant_matches_unit(self) -> None:
        mod = _load_daemon_module()
        self.assertEqual(mod.EXIT_LOCK_HELD, 75)

    def test_first_lock_acquisition_succeeds(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            lock_path = os.path.join(tmp, 'mail-proxy.lock')
            self._set_lock_env(lock_path)
            mod = _load_daemon_module()
            self.addCleanup(lambda: _release_mod_lock(mod))
            fd = mod.acquire_single_instance_lock(lock_path)
            self.assertGreater(fd, 0)

    def test_second_acquisition_exits_75(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            lock_path = os.path.join(tmp, 'mail-proxy.lock')
            holder = _load_daemon_via_c(
                f'mod.acquire_single_instance_lock({lock_path!r})',
                "print('LOCKED', flush=True)",
                'import time',
                'time.sleep(30)',
            )
            holder_proc = subprocess.Popen(
                [sys.executable, '-c', holder],
                env={**os.environ, _LOCK_ENV: lock_path},
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                text=True,
            )
            try:
                self._wait_for_holder_locked(holder_proc)
                dup = _load_daemon_via_c(
                    f'mod.acquire_single_instance_lock({lock_path!r})',
                )
                dup_proc = subprocess.run(
                    [sys.executable, '-c', dup],
                    env={**os.environ, _LOCK_ENV: lock_path},
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
                env={**os.environ, _LOCK_ENV: lock_path},
                check=True,
            )
            mod = _load_daemon_module()
            self.addCleanup(lambda: _release_mod_lock(mod))
            fd = mod.acquire_single_instance_lock(lock_path)
            self.assertGreater(fd, 0)

    def test_lock_path_is_directory_exits_1_not_75(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            lock_path = os.path.join(tmp, 'lockdir')
            os.mkdir(lock_path)
            script = _load_daemon_via_c(
                f'mod.acquire_single_instance_lock({lock_path!r})',
            )
            proc = subprocess.run(
                [sys.executable, '-c', script],
                env={**os.environ, _LOCK_ENV: lock_path},
                capture_output=True,
                text=True,
            )
            self.assertEqual(proc.returncode, 1)
            self.assertNotEqual(proc.returncode, 75)

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
                env={**os.environ, _LOCK_ENV: lock_path},
                capture_output=True,
                text=True,
            )
            self.assertEqual(proc.returncode, 1)
            self.assertNotEqual(proc.returncode, 75)


if __name__ == '__main__':
    unittest.main()
