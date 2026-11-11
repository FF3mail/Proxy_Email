#!/usr/bin/env python3
"""Issue #160 — nginx -t capture uses mktemp, not a fixed /tmp path."""

from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
VERIFY = ROOT / "scripts" / "verify-install-regression.sh"

NGINX_BLOCK_RE = re.compile(
    r'NGINX_TEST_LOG="\$\(mktemp\)".*?trap - EXIT\b',
    re.S,
)


class Issue160VerifyNginxMktempTests(unittest.TestCase):
    def test_no_fixed_verify_nginx_test_log_path(self) -> None:
        text = VERIFY.read_text(encoding="utf-8")
        self.assertNotIn("/tmp/verify-nginx-test.log", text)

    def test_nginx_block_uses_mktemp_trap_and_cleanup(self) -> None:
        text = VERIFY.read_text(encoding="utf-8")
        match = NGINX_BLOCK_RE.search(text)
        self.assertIsNotNone(match, "nginx mktemp block not found")
        block = match.group(0)
        self.assertIn("_nginx_test_log_cleanup", block)
        self.assertRegex(block, r"trap\s+'_nginx_test_log_cleanup'\s+EXIT\b")
        self.assertNotRegex(block, r"trap\s+'_nginx_test_log_cleanup'\s+EXIT\s+INT\s+TERM")
        self.assertIn('nginx -t >"$NGINX_TEST_LOG" 2>&1', block)
        self.assertIn('cat "$NGINX_TEST_LOG"', block)
        self.assertIn("_nginx_test_log_cleanup", block)


if __name__ == "__main__":
    unittest.main()
