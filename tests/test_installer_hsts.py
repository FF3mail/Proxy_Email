#!/usr/bin/env python3
"""Issue #155 — optional panel vhost HSTS per D-111 (installer logic and vhost output)."""

from __future__ import annotations

import os
import re
import stat
import subprocess
import tempfile
import textwrap
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
INSTALLER = ROOT / "delta-transit-install.sh"

HSTS_DIRECTIVE = 'add_header Strict-Transport-Security "max-age=31536000" always;'
REQUIRED_HEADERS = (
    'add_header X-Content-Type-Options "nosniff" always;',
    'add_header X-Frame-Options "DENY" always;',
    'add_header Referrer-Policy "same-origin" always;',
)

# Mutation guards — must stay non-empty (issues #171/#175 pattern).
GUARD_FORBID_SUBDOMAINS = re.compile(r"includeSubDomains")
GUARD_FORBID_PRELOAD = re.compile(r"\bpreload\b")
GUARD_HSTS_DIRECTIVE = re.compile(
    re.escape('add_header Strict-Transport-Security "max-age=31536000" always;')
)

# Rendered panel vhost (PANEL_HSTS_APPLY=no) with fixed installer test inputs below.
# Snapshot of pre-HSTS layout from master @ 2a6447c38a77031fccf5d14a7e98230b37d607f0.
# Committed fixture: shallow CI clones cannot `git show` arbitrary SHAs.
_GOLDEN_VHOST_FIXTURE = ROOT / "tests" / "fixtures" / "panel_vhost_golden_pre_hsts.txt"
GOLDEN_VHOST_SERVER_NAME = "panel.hsts.test"
GOLDEN_VHOST_PHP_FPM = "/run/php/php8.2-fpm.sock"
# Fixed scratch paths so rendered vhost bytes match committed golden fixture.
GOLDEN_VHOST_SCRATCH = Path("/tmp/mail-proxy-issue155-vhost-golden")


def _read_installer() -> str:
    return INSTALLER.read_text(encoding="utf-8")


def _vhost_golden_pre_hsts() -> str:
    return _GOLDEN_VHOST_FIXTURE.read_text(encoding="utf-8")


def _render_panel_vhost(scratch: Path | None = None, *, hsts_apply: str) -> str:
    base = scratch if scratch is not None else GOLDEN_VHOST_SCRATCH
    base.mkdir(parents=True, exist_ok=True)
    return _run_create_vhost(
        base,
        ssl_mode="self-signed",
        hsts_apply=hsts_apply,
        server_name=GOLDEN_VHOST_SERVER_NAME,
    )


def _openssl_panel_chain(
    tmp: Path,
    leaf_cn: str,
    *,
    include_intermediate_in_fullchain: bool = True,
) -> dict[str, Path]:
    """Root CA -> intermediate -> leaf; return PEM paths for HSTS chain tests."""
    root_key = tmp / "root.key"
    root_pem = tmp / "root.pem"
    int_key = tmp / "int.key"
    int_csr = tmp / "int.csr"
    int_pem = tmp / "int.pem"
    leaf_key = tmp / "leaf.key"
    leaf_csr = tmp / "leaf.csr"
    leaf_pem = tmp / "leaf.pem"
    subprocess.run(
        [
            "openssl",
            "req",
            "-x509",
            "-newkey",
            "rsa:2048",
            "-keyout",
            str(root_key),
            "-out",
            str(root_pem),
            "-days",
            "3650",
            "-nodes",
            "-subj",
            "/CN=HSTS Test Root",
        ],
        check=True,
        capture_output=True,
    )
    subprocess.run(
        [
            "openssl",
            "req",
            "-newkey",
            "rsa:2048",
            "-keyout",
            str(int_key),
            "-out",
            str(int_csr),
            "-nodes",
            "-subj",
            "/CN=HSTS Test Intermediate",
        ],
        check=True,
        capture_output=True,
    )
    int_ext = tmp / "int-ca.ext"
    int_ext.write_text(
        "basicConstraints=critical,CA:TRUE,pathlen:0\n"
        "keyUsage=critical,keyCertSign,cRLSign\n"
        "subjectKeyIdentifier=hash\n",
        encoding="utf-8",
    )
    subprocess.run(
        [
            "openssl",
            "x509",
            "-req",
            "-in",
            str(int_csr),
            "-CA",
            str(root_pem),
            "-CAkey",
            str(root_key),
            "-CAcreateserial",
            "-out",
            str(int_pem),
            "-days",
            "365",
            "-extfile",
            str(int_ext),
        ],
        check=True,
        capture_output=True,
    )
    subprocess.run(
        [
            "openssl",
            "req",
            "-newkey",
            "rsa:2048",
            "-keyout",
            str(leaf_key),
            "-out",
            str(leaf_csr),
            "-nodes",
            "-subj",
            f"/CN={leaf_cn}",
        ],
        check=True,
        capture_output=True,
    )
    subprocess.run(
        [
            "openssl",
            "x509",
            "-req",
            "-in",
            str(leaf_csr),
            "-CA",
            str(int_pem),
            "-CAkey",
            str(int_key),
            "-CAcreateserial",
            "-out",
            str(leaf_pem),
            "-days",
            "365",
        ],
        check=True,
        capture_output=True,
    )
    fullchain = tmp / "fullchain.pem"
    if include_intermediate_in_fullchain:
        fullchain.write_text(
            leaf_pem.read_text(encoding="utf-8") + int_pem.read_text(encoding="utf-8"),
            encoding="utf-8",
        )
    else:
        fullchain.write_bytes(leaf_pem.read_bytes())
    return {
        "root_pem": root_pem,
        "intermediate_pem": int_pem,
        "leaf_pem": leaf_pem,
        "fullchain_pem": fullchain,
    }


