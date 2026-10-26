#!/usr/bin/env python3
"""DEV-66 / Issue #124 — installer must ship the daemon's local import closure."""

from __future__ import annotations

import os
import re
import subprocess
import sys
import tempfile
import textwrap
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
INSTALLER = ROOT / "delta-transit-install.sh"

# Transitive local import closure of mail-proxy-daemon.py.
# referent_notify.py is required: the daemon imports mail_disposal at module
# level, and mail_disposal imports referent_notify at module level.
EXPECTED_RUNTIME_MODULES = (
    "./relationship_lookup.py",
    "./relationship_routing.py",
    "./message_rebuild.py",
    "./attachment_policy.py",
    "./mail_passage_journal.py",
    "./mail_disposal.py",
    "./referent_notify.py",
)

_ARRAY_RE = re.compile(
    r"(?m)^(?P<name>[A-Z_]+)=\(\n(?P<body>.*?)\n\)",
    re.S,
)
_FUNC_RE = re.compile(
    r"(?m)^{name}\(\) \{{\n(?P<body>.*?)\n\}}"
)


def _read_installer(text: str | None = None) -> str:
    if text is not None:
        return text
    return INSTALLER.read_text(encoding="utf-8")


def _array_body(text: str, name: str) -> str:
    for match in _ARRAY_RE.finditer(text):
        if match.group("name") == name:
            return match.group("body")
    raise AssertionError(f"array {name} not found")


def _function_body(text: str, name: str) -> str:
    match = _FUNC_RE.pattern.format(name=name)
    found = re.search(match, text, re.S)
    if not found:
        raise AssertionError(f"function {name} not found")
    return found.group("body")


def runtime_module_array(text: str | None = None) -> tuple[str, ...]:
    body = _array_body(_read_installer(text), "DAEMON_RUNTIME_MODULES")
    return tuple(re.findall(r'"(\./[^"]+)"', body))


def validation_includes_runtime_modules(text: str | None = None) -> bool:
    source = _read_installer(text)
    required = _array_body(source, "REQUIRED_FILES")
    return '"${DAEMON_RUNTIME_MODULES[@]}"' in required


def syntax_check_compiles_runtime_modules(text: str | None = None) -> bool:
    body = _function_body(_read_installer(text), "verify_daemon_syntax")
    return (
        'for mod in "${DAEMON_RUNTIME_MODULES[@]}"' in body
        and 'py_compile "${dest_dir}/$(basename "$mod")"' in body
    )


def copy_installs_runtime_modules(text: str | None = None) -> bool:
    body = _function_body(_read_installer(text), "install_daemon_binary")
    return (
        'for mod in "${DAEMON_RUNTIME_MODULES[@]}"' in body
        and 'cp "$mod" "$tmp"' in body
        and 'dirname "$DAEMON_TARGET"' in body
        and '$(basename "$mod")' in body
    )


def audit_checks_runtime_modules(text: str | None = None) -> bool:
    body = _function_body(_read_installer(text), "audit_required_files")
    return (
        'for mod in "${DAEMON_RUNTIME_MODULES[@]}"' in body
        and 'dirname "$DAEMON_TARGET"' in body
        and '$(basename "$mod")' in body
    )


def _drop_required_files_expansion(text: str) -> str:
    needle = '    "${DAEMON_RUNTIME_MODULES[@]}"\n'
    if needle not in text:
        raise AssertionError("REQUIRED_FILES expansion line missing")
    return text.replace(needle, "", 1)


def _drop_copy_loop(text: str) -> str:
    body = _function_body(text, "install_daemon_binary")
    loop = re.search(
        r"\n    dest_dir=\"\$\(dirname \"\$DAEMON_TARGET\"\)\"\n"
        r"    for mod in \"\$\{DAEMON_RUNTIME_MODULES\[@\]\}\"\n"
        r"    do\n"
        r"(?:        .*\n)*"
        r"    done",
        body,
    )
    if not loop:
        raise AssertionError("copy loop not found")
    return text.replace(loop.group(0), "", 1)


