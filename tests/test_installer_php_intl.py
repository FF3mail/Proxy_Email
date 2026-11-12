#!/usr/bin/env python3
"""Issue #141 — installer requires php-intl for panel login throttle canonicalization."""

from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
INSTALLER = ROOT / "delta-transit-install.sh"
VERIFY = ROOT / "scripts" / "verify-install-regression.sh"


class InstallerPhpIntlTests(unittest.TestCase):
    def test_apt_packages_includes_php_intl(self) -> None:
        text = INSTALLER.read_text(encoding="utf-8")
        self.assertIn("php-intl", text)
        block = re.search(r"APT_PACKAGES=\(\n(?P<body>.*?\n)\)", text, re.S)
        self.assertIsNotNone(block)
        body = block.group("body") if block else ""
        self.assertIn("php-intl", body)
        php_cli = body.find("php-cli")
        php_intl = body.find("php-intl")
        self.assertGreater(php_intl, php_cli)

    def test_validate_php_intl_extensions_present(self) -> None:
        text = INSTALLER.read_text(encoding="utf-8")
        self.assertIn("validate_php_intl_extensions()", text)
        self.assertIn('php -m 2>/dev/null | grep -qx intl', text)
        self.assertIn("php_fpm_intl_fpm_sapi_has_intl", text)
        self.assertIn("php_fpm_intl_wait_panel_ready", text)

    def test_phase_packages_and_validation_call_intl_check(self) -> None:
        text = INSTALLER.read_text(encoding="utf-8")
        packages = re.search(
            r"phase_packages\(\) \{\n(?P<body>.*?)\n\}", text, re.S
        )
        self.assertIsNotNone(packages)
        self.assertIn("validate_php_intl_extensions", packages.group("body"))
        validation = re.search(
            r"phase_validation\(\) \{\n(?P<body>.*?)\n\}", text, re.S
        )
        self.assertIsNotNone(validation)
        self.assertIn("validate_php_intl_extensions", validation.group("body"))

    def test_verify_install_regression_checks_intl(self) -> None:
        text = VERIFY.read_text(encoding="utf-8")
        self.assertIn("php -m", text)
        self.assertIn("intl", text)
        self.assertIn("php_fpm_intl_fpm_sapi_has_intl", text)
        self.assertIn("php_fpm_intl_wait_fpm_binary_ready", text)
        self.assertNotIn('"${fpm_php}" -m', text)


if __name__ == "__main__":
    unittest.main()