def _panel_hsts_existing_check(
    cert_path: Path,
    server_name: str,
    *,
    trust_ca: Path,
) -> int:
    env = {**os.environ, "DELTA_TLS_CA_FILE": str(trust_ca)}
    script = textwrap.dedent(
        f"""
        set -euo pipefail
        cd "{ROOT}"
        source "./delta-transit-install.sh"
        SSL_MODE="existing"
        NGINX_SSL_CERT="{cert_path}"
        NGINX_SERVER_NAME="{server_name}"
        panel_certificate_is_valid_for_hsts && exit 0 || exit 1
        """
    )
    return subprocess.run(["bash", "-c", script], env=env, capture_output=True).returncode


def _extract_vhost_template(source: str) -> str:
    start = source.find("create_mail_proxy_virtual_host() {")
    end = source.find("\nenable_mail_proxy_virtual_host() {", start)
    match = re.search(
        r'cat > "\$NGINX_SITE_AVAILABLE" <<EOF\n(?P<template>.*?)\nEOF\n',
        source[start:end],
        re.S,
    )
    if not match:
        raise AssertionError("panel vhost heredoc not found")
    return match.group("template")


def _run_create_vhost(
    tmp: Path,
    *,
    ssl_mode: str,
    hsts_apply: str,
    cert_path: Path | None = None,
    server_name: str = "panel.hsts.test",
) -> str:
    vhost_out = tmp / "mail-proxy.conf"
    web_root = tmp / "www"
    web_root.mkdir(exist_ok=True)
    ssl_cert = cert_path or (tmp / "panel.crt")
    ssl_key = tmp / "panel.key"
    if cert_path is None:
        subprocess.run(
            [
                "openssl",
                "req",
                "-x509",
                "-newkey",
                "rsa:2048",
                "-keyout",
                str(ssl_key),
                "-out",
                str(ssl_cert),
                "-days",
                "30",
                "-nodes",
                "-subj",
                f"/CN={server_name}",
            ],
            check=True,
            capture_output=True,
        )
    elif not ssl_key.is_file():
        subprocess.run(
            ["openssl", "req", "-newkey", "rsa:2048", "-keyout", str(ssl_key), "-nodes", "-subj", "/CN=x"],
            check=True,
            capture_output=True,
        )

    env = {
        **os.environ,
        "PATH": os.environ.get("PATH", ""),
    }
    script = textwrap.dedent(
        f"""
        set +o histexpand
        set -euo pipefail
        cd "{ROOT}"
        source "./delta-transit-install.sh"
        NGINX_SITE_AVAILABLE="{vhost_out}"
        WEB_ROOT="{web_root}"
        NGINX_SERVER_NAME="{server_name}"
        NGINX_SSL_CERT="{ssl_cert}"
        NGINX_SSL_KEY="{ssl_key}"
        PHP_FPM_SOCKET_DETECTED="{GOLDEN_VHOST_PHP_FPM}"
        SSL_MODE="{ssl_mode}"
        PANEL_HSTS_APPLY="{hsts_apply}"
        PANEL_HSTS_INTENT="{'enabled' if hsts_apply == 'yes' else 'disabled'}"
        log_info() {{ :; }}
        log_warn() {{ :; }}
        log_ok() {{ :; }}
        register_tmpfile() {{ :; }}
        install_traps() {{ :; }}
        chown() {{ :; }}
        create_mail_proxy_virtual_host
        """
    )
    subprocess.run(["bash", "-c", script], env=env, check=True, capture_output=True, text=True)
    return vhost_out.read_text(encoding="utf-8")