class InstallerRuntimeModuleListTests(unittest.TestCase):
    def test_runtime_array_is_the_import_closure(self) -> None:
        self.assertEqual(runtime_module_array(), EXPECTED_RUNTIME_MODULES)

    def test_required_file_validation_includes_every_runtime_module(self) -> None:
        self.assertTrue(validation_includes_runtime_modules())
        self.assertIn("./referent_notify.py", runtime_module_array())

    def test_copy_logic_installs_every_runtime_module(self) -> None:
        self.assertTrue(copy_installs_runtime_modules())
        self.assertTrue(syntax_check_compiles_runtime_modules())

    def test_post_install_audit_requires_installed_modules(self) -> None:
        self.assertTrue(audit_checks_runtime_modules())

    def test_missing_validation_entry_is_detected(self) -> None:
        mutated = _drop_required_files_expansion(_read_installer())
        self.assertEqual(runtime_module_array(mutated), EXPECTED_RUNTIME_MODULES)
        self.assertFalse(validation_includes_runtime_modules(mutated))
        self.assertTrue(copy_installs_runtime_modules(mutated))

    def test_missing_copy_loop_is_detected(self) -> None:
        mutated = _drop_copy_loop(_read_installer())
        self.assertTrue(validation_includes_runtime_modules(mutated))
        self.assertFalse(copy_installs_runtime_modules(mutated))
        self.assertEqual(runtime_module_array(mutated), EXPECTED_RUNTIME_MODULES)

    def test_module_removed_from_the_shared_list_is_detected(self) -> None:
        mutated = _read_installer().replace('    "./referent_notify.py"\n', "", 1)
        self.assertNotIn("./referent_notify.py", runtime_module_array(mutated))
        self.assertNotEqual(runtime_module_array(mutated), EXPECTED_RUNTIME_MODULES)


class StagedDaemonInstallTests(unittest.TestCase):
    def test_staged_install_resolves_local_imports_from_staged_directory(self) -> None:
        installer = _read_installer()
        self.assertTrue(installer.rstrip().endswith('main "$@"'))
        stripped = installer[: installer.rfind('main "$@"')]
        with tempfile.TemporaryDirectory(prefix="delta-issue-124-") as tmp:
            root = Path(tmp)
            dest = root / "usr" / "local" / "bin"
            dest.mkdir(parents=True)
            stub = root / "stub-bin"
            stub.mkdir()
            chown = stub / "chown"
            chown.write_text("#!/bin/sh\nexit 0\n", encoding="utf-8")
            chown.chmod(0o755)
            target = dest / "mail-proxy-daemon.py"
            runner = stripped + textwrap.dedent(
                f"""
                DAEMON_TARGET={_bash_quote(str(target))}
                install_daemon_binary
                """
            )
            env = os.environ.copy()
            env["PATH"] = str(stub) + os.pathsep + env.get("PATH", "")
            env.pop("PYTHONPATH", None)
            completed = subprocess.run(
                ["bash", "-c", runner],
                cwd=ROOT,
                env=env,
                text=True,
                capture_output=True,
                check=False,
            )
            self.assertEqual(
                completed.returncode,
                0,
                completed.stderr or completed.stdout,
            )
            installed = sorted(path.name for path in dest.iterdir() if path.is_file())
            expected_names = ["mail-proxy-daemon.py", *[
                Path(module).name for module in EXPECTED_RUNTIME_MODULES
            ]]
            self.assertEqual(installed, sorted(expected_names))

            compile_env = os.environ.copy()
            compile_env.pop("PYTHONPATH", None)
            compile_env["PYTHONSAFEPATH"] = "1"
            compiled = subprocess.run(
                [sys.executable, "-m", "py_compile", *[
                    str(dest / name) for name in expected_names
                ]],
                cwd=dest,
                env=compile_env,
                text=True,
                capture_output=True,
                check=False,
            )
            self.assertEqual(compiled.returncode, 0, compiled.stderr or compiled.stdout)

            imported = subprocess.run(
                [sys.executable, "-c", _STAGED_IMPORT_CHECK, str(dest), str(ROOT)],
                cwd=dest,
                env=compile_env,
                text=True,
                capture_output=True,
                check=False,
            )
            self.assertEqual(
                imported.returncode,
                0,
                imported.stderr or imported.stdout,
            )
            self.assertIn("STAGED_OK", imported.stdout)

    def test_require_file_rejects_a_missing_runtime_module(self) -> None:
        installer = _read_installer()
        self.assertTrue(installer.rstrip().endswith('main "$@"'))
        stripped = installer[: installer.rfind('main "$@"')]
        runner = stripped + textwrap.dedent(
            """
            for module in "${DAEMON_RUNTIME_MODULES[@]}"
            do
                require_file "$module"
            done
            printf '%s\\n' "${REQUIRED_FILES[@]}" > "$VALIDATION_LIST"
            work="$(mktemp -d)"
            for module in "${DAEMON_RUNTIME_MODULES[@]}"
            do
                if [[ "$(basename "$module")" == "referent_notify.py" ]]
                then
                    continue
                fi
                cp "$module" "$work/$(basename "$module")"
            done
            if ( cd "$work" && require_file ./referent_notify.py )
            then
                echo "require_file accepted a missing runtime module" >&2
                exit 3
            fi
            echo VALIDATION_OK
            """
        )
        with tempfile.TemporaryDirectory(prefix="delta-issue-124-validate-") as tmp:
            listing = Path(tmp) / "required-files.txt"
            env = os.environ.copy()
            env["VALIDATION_LIST"] = str(listing)
            completed = subprocess.run(
                ["bash", "-c", runner],
                cwd=ROOT,
                env=env,
                text=True,
                capture_output=True,
                check=False,
            )
            self.assertEqual(
                completed.returncode,
                0,
                completed.stderr or completed.stdout,
            )
            self.assertIn("VALIDATION_OK", completed.stdout)
            listed = listing.read_text(encoding="utf-8").splitlines()
            for module in EXPECTED_RUNTIME_MODULES:
                self.assertIn(module, listed)


