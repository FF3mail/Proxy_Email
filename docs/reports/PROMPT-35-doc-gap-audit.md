# PROMPT-35 — Admin guide documentation gap audit

**Date:** 2026-09-08  
**Branch:** `prompt-35-doc-gaps`  
**Scope:** Documentation only (`configure_limits.sh` behavior unchanged)

---

## 1. PDF source discovery

The committed artifact `docs/DELTA_transit_admin_guide.pdf` (v3.3) was produced with **WeasyPrint 62.3** (per PDF `/Producer` metadata), but **no HTML/CSS source was present in the repository** — only the binary PDF existed on `master` (initial commit `3e2f0ec`).

For this prompt the maintainable source was **reconstructed** from the v3.3 PDF content and committed as:

| File | Role |
|------|------|
| `docs/src/DELTA_transit_admin_guide.html` | Authoritative markup |
| `docs/src/admin_guide.css` | Print stylesheet (WeasyPrint-compatible) |
| `docs/build_admin_guide.py` | Rebuild entry point (WeasyPrint preferred, xhtml2pdf fallback) |
| `docs/build_admin_guide.sh` | Thin shell wrapper |

Guide version bumped to **v3.4** (PROMPT 35 patch line added on title page).

---

## 2. Fixed in Section 4

**Section 4.1 — Интерактивный шаг: квоты почтовых ящиков (MariaDB)** documents:

| Topic | Documentation added |
|-------|---------------------|
| MariaDB root password prompt | Hidden `MySQL root password:` input; same credential as iRedMail/MariaDB setup; used for `UPDATE vmail.mailbox` |
| Mailbox selection | Comma-separated addresses **or** literal `--all-referents` |
| Domain follow-up | `Домен:` prompt when `--all-referents` is used; SQL `WHERE username LIKE '%@<domain>'` |
| Quota value | `quota = 10240` in `vmail.mailbox` — **megabytes (10 GB)** per iRedMail convention; distinct from Postfix/Nginx attachment limits |
| Automation | Explicit warning: no CLI flags; stdin piping / unattended runs not supported |

---

## 3. Other script vs. guide gaps (audit pass)

Scripts referenced in the admin guide were compared against actual `read -r*` prompts in code.

| Script | Guide section | Interactive prompts in code | Documented in guide (before PROMPT-35)? | Action |
|--------|---------------|----------------------------|----------------------------------------|--------|
| `configure_limits.sh` | §4 | `MySQL root password:`, mailbox list / `--all-referents`, `Домен:` | **No** (table only) | **Fixed** — §4.1 added |
| `delta-transit-install.sh` | §2.3 | Preflight: `Public URL`, `MariaDB root password`, `pip mirror`; on pip failure: menu `1/2/3`; Nginx phase: `Путь к сокету или TCP-адрес` if PHP-FPM socket not auto-detected | **No** (phase list only) | **Deferred** — §2.3 should gain a «Интерактивные запросы» subsection; out of scope here to avoid mixing install-doc expansion with limits fix |
| `mail-proxy-setup.sh` | §1.1 (mentioned) | None (`read` not used) | N/A | No gap |
| `verify-install-regression.sh` | Not in PDF (used post-install on VPS) | None | N/A | No gap |

**Reasoning for deferrals:** `delta-transit-install.sh` has five distinct prompt surfaces across preflight, pip retry, and PHP-FPM detection. Documenting them properly belongs in **Section 2** (installation), not Section 4, and would roughly double the §2.3 edit surface. Flagged for a follow-up prompt (suggested: PROMPT-36).

---

## 4. PDF rebuild verification

| Check | Result |
|-------|--------|
| Rebuilt from `docs/src/` | Yes |
| Page count | **10 pages** (was 9 in v3.3; +§4.1 and section 3.2/4 reflow on splice path) |
| Section 4.1 present | Yes — `MySQL root password`, `--all-referents`, `quota = 10240`, automation warning |
| Tables | Limits table and security checklist render (xhtml2pdf ignores `border-collapse`; borders still visible) |
| Build backend on dev host | WeasyPrint when Pango available; otherwise **splice**: v3.3 pages 1–6 + reportlab §3.2/§4 (incl. §4.1) + v3.3 pages 8–9 |

---

## 5. Out of scope (confirmed)

- No changes to `configure_limits.sh` logic, prompts, or non-interactive flags
- No changes under `web/` or panel-auth / vmail-ownership branches