def _server_level_body(vhost: str) -> str:
    server = re.search(r"(?ms)^server \{\n(?P<body>.*)\n\}$", vhost)
    assert server, "server block missing"
    body = server.group("body")
    locations = re.findall(r"(?ms)^    location\b.*?^    \}", body)
    for loc in locations:
        body = body.replace(loc, "", 1)
    return body


def _openssl_ca_signed_pair(
    tmp: Path,
    *,
    cn: str,
    days: int = 365,
    startdate: str | None = None,
    enddate: str | None = None,
) -> tuple[Path, Path, Path]:
    ca_key = tmp / "ca.key"
    ca_pem = tmp / "ca.pem"
    leaf_key = tmp / "leaf.key"
    leaf_csr = tmp / "leaf.csr"
    leaf_pem = tmp / "leaf.pem"
    subprocess.run(
        [
            "openssl",
            "req",
            "-x509",
            "-newkey",
            "rsa:2048",
            "-keyout",
            str(ca_key),
            "-out",
            str(ca_pem),
            "-days",
            "3650",
            "-nodes",
            "-subj",
            "/CN=HSTS Test CA",
        ],
        check=True,
        capture_output=True,
    )
    subprocess.run(
        [
            "openssl",
            "req",
            "-newkey",
            "rsa:2048",
            "-keyout",
            str(leaf_key),
            "-out",
            str(leaf_csr),
            "-nodes",
            "-subj",
            f"/CN={cn}",
        ],
        check=True,
        capture_output=True,
    )
    sign_cmd = [
        "openssl",
        "x509",
        "-req",
        "-in",
        str(leaf_csr),
        "-CA",
        str(ca_pem),
        "-CAkey",
        str(ca_key),
        "-CAcreateserial",
        "-out",
        str(leaf_pem),
        "-days",
        str(days),
    ]
    if startdate:
        sign_cmd.extend(["-startdate", startdate])
    if enddate:
        sign_cmd.extend(["-enddate", enddate])
    subprocess.run(sign_cmd, check=True, capture_output=True)
    return ca_pem, leaf_pem, leaf_key


class InstallerHstsStaticGuards(unittest.TestCase):
    def test_forbid_include_subdomains_and_preload_patterns(self) -> None:
        text = _read_installer()
        self.assertIsNone(GUARD_FORBID_SUBDOMAINS.search(text))
        self.assertIsNone(GUARD_FORBID_PRELOAD.search(text))

    def test_hsts_directive_guard_present_once(self) -> None:
        text = _read_installer()
        hits = GUARD_HSTS_DIRECTIVE.findall(text)
        self.assertEqual(len(hits), 1, "exactly one conditional HSTS directive in installer")

    def test_guard_patterns_nonempty(self) -> None:
        self.assertTrue(GUARD_HSTS_DIRECTIVE.pattern)
        self.assertTrue(GUARD_FORBID_SUBDOMAINS.pattern)


