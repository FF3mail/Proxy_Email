#!/usr/bin/env python3
"""PROMPT-78 — static checks for durable daemon log permission infrastructure."""

from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

SERVICE = (ROOT / 'mail-proxy.service').read_text(encoding='utf-8')
LOGROTATE = (ROOT / 'logrotate-mail-proxy').read_text(encoding='utf-8')
TMPFILES = (ROOT / 'tmpfiles.d-mail-proxy.conf').read_text(encoding='utf-8')
INSTALLER = (ROOT / 'delta-transit-install.sh').read_text(encoding='utf-8')
SETUP = (ROOT / 'mail-proxy-setup.sh').read_text(encoding='utf-8')


class LogPermissionsInfraTests(unittest.TestCase):
    def test_systemd_uses_tmpfiles_not_fragile_chown(self) -> None:
        self.assertIn('UMask=0027', SERVICE)
        self.assertIn(
            'ExecStartPre=+/usr/bin/systemd-tmpfiles --create /etc/tmpfiles.d/mail-proxy.conf',
            SERVICE,
        )
        self.assertNotIn('chown vmail:mail-proxy-logs', SERVICE)

    def test_tmpfiles_setgid_directory_and_log_files(self) -> None:
        self.assertIn('d /var/log/mail-proxy 2750 vmail mail-proxy-logs', TMPFILES)
        self.assertIn(
            'f /var/log/mail-proxy/mail-proxy-daemon.log 0640 vmail mail-proxy-logs',
            TMPFILES,
        )
        self.assertIn(
            'f /var/log/mail-proxy/web_admin.log 0660 vmail mail-proxy-logs',
            TMPFILES,
        )

    def test_logrotate_creates_mail_proxy_logs_group(self) -> None:
        self.assertRegex(
            LOGROTATE,
            r'create 0640 vmail mail-proxy-logs',
        )
        self.assertRegex(
            LOGROTATE,
            r'create 0660 vmail mail-proxy-logs',
        )
        self.assertNotRegex(LOGROTATE, r'create 0640 vmail vmail')
        self.assertNotIn('su vmail vmail', LOGROTATE)

    def test_installer_wires_tmpfiles_and_setgid(self) -> None:
        self.assertIn('tmpfiles.d-mail-proxy.conf', INSTALLER)
        self.assertIn('install -d -m 2750', INSTALLER)
        self.assertIn('systemd-tmpfiles --create', INSTALLER)

    def test_setup_script_setgid_and_tmpfiles(self) -> None:
        self.assertIn('install -d -m 2750', SETUP)
        self.assertIn('tmpfiles.d-mail-proxy.conf', SETUP)


if __name__ == '__main__':
    unittest.main()
