#!/usr/bin/env python3
"""Issue #138 — static scan: password-change gate on all authenticated panel entry points."""

from __future__ import annotations

import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
WEB = ROOT / "web"


class PanelPasswordPageGateTests(unittest.TestCase):
    def test_standalone_php_pages_use_password_gate_helper(self) -> None:
        for name in ("logs.php", "monitor.php", "relationship-status.php"):
            text = (WEB / name).read_text(encoding="utf-8")
            self.assertIn(
                "requirePanelAdminWithPasswordGate();",
                text,
                msg=f"{name} must call requirePanelAdminWithPasswordGate()",
            )
            self.assertNotIn(
                "requirePanelAdmin();",
                text,
                msg=f"{name} must not call bare requirePanelAdmin()",
            )

    def test_index_enforces_gate_with_exemptions(self) -> None:
        text = (WEB / "index.php").read_text(encoding="utf-8")
        self.assertIn("enforcePanelPasswordChangeRequired();", text)
        self.assertIn("'password_change'", text)
        self.assertIn("'password_change_submit'", text)
        self.assertIn("'logout'", text)
        self.assertIn("$passwordChangeExemptActions", text)

    def test_operator_password_helper_redirects_via_panel_action(self) -> None:
        text = (ROOT / "web/includes/panel_operator_password.php").read_text(encoding="utf-8")
        self.assertIn("redirectToPanelAction('password_change')", text)
        self.assertIn("function requirePanelAdminWithPasswordGate", text)

    def test_reset_handler_requires_master(self) -> None:
        text = (ROOT / "web/includes/panel_operator_password.php").read_text(encoding="utf-8")
        body = text.split("function handleOperatorResetPassword", 1)[1].split("function ", 1)[0]
        self.assertIn("requireMasterAdmin();", body)

    def test_password_change_regenerates_session_on_success(self) -> None:
        text = (ROOT / "web/includes/panel_operator_password.php").read_text(encoding="utf-8")
        success = text.split("Panel password_change success", 1)[0]
        self.assertIn("session_regenerate_id(true);", success)


if __name__ == "__main__":
    unittest.main()