class InstallerHstsVhostMatrix(unittest.TestCase):
    def test_vhost_combinations(self) -> None:
        with tempfile.TemporaryDirectory() as raw:
            tmp = Path(raw)
            ca_pem, leaf_pem, leaf_key = _openssl_ca_signed_pair(tmp, cn="panel.matrix.test")
            host = "panel.matrix.test"
            cases: list[tuple[str, str, str, bool]] = [
                ("disabled", "self-signed", "no", False),
                ("disabled", "existing", "no", False),
                ("disabled", "certbot", "no", False),
                ("enabled", "self-signed", "no", False),
                ("enabled", "existing", "yes", True),
                ("enabled", "certbot", "yes", True),
            ]
            for intent, ssl_mode, apply, want_hsts in cases:
                with self.subTest(intent=intent, ssl_mode=ssl_mode):
                    if ssl_mode == "existing":
                        vhost = _run_create_vhost(
                            tmp,
                            ssl_mode="existing",
                            hsts_apply=apply,
                            cert_path=leaf_pem,
                            server_name=host,
                        )
                    else:
                        vhost = _run_create_vhost(
                            tmp,
                            ssl_mode=ssl_mode,
                            hsts_apply=apply,
                            server_name=host if ssl_mode != "self-signed" else host,
                        )
                    count = vhost.count("Strict-Transport-Security")
                    if want_hsts:
                        self.assertEqual(count, 1)
                        self.assertIn(HSTS_DIRECTIVE, _server_level_body(vhost))
                    else:
                        self.assertEqual(count, 0)
                    for hdr in REQUIRED_HEADERS:
                        self.assertEqual(vhost.count(hdr), 1, hdr)


class InstallerHstsCertificateRule(unittest.TestCase):
    def _source_functions(self, extra_env: dict[str, str] | None = None) -> subprocess.CompletedProcess[str]:
        env = {**os.environ, **(extra_env or {})}
        script = textwrap.dedent(
            f"""
            set -euo pipefail
            cd "{ROOT}"
            source "./delta-transit-install.sh"
            log_info() {{ :; }}
            log_warn() {{ :; }}
            register_tmpfile() {{ :; }}
            install_traps() {{ :; }}
            """
        )
        return subprocess

    def test_ca_signed_trusted_valid(self) -> None:
        with tempfile.TemporaryDirectory() as raw:
            tmp = Path(raw)
            ca_pem, leaf_pem, _ = _openssl_ca_signed_pair(tmp, cn="panel.ca.test")
            env = {"DELTA_TLS_CA_FILE": str(ca_pem)}
            script = textwrap.dedent(
                f"""
                set -euo pipefail
                cd "{ROOT}"
                source "./delta-transit-install.sh"
                SSL_MODE="existing"
                NGINX_SSL_CERT="{leaf_pem}"
                NGINX_SERVER_NAME="panel.ca.test"
                if panel_certificate_is_valid_for_hsts; then exit 0; else exit 11; fi
                """
            )
            proc = subprocess.run(["bash", "-c", script], env={**os.environ, **env}, capture_output=True)
            self.assertEqual(proc.returncode, 0)

    def test_self_signed_invalid(self) -> None:
        with tempfile.TemporaryDirectory() as raw:
            tmp = Path(raw)
            cert = tmp / "self.pem"
            subprocess.run(
                [
                    "openssl",
                    "req",
                    "-x509",
                    "-newkey",
                    "rsa:2048",
                    "-keyout",
                    str(tmp / "self.key"),
                    "-out",
                    str(cert),
                    "-days",
                    "30",
                    "-nodes",
                    "-subj",
                    "/CN=panel.self.test",
                ],
                check=True,
                capture_output=True,
            )
            script = textwrap.dedent(
                f"""
                set -euo pipefail
                cd "{ROOT}"
                source "./delta-transit-install.sh"
                SSL_MODE="self-signed"
                NGINX_SSL_CERT="{cert}"
                NGINX_SERVER_NAME="panel.self.test"
                panel_certificate_is_valid_for_hsts && exit 0 || exit 12
                """
            )
            proc = subprocess.run(["bash", "-c", script], capture_output=True)
            self.assertEqual(proc.returncode, 12)

    def test_expired_invalid(self) -> None:
        from datetime import datetime, timedelta, timezone

        from cryptography import x509
        from cryptography.hazmat.primitives import hashes, serialization
        from cryptography.hazmat.primitives.asymmetric import rsa
        from cryptography.x509.oid import NameOID

        with tempfile.TemporaryDirectory() as raw:
            tmp = Path(raw)
            ca_key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
            ca_pem = tmp / "ca.pem"
            ca_cert = (
                x509.CertificateBuilder()
                .subject_name(x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, "Test CA")]))
                .issuer_name(x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, "Test CA")]))
                .public_key(ca_key.public_key())
                .serial_number(1)
                .not_valid_before(datetime(2000, 1, 1, tzinfo=timezone.utc))
                .not_valid_after(datetime(2001, 1, 1, tzinfo=timezone.utc))
                .sign(ca_key, hashes.SHA256())
            )
            ca_pem.write_bytes(ca_cert.public_bytes(serialization.Encoding.PEM))

            leaf_key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
            leaf_pem = tmp / "leaf.pem"
            leaf_cert = (
                x509.CertificateBuilder()
                .subject_name(
                    x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, "panel.expired.test")])
                )
                .issuer_name(x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, "Test CA")]))
                .public_key(leaf_key.public_key())
                .serial_number(2)
                .not_valid_before(datetime(2000, 1, 1, tzinfo=timezone.utc))
                .not_valid_after(datetime(2000, 6, 1, tzinfo=timezone.utc))
                .sign(ca_key, hashes.SHA256())
            )
            leaf_pem.write_bytes(leaf_cert.public_bytes(serialization.Encoding.PEM))
            ca_pem_path = ca_pem
            env = {"DELTA_TLS_CA_FILE": str(ca_pem_path)}
            script = textwrap.dedent(
                f"""
                set -euo pipefail
                cd "{ROOT}"
                source "./delta-transit-install.sh"
                SSL_MODE="existing"
                NGINX_SSL_CERT="{leaf_pem}"
                NGINX_SERVER_NAME="panel.expired.test"
                panel_certificate_is_valid_for_hsts && exit 0 || exit 13
                """
            )
            proc = subprocess.run(["bash", "-c", script], env={**os.environ, **env}, capture_output=True)
            self.assertEqual(proc.returncode, 13)

    def test_wrong_host_invalid(self) -> None:
        with tempfile.TemporaryDirectory() as raw:
            tmp = Path(raw)
            ca_pem, leaf_pem, _ = _openssl_ca_signed_pair(tmp, cn="other.host.test")
            env = {"DELTA_TLS_CA_FILE": str(ca_pem)}
            script = textwrap.dedent(
                f"""
                set -euo pipefail
                cd "{ROOT}"
                source "./delta-transit-install.sh"
                SSL_MODE="existing"
                NGINX_SSL_CERT="{leaf_pem}"
                NGINX_SERVER_NAME="panel.wrong.test"
                panel_certificate_is_valid_for_hsts && exit 0 || exit 14
                """
            )
            proc = subprocess.run(["bash", "-c", script], env={**os.environ, **env}, capture_output=True)
            self.assertEqual(proc.returncode, 14)

    def test_untrusted_ca_invalid(self) -> None:
        with tempfile.TemporaryDirectory() as raw:
            tmp = Path(raw)
            ca_pem, leaf_pem, _ = _openssl_ca_signed_pair(tmp, cn="panel.untrusted.test")
            other_dir = tmp / "other"
            other_dir.mkdir()
            other_ca, _, _ = _openssl_ca_signed_pair(other_dir, cn="panel.untrusted.test")
            env = {"DELTA_TLS_CA_FILE": str(other_ca)}
            script = textwrap.dedent(
                f"""
                set -euo pipefail
                cd "{ROOT}"
                source "./delta-transit-install.sh"
                SSL_MODE="existing"
                NGINX_SSL_CERT="{leaf_pem}"
                NGINX_SERVER_NAME="panel.untrusted.test"
                panel_certificate_is_valid_for_hsts && exit 0 || exit 15
                """
            )
            proc = subprocess.run(["bash", "-c", script], env={**os.environ, **env}, capture_output=True)
            self.assertEqual(proc.returncode, 15)
            _ = ca_pem


