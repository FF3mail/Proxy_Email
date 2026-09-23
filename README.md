# DELTA-transit (Proxy_Email)

Corporate mail proxy gateway between external IMAP/SMTP accounts and local referent Maildirs (iRedMail).

**Status:** Production Candidate / pilot  
**Source of truth:** [docs/DELTA-transit_anchor.md](docs/DELTA-transit_anchor.md)

## Architecture

- **Inbound:** external IMAP → daemon worker pool → local Postfix `:25`
- **Outbound:** Maildir watchdog → daemon worker pool → external SMTP

## Main components

| Component | Role |
|-----------|------|
| `mail-proxy-daemon.py` | Python daemon (IMAP poll, SMTP send, OAuth2 refresh) |
| `web/` | PHP admin panel (referents, external accounts, OAuth setup) |
| MariaDB (`schema.sql`) | Configuration and encrypted credentials |
| `mail-proxy.service` | systemd unit (`User=vmail`, hardened) |
| `delta-transit-install.sh` | Full installer (venv, nginx, web, DB, limits) |

## Quick start

1. Provision a base virtual-mailbox system (e.g. iRedMail) with test mailboxes.
2. Run [`delta-transit-install.sh`](delta-transit-install.sh) on the target host.
3. Apply limits: [`configure_limits.sh`](configure_limits.sh).
4. Follow the deployment checklist: [guide/10-deployment-checklist.md](docs/guide/10-deployment-checklist.md) (see [docs/README.md](docs/README.md) for the full doc map).

### Staging / integration host (Ubuntu 24.04, PHP 8.3)

Verified staging baseline: Ubuntu 24.04 LTS, PHP-FPM 8.3, MariaDB 10.11, existing Postfix/Dovecot/Nginx stack.

- **PHP-FPM version** is auto-detected from `/etc/php/*/fpm/php.ini` (minimum 8.1); scripts do not assume PHP 8.1.
- **PHP-FPM concurrency ceiling** defaults to `pm.max_children = 10` on staging-sized hosts (~8 GB RAM). Override before install/limits run: `export PHP_FPM_MAX_CHILDREN=15`.
- `memory_limit` remains the project default (`512M` per request ceiling); this is not a guaranteed RAM reservation.
- **Production sizing** (pool size, RAM, disk) must be evaluated separately — staging is not a capacity benchmark.
- **Panel URL** (`PARAM_APP_URL`) hostname must differ from Postfix `myhostname` (e.g. use `https://panel.mail.testvps.loc` when Postfix is `mail.testvps.loc`).
- Installers only adjust required limit parameters; they do not replace the existing mail stack wholesale.

## Documentation

Start here: **[docs/README.md](docs/README.md)** — index of operator guide, anchor (SoT), decisions, and historical material.

| Document | Purpose |
|----------|---------|
| [docs/README.md](docs/README.md) | Documentation index |
| [docs/guide/README.md](docs/guide/README.md) | Operator install/ops guide (10 chapters, Markdown) |
| [docs/guide/10-deployment-checklist.md](docs/guide/10-deployment-checklist.md) | Current deployment / pilot checklist |
| [docs/DELTA_transit_admin_guide.pdf](docs/DELTA_transit_admin_guide.pdf) | Formal admin reference (PDF) |
| [docs/DELTA-transit_anchor.md](docs/DELTA-transit_anchor.md) | Architecture, schema, security, production criteria (**SoT**) |
| [docs/decisions/](docs/decisions/) | ADRs and decision registers |
| [docs/reports/](docs/reports/) | Historical PROMPT closure reports (**not for ops**) |
| [docs/prompts/](docs/prompts/) | Historical AI prompts (**not for ops**) |
| [docs/Ckeck-list_00.md](docs/Ckeck-list_00.md) | Superseded pilot checklist (historical; see guide ch. 10) |

## Repository

https://github.com/FF3mail/Proxy_Email
