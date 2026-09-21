# PROMPT-38 — Guide consistency pass completion

**Date:** 2026-09-08  
**Branch:** `prompt-37-sonnet-docs` (PR #5 fixup)  
**Scope:** `docs/guide/*.md` only — no script or PDF changes

**Supersedes:** [PROMPT-37-sonnet-guide-import.md](PROMPT-37-sonnet-guide-import.md) (import summary retained below; this report is the authoritative consistency audit).

---

## Method

All ten files under `docs/guide/` were read against the current tree on `prompt-37-sonnet-docs` (aligned with `master` post PR #2/#3):

- `delta-transit-install.sh`
- `configure_limits.sh`
- `verify-install-regression.sh`
- `mail-proxy-daemon.py`
- `web/` (PHP panel auth, allow-list, CSRF)

---

## Findings table

| File / section | What the doc said | What the code actually does | Resolution |
|----------------|-------------------|----------------------------|------------|
| `03-installation.md` §3.7 | Operator may leave `/var/vmail` as `root:root` (“инсталлятор не сломает доставку”) | `verify_vmail_ownership_before_harden()` calls `fatal()` — install **stops** at UsersGroups; later phases never run (`delta-transit-install.sh` ~1143) | **Fixed** — hard-stop remediation |
| `03-installation.md` §3.4 | Nginx TLS: “self-signed или существующий” only | PROMPT-31: interactive choice 1=self-signed, 2=existing paths, 3=certbot/Let's Encrypt when hostname has public IPv4 A record; non-TTY defaults without prompt | **Fixed** — three options in phase table |
| `03-installation.md` §3.6 | Manual Let's Encrypt replacement after install; no certbot path | Same as above — certbot integrated in `ask_tls_certificate_source()` / `configure_tls_certificate()` | **Fixed** — §3.6 TLS subsection |
| `07-troubleshooting.md` §7.7 | “Оставить root:root и пропустить hardening” | Same `fatal()` as §3.7 — no skip path | **Fixed** — aligned with §3.7 |
| `01-overview.md` §1.6 | Panel has **no** user table; access = IP allow-list only | `panel_admins` table + `requirePanelAdmin()` (PR #2); allow-list is layer 1 only | **Fixed** — two-layer model |
| `05-web-panel.md` §5.2 HTTPS | Self-signed only; manual production cert replacement | Installer offers certbot when DNS qualifies | **Fixed** — cross-link to §3.6 |
| `05-web-panel.md` §5.2 seed | Implied hash written during Preflight | Preflight **collects** credentials (`preflight_panel_master_readiness`); `seed_panel_master()` runs in **Database** phase | **Fixed** — Preflight vs Database wording |
| `02-requirements.md` §2.4 | Installer “refuses to change permissions” (soft) | `fatal()` — full install halt, not a skipped chmod | **Fixed** — explicit halt + link to §3.7 |
| `04-configuration.md` §4.4 | Schema lists mail tables only | `schema.sql` includes `panel_admins` | **Fixed** — table documented |
| `10-deployment-checklist.md` §5 | MariaDB tables list omits `panel_admins` | Table exists; §9 already checks master | **Fixed** — added to list |
| `07-troubleshooting.md` §7.6 | 403 = IP only | 403 is allow-list; LAN users still need login | **Fixed** — note on second layer |
| `03-installation.md` §3.5 vs PDF §4.1 | (cross-check requested) | See section below | **Consistent** — no doc change |
| `08-security.md` §8.5 | “доверенный TLS” without naming certbot | Certbot is installer path, not a separate security feature | **Deferred** — §3.6/§5.2 now cover TLS sources; §8.5 generic advice still valid |
| `06-operations.md` | `verify-install-regression.sh` after changes | Script checks service, nginx, db.conf, 210M body, `/var/vmail` ownership — matches | **OK** — no change |
| `09-backup-restore.md` | Critical objects list | Matches paths in installer and runtime | **OK** — no change |
| `01-overview.md` §1.3 | ~60 s IMAP poll, 20 workers, queues 5000/1000 | `IMAP_POLL_INTERVAL=60`, `IMAP_WORKER_COUNT=20`, queue sizes in `mail-proxy-daemon.py` | **OK** |
| `04-configuration.md` §4.6 | Daemon constants table | Matches `mail-proxy-daemon.py` defaults | **OK** |
| `05-web-panel.md` §5.2 | Login lockout “5 attempts / 15 min” | `PANEL_LOGIN_MAX_FAILURES=5`, `PANEL_LOGIN_WINDOW_SECONDS=900` in `web/includes/auth.php` | **OK** |
| `03-installation.md` §3.3 | Master prompts in Preflight; non-TTY abort | `preflight_panel_master_readiness()` + `abort_panel_master_no_tty()` | **OK** (PROMPT-37) |
| `03-installation.md` §3.5 | `configure_limits.sh` interactive steps | Lines 396–402: hidden MySQL password, mailbox list / `--all-referents`, `Домен:` | **OK** (PROMPT-37) |
| `verify-install-regression.sh` | Referenced in §3.6 / checklist | 8 checks including vmail ownership (PROMPT-34) — doc does not overclaim extra panel-auth checks | **OK** — script has no `panel_admins` check (by design) |

---

## §3.5 vs `DELTA_transit_admin_guide.pdf` §4.1 cross-check

**PDF source read:** `docs/DELTA_transit_admin_guide.pdf` (PROMPT-35 §4.1 text extracted; PDF rendering/Courier glyph issues out of scope per prompt).

| Topic | `docs/guide/03-installation.md` §3.5 | Admin guide PDF §4.1 | Verdict |
|-------|--------------------------------------|----------------------|---------|
| When prompts appear | After `max_allowed_packet`; script waits before Nginx/PHP-FPM | Same | **Match** |
| Prompt 1 | Hidden `MySQL root password:`; iRedMail/MariaDB root; `UPDATE vmail.mailbox` | Same | **Match** |
| Prompt 2 | `Email-адреса через запятую или --all-referents:` | Same | **Match** |
| `--all-referents` follow-up | `Домен:` → `LIKE '%@<domain>'` | Same (PDF shows glyph boxes for Cyrillic “Домен” — rendering only) | **Match (meaning)** |
| Quota value | `10240` MB = 10 GB in `vmail.mailbox.quota` | Same | **Match** |
| Result line | `Updated mailbox rows: N`; `N=0` troubleshooting | Same | **Match** |
| Automation | No flags; no pipe/cron | Same | **Match** |
| Placement | Under installation chapter | Under “Настройка лимитов” (section 4) | **Structural only** — operator guide vs admin PDF layout; no operational conflict |

**Conclusion:** Wording and semantics for `configure_limits.sh` interactive steps are **aligned**. An operator following either document gets the same sequence and constraints. The operator guide §3.5 is the preferred cross-link target from `04-configuration.md` §4.5.

---

## PROMPT-37 import (unchanged context)

Ten-chapter set under `docs/guide/` + `docs/guide/README.md`. Root `README.md` links to the operator guide index.

---

## Files changed in PROMPT-38

- `docs/guide/03-installation.md` — §3.4, §3.6, §3.7
- `docs/guide/01-overview.md` — §1.6
- `docs/guide/02-requirements.md` — §2.4
- `docs/guide/04-configuration.md` — §4.4
- `docs/guide/05-web-panel.md` — §5.2
- `docs/guide/07-troubleshooting.md` — §7.6, §7.7
- `docs/guide/10-deployment-checklist.md` — §5
- `docs/reports/PROMPT-38-guide-consistency-completion.md` — this report
- `docs/reports/PROMPT-37-sonnet-guide-import.md` — superseded pointer