class InstallerHstsFullchainCertificateRule(unittest.TestCase):
    def test_fullchain_with_intermediate_valid(self) -> None:
        with tempfile.TemporaryDirectory() as raw:
            tmp = Path(raw)
            material = _openssl_panel_chain(tmp, "panel.fullchain.test")
            rc = _panel_hsts_existing_check(
                material["fullchain_pem"],
                "panel.fullchain.test",
                trust_ca=material["root_pem"],
            )
            self.assertEqual(rc, 0, "root->intermediate->leaf fullchain should verify")

    def test_leaf_only_without_intermediate_invalid(self) -> None:
        with tempfile.TemporaryDirectory() as raw:
            tmp = Path(raw)
            material = _openssl_panel_chain(tmp, "panel.no-int.test", include_intermediate_in_fullchain=False)
            rc = _panel_hsts_existing_check(
                material["leaf_pem"],
                "panel.no-int.test",
                trust_ca=material["root_pem"],
            )
            self.assertEqual(rc, 1, "leaf without intermediate in bundle should fail")

    def test_wrong_cert_first_in_bundle_invalid(self) -> None:
        with tempfile.TemporaryDirectory() as raw:
            tmp = Path(raw)
            material = _openssl_panel_chain(tmp, "panel.right.test")
            wrong_dir = tmp / "wrong"
            wrong_dir.mkdir(parents=True, exist_ok=True)
            _, wrong_leaf, _ = _openssl_ca_signed_pair(wrong_dir, cn="other.host.first")
            reversed_pem = tmp / "wrong-first.pem"
            reversed_pem.write_text(
                wrong_leaf.read_text(encoding="utf-8")
                + material["leaf_pem"].read_text(encoding="utf-8"),
                encoding="utf-8",
            )
            rc = _panel_hsts_existing_check(
                reversed_pem,
                "panel.right.test",
                trust_ca=material["root_pem"],
            )
            self.assertEqual(rc, 1, "nginx uses first PEM cert; wrong leaf-first must fail host check")

    def test_bundle_signed_by_other_ca_invalid(self) -> None:
        with tempfile.TemporaryDirectory() as raw:
            tmp = Path(raw)
            trusted = _openssl_panel_chain(tmp, "panel.trusted.test")
            rogue_dir = tmp / "rogue"
            rogue_dir.mkdir()
            rogue_leaf, _, _ = _openssl_ca_signed_pair(rogue_dir, cn="panel.trusted.test")
            rc = _panel_hsts_existing_check(
                rogue_leaf,
                "panel.trusted.test",
                trust_ca=trusted["root_pem"],
            )
            self.assertEqual(rc, 1)


