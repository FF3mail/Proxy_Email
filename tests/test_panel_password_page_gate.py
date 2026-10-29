#!/usr/bin/env python3
"""Issue #138 — static scan: password-change gate on all authenticated panel entry points."""

from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
WEB = ROOT / "web"

# Files that may call requirePanelAdmin() without requirePanelAdminWithPasswordGate().
# index.php: requirePanelAdmin() + enforcePanelPasswordChangeRequired() with exemptions.
# auth.php / panel_operator_password.php: definitions and internal composition.
LEGITIMATE_REQUIRE_PANEL_ADMIN: dict[str, str] = {
    "includes/auth.php": "defines requirePanelAdmin(); requireMasterAdmin() delegates to it",
    "includes/panel_operator_password.php": "requirePanelAdminWithPasswordGate() calls requirePanelAdmin() internally",
    "index.php": "front controller: requirePanelAdmin() then enforcePanelPasswordChangeRequired() with exempt actions",
}

_INDEX_PASSWORD_EXEMPT = frozenset({"password_change", "password_change_submit", "logout"})


def _strip_php_comments(text: str) -> str:
    """Remove // and # line comments and /* */ blocks (approximate, for static scan)."""
    out: list[str] = []
    i = 0
    n = len(text)
    while i < n:
        if text.startswith("/*", i):
            end = text.find("*/", i + 2)
            if end == -1:
                break
            i = end + 2
            continue
        if text.startswith("//", i) or text[i : i + 1] == "#":
            while i < n and text[i] != "\n":
                i += 1
            continue
        out.append(text[i])
        i += 1
    return "".join(out)


def _relative_web_path(path: Path) -> str:
    return str(path.relative_to(WEB)).replace("\\", "/")


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
                _strip_php_comments(text),
                msg=f"{name} must not call bare requirePanelAdmin()",
            )

    def test_index_enforces_gate_with_exemptions(self) -> None:
        text = (WEB / "index.php").read_text(encoding="utf-8")
        code = _strip_php_comments(text)
        self.assertIn("enforcePanelPasswordChangeRequired();", code)
        self.assertIn("$passwordChangeExemptActions", code)
        for action in _INDEX_PASSWORD_EXEMPT:
            self.assertIn(f"'{action}'", code)

    def test_index_password_exemptions_are_only_expected_actions(self) -> None:
        text = (WEB / "index.php").read_text(encoding="utf-8")
        match = re.search(
            r"\$passwordChangeExemptActions\s*=\s*\[(?P<body>[^\]]+)\]",
            _strip_php_comments(text),
        )
        self.assertIsNotNone(match, "passwordChangeExemptActions array not found")
        body = match.group("body") if match else ""
        found = set(re.findall(r"'([^']+)'", body))
        self.assertEqual(
            found,
            set(_INDEX_PASSWORD_EXEMPT),
            msg="index.php must exempt only password_change, password_change_submit, logout",
        )

    def test_all_web_php_require_panel_admin_calls_are_gated_or_documented(self) -> None:
        violations: list[str] = []
        for path in sorted(WEB.rglob("*.php")):
            rel = _relative_web_path(path)
            code = _strip_php_comments(path.read_text(encoding="utf-8"))
            if "requirePanelAdmin();" not in code:
                continue
            if rel in LEGITIMATE_REQUIRE_PANEL_ADMIN:
                continue
            if "requirePanelAdminWithPasswordGate();" in code:
                continue
            violations.append(rel)

        self.assertEqual(
            violations,
            [],
            msg=(
                "Every web PHP entry point must use requirePanelAdminWithPasswordGate() "
                "or be listed in LEGITIMATE_REQUIRE_PANEL_ADMIN. Violations: "
                + ", ".join(violations)
            ),
        )

    def test_legitimate_exceptions_documented(self) -> None:
        """Ensure documented exceptions still contain requirePanelAdmin() in source."""
        for rel, _reason in LEGITIMATE_REQUIRE_PANEL_ADMIN.items():
            code = _strip_php_comments((WEB / rel).read_text(encoding="utf-8"))
            self.assertIn(
                "requirePanelAdmin",
                code,
                msg=f"documented exception {rel} should reference requirePanelAdmin",
            )

    def test_operator_password_helper_redirects_via_panel_action(self) -> None:
        text = (ROOT / "web/includes/panel_operator_password.php").read_text(encoding="utf-8")
        self.assertIn("redirectToPanelAction('password_change')", text)
        self.assertIn("function requirePanelAdminWithPasswordGate", text)

    def test_auth_provides_set_flash_for_standalone_gate(self) -> None:
        auth = (ROOT / "web/includes/auth.php").read_text(encoding="utf-8")
        self.assertIn("function setFlash", auth)
        self.assertIn("$_SESSION['flash']", auth)

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
