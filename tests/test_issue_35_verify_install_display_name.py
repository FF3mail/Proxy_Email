#!/usr/bin/env python3
"""Issue #35 — verify-install-regression.sh checks clients.display_name (DAT-24)."""

from __future__ import annotations

import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
VERIFY = ROOT / "scripts" / "verify-install-regression.sh"


class VerifyInstallDisplayNameTests(unittest.TestCase):
    def test_verify_script_checks_display_name_column(self) -> None:
        text = VERIFY.read_text(encoding="utf-8")
        self.assertIn("clients.display_name column present (DAT-24)", text)
        self.assertIn("SHOW COLUMNS FROM clients LIKE 'display_name'", text)


if __name__ == "__main__":
    unittest.main()