def _bash_quote(value: str) -> str:
    return "'" + value.replace("'", "'\\''") + "'"


_STAGED_IMPORT_CHECK = r"""
import ast
import sys
from pathlib import Path

staged = Path(sys.argv[1]).resolve()
repo = Path(sys.argv[2]).resolve()
kept = []
for entry in sys.path:
    if not entry:
        continue
    resolved = Path(entry).resolve()
    if resolved == repo or repo in resolved.parents:
        continue
    if resolved == staged or staged in resolved.parents:
        continue
    kept.append(entry)
sys.path = [str(staged), *kept]

loaded_from = []
for name in (
    "relationship_lookup",
    "relationship_routing",
    "message_rebuild",
    "attachment_policy",
    "mail_passage_journal",
    "mail_disposal",
    "referent_notify",
):
    module = __import__(name)
    path = Path(module.__file__).resolve()
    if path.parent != staged:
        raise SystemExit(f"{name} loaded from {path}, not {staged}")
    loaded_from.append(f"{name}={path}")

tree = ast.parse((staged / "mail-proxy-daemon.py").read_text(encoding="utf-8"))
direct = []
for node in tree.body:
    if isinstance(node, ast.ImportFrom) and node.level == 0 and node.module:
        mod = sys.modules.get(node.module)
        if mod is None or not getattr(mod, "__file__", None):
            continue
        path = Path(mod.__file__).resolve()
        if path.parent == staged:
            direct.append(node.module)
expected_direct = {
    "relationship_lookup",
    "relationship_routing",
    "message_rebuild",
    "attachment_policy",
    "mail_passage_journal",
    "mail_disposal",
}
if set(direct) != expected_direct:
    raise SystemExit(f"daemon staged imports {sorted(set(direct))}")
disposal = ast.parse((staged / "mail_disposal.py").read_text(encoding="utf-8"))
transitive = [
    node.module
    for node in disposal.body
    if isinstance(node, ast.ImportFrom) and node.module == "referent_notify"
]
if transitive != ["referent_notify"]:
    raise SystemExit("mail_disposal did not import referent_notify from the staged file")
if Path(sys.modules["referent_notify"].__file__).resolve().parent != staged:
    raise SystemExit("referent_notify was not loaded from the staged directory")
print("STAGED_OK")
print("\n".join(loaded_from))
"""


if __name__ == "__main__":
    unittest.main()
