#!/usr/bin/env python3
"""Issue #142 — installer panel vhost passes client IP to PHP-FPM for getClientIp()."""

from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
INSTALLER = ROOT / "delta-transit-install.sh"


def _read_installer() -> str:
    return INSTALLER.read_text(encoding="utf-8")


def _vhost_template() -> str:
    source = _read_installer()
    start = source.find("create_mail_proxy_virtual_host() {")
    end = source.find("\nenable_mail_proxy_virtual_host() {", start)
    if start < 0 or end < 0:
        raise AssertionError("create_mail_proxy_virtual_host span not found")
    match = re.search(
        r'cat > "\$NGINX_SITE_AVAILABLE" <<EOF\n(?P<template>.*?)\nEOF\n',
        source[start:end],
        re.S,
    )
    if not match:
        raise AssertionError("panel vhost heredoc not found")
    return match.group("template")


class NginxPanelClientIpTests(unittest.TestCase):
    def test_conf_d_profile_has_no_login_limit_req(self) -> None:
        start = _read_installer().find("install_nginx_delta_profile() {")
        end = _read_installer().find("\nphase_nginx() {", start)
        profile = _read_installer()[start:end]
        self.assertNotIn("limit_req_zone", profile)
        self.assertNotIn("limit_req ", profile)

    def test_php_location_sets_fastcgi_client_ip_params(self) -> None:
        template = _vhost_template()
        php_location = re.search(
            r"(?ms)^    location ~ \\\.php\\\$ \{\n(?P<body>.*?)^    \}",
            template,
        )
        self.assertIsNotNone(php_location, "php location not found")
        body = php_location.group("body") if php_location else ""
        self.assertNotIn("limit_req", body)
        self.assertIn("fastcgi_param HTTP_X_REAL_IP \\$remote_addr", body)
        self.assertIn(
            "fastcgi_param HTTP_X_FORWARDED_FOR \\$proxy_add_x_forwarded_for",
            body,
        )


if __name__ == "__main__":
    unittest.main()