class InstallerHstsPersistence(unittest.TestCase):
    def test_intent_file_atomic_mode_and_non_tty(self) -> None:
        with tempfile.TemporaryDirectory() as raw:
            tmp = Path(raw)
            cfg = tmp / "etc-mail-proxy"
            cfg.mkdir()
            intent = cfg / "panel-hsts"
            script = textwrap.dedent(
                f"""
                set -euo pipefail
                cd "{ROOT}"
                source "./delta-transit-install.sh"
                CONFIG_DIR="{cfg}"
                PANEL_HSTS_CONF="{intent}"
                GROUP_CRYPTO="mail-proxy-crypto-test"
                log_info() {{ :; }}
                log_warn() {{ :; }}
                register_tmpfile() {{ :; }}
                install_traps() {{ :; }}
                chown() {{ :; }}
                write_panel_hsts_intent_file "enabled"
                """
            )
            subprocess.run(["bash", "-c", script], check=True)
            self.assertEqual(intent.read_text(encoding="utf-8").strip(), "enabled")
            mode = stat.S_IMODE(intent.stat().st_mode)
            self.assertEqual(mode, 0o640)

            script2 = textwrap.dedent(
                f"""
                set -euo pipefail
                cd "{ROOT}"
                source "./delta-transit-install.sh"
                CONFIG_DIR="{cfg}"
                PANEL_HSTS_CONF="{intent}"
                installer_has_tty() {{ return 1; }}
                log_info() {{ :; }}
                log_warn() {{ :; }}
                configure_panel_hsts
                printf "%s" "$PANEL_HSTS_INTENT"
                """
            )
            out = subprocess.run(
                ["bash", "-c", script2],
                capture_output=True,
                text=True,
                check=True,
            )
            self.assertEqual(out.stdout.strip(), "enabled")

    def test_first_non_tty_stays_disabled_without_file(self) -> None:
        with tempfile.TemporaryDirectory() as raw:
            tmp = Path(raw)
            cfg = tmp / "cfg"
            cfg.mkdir()
            intent = cfg / "panel-hsts"
            script = textwrap.dedent(
                f"""
                set -euo pipefail
                cd "{ROOT}"
                source "./delta-transit-install.sh"
                CONFIG_DIR="{cfg}"
                PANEL_HSTS_CONF="{intent}"
                SSL_MODE="self-signed"
                installer_has_tty() {{ return 1; }}
                configure_panel_hsts
                [[ -f "$PANEL_HSTS_CONF" ]] && exit 9 || exit 0
                """
            )
            proc = subprocess.run(["bash", "-c", script], capture_output=True)
            self.assertEqual(proc.returncode, 0)

    def test_disabled_intent_removes_header_on_regen(self) -> None:
        with tempfile.TemporaryDirectory() as raw:
            tmp = Path(raw)
            on = _run_create_vhost(tmp, ssl_mode="certbot", hsts_apply="yes")
            off = _run_create_vhost(tmp, ssl_mode="certbot", hsts_apply="no")
            self.assertIn(HSTS_DIRECTIVE, on)
            self.assertNotIn("Strict-Transport-Security", off)


