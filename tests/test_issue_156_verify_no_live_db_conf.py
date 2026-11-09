#!/usr/bin/env python3
"""Issue #156 — verify-install-regression.sh must not mutate live db.conf."""

from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
VERIFY = ROOT / "scripts" / "verify-install-regression.sh"

CHANGE_ME_BLOCK_RE = re.compile(
    r"# CHANGE_ME placeholder rejection.*?\nfi\n",
    re.S,
)


class Issue156VerifyNoLiveDbConfTests(unittest.TestCase):
    def _change_me_block(self) -> str:
        text = VERIFY.read_text(encoding="utf-8")
        match = CHANGE_ME_BLOCK_RE.search(text)
        self.assertIsNotNone(match, "CHANGE_ME block not found")
        assert match is not None
        return match.group(0)

    def test_a_change_me_block_never_writes_live_db_conf(self) -> None:
        block = self._change_me_block()
        forbidden = (
            'cp "$scratch" "$DB_CONF"',
            'cp "${scratch}.real" "$DB_CONF"',
            'chmod 0640 "$DB_CONF"',
            'chown root:mail-proxy-crypto "$DB_CONF"',
            "systemctl restart mail-proxy.service",
        )
        for line in forbidden:
            with self.subTest(line=line):
                self.assertNotIn(line, block)

    def test_b_change_me_block_uses_private_harness_dir_and_traps(self) -> None:
        block = self._change_me_block()
        self.assertIn("umask 077", block)
        self.assertIn('mktemp -d', block)
        self.assertRegex(block, r"trap\s+'_harness_cleanup'\s+EXIT\s+INT\s+TERM")
        self.assertIn("${HARN_DIR}/db_change_me.conf", block)
        self.assertIn("mod.Database(conf_file=conf_path)", block)

    def test_c_preserves_change_me_pass_fail_messages(self) -> None:
        text = VERIFY.read_text(encoding="utf-8")
        self.assertIn('pass "daemon refuses CHANGE_ME placeholder"', text)
        self.assertIn("daemon did not refuse CHANGE_ME placeholder", text)


if __name__ == "__main__":
    unittest.main()
