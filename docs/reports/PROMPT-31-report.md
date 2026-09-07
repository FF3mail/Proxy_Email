# PROMPT-31 — Interactive TLS certificate source selection

**Project:** DELTA-transit (mail-proxy)  
**Branch:** `prompt-24-panel-auth`  
**Implementation commit:** `95d1b2edb6b758abf4c68c27bfe2ff428a47bda0` (`95d1b2e`)  
**Date:** 2026-09-04 (implementation); live check 2026-09-07 (PROMPT-32)

---

## Scope

Installer-only change in `delta-transit-install.sh`: interactive TLS source choice — self-signed (default), existing certificate paths, or certbot (when hostname resolves to a public IPv4). Non-TTY installs keep self-signed/existing defaults without prompting. Nginx allow-list unchanged.

---

## Implementation verification

| Check | Result |
|-------|--------|
| `bash -n delta-transit-install.sh` | **PASS** |
| `configure_tls_certificate` wired in `phase_nginx` | **PASS** |
| Summary block prints `SSL_MODE` / `SSL_CERTIFICATE` / `SSL_KEY` | **PASS** |

---

## Live validation (PROMPT-32)

Epic A lab host (`panel.mail.testvps.loc`, private IP only — certbot option correctly omitted):

| Path | Result |
|------|--------|
| **Self-signed** (default `1`, pseudo-TTY fresh install) | **PASS** — `SSL_MODE=self-signed`, certs under `/etc/ssl/certs|private/mail-proxy.*`, install completes |
| **Existing files** (`2`) | **Not exercised** — no separate live run on this host |
| **Certbot** (`3`) | **Not exercised** — hostname has no public A record; option omitted as designed |

Re-runs on an host with certs already present report `SSL_MODE=existing` (reuse default paths).

---

## Verdict

**GO for lab/staging path** — self-signed default and PROMPT-31 prompt flow verified on live VPS. Production operators should validate **existing-files** and **certbot** paths on a host with real DNS before relying on them in production.