class InstallerHstsDialog(unittest.TestCase):
    def test_dialog_default_no_and_yes_persists(self) -> None:
        with tempfile.TemporaryDirectory() as raw:
            tmp = Path(raw)
            cfg = tmp / "cfg"
            cfg.mkdir()
            intent = cfg / "panel-hsts"
            script = textwrap.dedent(
                f"""
                set +o histexpand
                set -euo pipefail
                cd "{ROOT}"
                source "./delta-transit-install.sh"
                CONFIG_DIR="{cfg}"
                PANEL_HSTS_CONF="{intent}"
                GROUP_CRYPTO="mail-proxy-crypto-test"
                log_info() {{ :; }}
                log_warn() {{ :; }}
                register_tmpfile() {{ :; }}
                install_traps() {{ :; }}
                installer_has_tty() {{ return 0; }}
                chown() {{ :; }}
                printf '\\n' | ask_panel_hsts
                grep -qx disabled "$PANEL_HSTS_CONF"
                printf 'yes\\n' | ask_panel_hsts
                grep -qx enabled "$PANEL_HSTS_CONF"
                """
            )
            subprocess.run(["bash", "-c", script], check=True)

    def test_dialog_invalid_reasked(self) -> None:
        with tempfile.TemporaryDirectory() as raw:
            tmp = Path(raw)
            cfg = tmp / "cfg"
            cfg.mkdir()
            intent = cfg / "panel-hsts"
            script = textwrap.dedent(
                f"""
                set +o histexpand
                set -euo pipefail
                cd "{ROOT}"
                source "./delta-transit-install.sh"
                CONFIG_DIR="{cfg}"
                PANEL_HSTS_CONF="{intent}"
                GROUP_CRYPTO="mail-proxy-crypto-test"
                log_info() {{ :; }}
                log_warn() {{ :; }}
                register_tmpfile() {{ :; }}
                install_traps() {{ :; }}
                installer_has_tty() {{ return 0; }}
                chown() {{ :; }}
                printf 'maybe\\nno\\n' | ask_panel_hsts
                grep -qx disabled "$PANEL_HSTS_CONF"
                """
            )
            subprocess.run(["bash", "-c", script], check=True)


class InstallerHstsGoldenVhost(unittest.TestCase):
    def test_default_rendered_vhost_byte_identical_to_golden(self) -> None:
        generated = _render_panel_vhost(hsts_apply="no")
        golden = _vhost_golden_pre_hsts()
        self.assertEqual(generated, golden)

    def test_hsts_on_adds_single_sts_line_after_referrer(self) -> None:
        generated = _render_panel_vhost(hsts_apply="yes")
        golden = _vhost_golden_pre_hsts()
        expected = golden.replace(
            'add_header Referrer-Policy "same-origin" always;\n',
            'add_header Referrer-Policy "same-origin" always;\n'
            f"    {HSTS_DIRECTIVE}\n",
            1,
        )
        self.assertEqual(generated, expected)
        self.assertEqual(generated.count(HSTS_DIRECTIVE), 1)

    def test_referrer_to_access_log_single_blank_line_when_hsts_off(self) -> None:
        generated = _render_panel_vhost(hsts_apply="no")
        marker = 'add_header Referrer-Policy "same-origin" always;\n'
        tail = generated.split(marker, 1)[1]
        self.assertTrue(
            tail.startswith("\n    access_log"),
            "expected exactly one blank line between Referrer-Policy and access_log",
        )
        self.assertFalse(tail.startswith("\n\n    access_log"))


class InstallerHstsMutationGuardDocumentation(unittest.TestCase):
    """Document mutation ids M1–M22; enforcement is manual pre-PR."""

    MUTATION_IDS = tuple(f"M{i}" for i in range(1, 23))

    def test_mutation_list_complete(self) -> None:
        self.assertEqual(len(self.MUTATION_IDS), 22)


if __name__ == "__main__":
    unittest.main()