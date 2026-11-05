#!/usr/bin/env python3
"""Issue #142 — nginx login_submit limit_req and fastcgi client IP params in installer vhost."""

from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
INSTALLER = ROOT / "delta-transit-install.sh"

_FUNC_RE = re.compile(r"(?m)^{name}\(\) \{{\n(?P<body>.*?)\n\}}")


def _read_installer() -> str:
    return INSTALLER.read_text(encoding="utf-8")


def _function_body(text: str, name: str) -> str:
    found = re.search(_FUNC_RE.pattern.format(name=name), text, re.S)
    if not found:
        raise AssertionError(f"function {name} not found")
    return found.group("body")


def _vhost_template(text: str | None = None) -> str:
    source = _read_installer() if text is None else text
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


class NginxLoginFloodBoundsTests(unittest.TestCase):
    def _nginx_profile_heredoc(self) -> str:
        text = _read_installer()
        start = text.find("install_nginx_delta_profile() {")
        end = text.find("\nphase_nginx() {", start)
        self.assertGreater(end, start)
        match = re.search(
            r'cat > "\$NGINX_INCLUDE_CONF" <<\'EOF\'\n(?P<body>.*?)\nEOF\n',
            text[start:end],
            re.S,
        )
        self.assertIsNotNone(match, "nginx profile heredoc not found")
        return match.group("body") if match else ""

    def test_conf_d_has_login_submit_limit_zone(self) -> None:
        profile = self._nginx_profile_heredoc()
        self.assertIn('map $arg_action$request_method $panel_login_submit_limit_key', profile)
        self.assertIn('"login_submitPOST" $binary_remote_addr', profile)
        self.assertIn(
            "limit_req_zone $panel_login_submit_limit_key zone=panel_login_submit:10m rate=10r/m;",
            profile,
        )
        self.assertIn("limit_req_status 429", profile)

    def test_php_location_applies_limit_req_and_fastcgi_client_ip(self) -> None:
        template = _vhost_template()
        php_location = re.search(
            r"(?ms)^    location ~ \\\.php\\\$ \{\n(?P<body>.*?)^    \}",
            template,
        )
        self.assertIsNotNone(php_location, "php location not found")
        body = php_location.group("body") if php_location else ""
        self.assertIn("limit_req zone=panel_login_submit burst=5 nodelay", body)
        self.assertIn("fastcgi_param HTTP_X_REAL_IP \\$remote_addr", body)
        self.assertIn(
            "fastcgi_param HTTP_X_FORWARDED_FOR \\$proxy_add_x_forwarded_for",
            body,
        )


if __name__ == "__main__":
    unittest.main()
