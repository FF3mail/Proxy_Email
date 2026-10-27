#!/usr/bin/env python3
"""Issue #134 — SEC-12 headers in the panel vhost; HSTS stays off (DEV-25 not closed)."""

from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
INSTALLER = ROOT / "delta-transit-install.sh"

_FUNC_RE = re.compile(r"(?m)^{name}\(\) \{{\n(?P<body>.*?)\n\}}")

REQUIRED_HEADERS = (
    'add_header X-Content-Type-Options "nosniff" always;',
    'add_header X-Frame-Options "DENY" always;',
    'add_header Referrer-Policy "same-origin" always;',
)

PRESERVED_LINES = (
    "listen 443 ssl;",
    "listen [::]:443 ssl;",
    "ssl_certificate     ${NGINX_SSL_CERT};",
    "ssl_certificate_key ${NGINX_SSL_KEY};",
    "allow 10.0.0.0/8;",
    "allow 172.16.0.0/12;",
    "allow 192.168.0.0/16;",
    "allow 127.0.0.1;",
    "allow ::1;",
    "deny all;",
    "client_max_body_size 210M;",
    "include snippets/fastcgi-php.conf;",
    "include fastcgi_params;",
)


def _read_installer() -> str:
    return INSTALLER.read_text(encoding="utf-8")


def _function_body(text: str, name: str) -> str:
    found = re.search(_FUNC_RE.pattern.format(name=name), text, re.S)
    if not found:
        raise AssertionError(f"function {name} not found")
    return found.group("body")


def _vhost_template(text: str | None = None) -> str:
    """Heredoc written to NGINX_SITE_AVAILABLE by create_mail_proxy_virtual_host.

    The vhost body contains a column-0 closing brace, so this slice stops at
    the next function instead of the first brace.
    """
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


def _server_and_locations(template: str) -> tuple[str, list[str]]:
    """Return the server block with location bodies removed, plus each location."""
    server = re.search(r"(?ms)^server \{\n(?P<body>.*)\n\}$", template)
    if not server:
        raise AssertionError("server block not found")
    body = server.group("body")
    locations = re.findall(r"(?ms)^    location\b.*?^    \}", body)
    if len(locations) != 3:
        raise AssertionError(f"expected 3 locations, found {len(locations)}")
    server_level = body
    for location in locations:
        server_level = server_level.replace(location, "", 1)
    return server_level, locations


class NginxSecurityHeaderTests(unittest.TestCase):
    def test_server_block_has_the_three_headers_once(self) -> None:
        template = _vhost_template()
        server_level, locations = _server_and_locations(template)
        for directive in REQUIRED_HEADERS:
            self.assertEqual(template.count(directive), 1, directive)
            self.assertEqual(server_level.count(directive), 1, directive)
            for location in locations:
                self.assertNotIn(directive, location)

    def test_nested_locations_do_not_override_add_header(self) -> None:
        _, locations = _server_and_locations(_vhost_template())
        for location in locations:
            headers = re.findall(r"(?m)^\s*add_header\b.*$", location)
            if not headers:
                continue
            for directive in REQUIRED_HEADERS:
                self.assertEqual(location.count(directive), 1, location)

    def test_no_hsts_and_no_extra_add_header(self) -> None:
        template = _vhost_template()
        self.assertNotIn("Strict-Transport-Security", template)
        self.assertNotIn("Strict-Transport-Security", _read_installer())
        add_headers = re.findall(r"(?m)^\s*add_header\b.*$", template)
        self.assertEqual(list(REQUIRED_HEADERS), [line.strip() for line in add_headers])

    def test_listen_acl_tls_and_body_limit_stay(self) -> None:
        template = _vhost_template()
        for line in PRESERVED_LINES:
            self.assertIn(line, template)
        self.assertIsNone(re.search(r"(?m)^\s*listen\s+80\b", template))
        self.assertIsNone(re.search(r"(?m)^\s*listen\s+\[::\]:80\b", template))
        self.assertEqual(template.count("server {"), 1)

    def test_conf_d_profile_is_body_size_only(self) -> None:
        body = _function_body(_read_installer(), "install_nginx_delta_profile")
        self.assertIn("client_max_body_size 210M;", body)
        self.assertNotIn("add_header", body)
        self.assertNotIn("Strict-Transport-Security", body)


if __name__ == "__main__":
    unittest.main()
