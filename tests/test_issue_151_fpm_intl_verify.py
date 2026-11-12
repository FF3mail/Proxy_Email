#!/usr/bin/env python3
"""Issue #151 — verify php-intl in PHP-FPM SAPI (FPM php.ini), not CLI php -m."""

from __future__ import annotations

import os
import re
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
VERIFY = ROOT / "scripts" / "verify-install-regression.sh"
LIB = ROOT / "scripts" / "php_fpm_intl_verify.sh"
INSTALLER = ROOT / "delta-transit-install.sh"
HARNESS = Path(__file__).resolve().parent / "harness" / "issue_151_fpm_intl_harness.sh"


class Issue151FpmIntlVerifyStructureTests(unittest.TestCase):
    def test_verify_sources_shared_library(self) -> None:
        text = VERIFY.read_text(encoding="utf-8")
        self.assertIn("php_fpm_intl_verify.sh", text)
        self.assertIn("php_fpm_intl_fpm_sapi_has_intl", text)
        self.assertNotRegex(
            text,
            r'fpm_php.*\|\s*grep -qx intl',
            "verify must not use CLI php -m as FPM SAPI check",
        )

    def test_verify_distinguishes_not_ready_and_missing_intl(self) -> None:
        text = VERIFY.read_text(encoding="utf-8")
        self.assertIn("not ready within", text)
        self.assertIn("FPM SAPI intl extension missing", text)

    def test_library_uses_fpm_php_ini_for_modules(self) -> None:
        text = LIB.read_text(encoding="utf-8")
        self.assertIn('"$php_bin" -c "$PHP_FPM_INI" -m', text)
        self.assertIn("php_fpm_intl_wait_panel_ready", text)

    def test_installer_validate_uses_fpm_ini_not_cli_minus_m(self) -> None:
        text = INSTALLER.read_text(encoding="utf-8")
        block = re.search(
            r"validate_php_intl_extensions\(\) \{\n(?P<body>.*?)\n\}",
            text,
            re.S,
        )
        self.assertIsNotNone(block)
        body = block.group("body")
        self.assertIn("php_fpm_intl_fpm_sapi_has_intl", body)
        self.assertNotIn('"${fpm_php}" -m', body)
        self.assertNotIn('"$fpm_php" -m', body)


class Issue151FpmIntlHarnessTests(unittest.TestCase):
    def test_harness_scenarios(self) -> None:
        env = os.environ.copy()
        proc = subprocess.run(
            ["bash", str(HARNESS)],
            cwd=ROOT,
            env=env,
            capture_output=True,
            text=True,
            check=False,
        )
        self.assertEqual(
            proc.returncode,
            0,
            f"harness failed:\nstdout={proc.stdout}\nstderr={proc.stderr}",
        )

    def test_mutation_old_cli_check_misses_fpm_only_gap(self) -> None:
        """CLI has intl but FPM php.ini does not — FPM ini check must fail; CLI -m would wrongly pass."""
        with tempfile.TemporaryDirectory() as tmp:
            bindir = Path(tmp) / "bin"
            bindir.mkdir()
            php_fake = bindir / "php8.3"
            php_fake.write_text(
                """#!/usr/bin/env bash
if [[ "${1:-}" == "-c" && "${2:-}" == *"/fpm/php.ini" ]]; then
  printf '%s\\n' mbstring
  exit 0
fi
if [[ "${1:-}" == "-m" ]]; then
  printf '%s\\n' intl mbstring
  exit 0
fi
exit 1
""",
                encoding="utf-8",
            )
            php_fake.chmod(0o755)

            lib_copy = Path(tmp) / "php_fpm_intl_verify.sh"
            lib_copy.write_text(LIB.read_text(encoding="utf-8"), encoding="utf-8")

            env = os.environ.copy()
            env["PATH"] = f"{bindir}:{env.get('PATH', '')}"
            env["PHP_FPM_VERSION"] = "8.3"
            env["PHP_FPM_INI"] = "/etc/php/8.3/fpm/php.ini"

            def run_has_intl(lib_path: Path) -> subprocess.CompletedProcess[str]:
                script = f"""
set -euo pipefail
source '{lib_path}'
export PHP_FPM_VERSION=8.3
export PHP_FPM_INI=/etc/php/8.3/fpm/php.ini
if php_fpm_intl_fpm_sapi_has_intl; then echo HAS; else echo MISSING; fi
"""
                return subprocess.run(
                    ["bash", "-c", script],
                    env=env,
                    capture_output=True,
                    text=True,
                    check=False,
                )

            proc_correct = run_has_intl(lib_copy)
            self.assertIn("MISSING", proc_correct.stdout)

            mutated = lib_copy.read_text(encoding="utf-8").replace(
                '"$php_bin" -c "$PHP_FPM_INI" -m 2>/dev/null',
                '"$php_bin" -m 2>/dev/null',
            )
            lib_copy.write_text(mutated, encoding="utf-8")
            proc_mutated = run_has_intl(lib_copy)
            self.assertIn(
                "HAS",
                proc_mutated.stdout,
                "mutated CLI -m check incorrectly passes when only CLI SAPI has intl",
            )


if __name__ == "__main__":
    unittest.main()
