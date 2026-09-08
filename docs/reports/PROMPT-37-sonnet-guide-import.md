# PROMPT-37 — Sonnet operator guide import

**Date:** 2026-09-08  
**Branch:** `prompt-37-sonnet-docs`

## Imported

Ten-chapter markdown set committed under `docs/guide/`:

- `01-overview.md` … `10-deployment-checklist.md`
- `docs/guide/README.md` — operator guide index; distinguishes PDF admin reference and anchor doc

## Gaps fixed

| File | Change |
|------|--------|
| `03-installation.md` §3.3 | Panel master username/password prompts during install; non-TTY abort |
| `03-installation.md` §3.5 | Full `configure_limits.sh` interactive step (MySQL password, mailbox list / `--all-referents`, quota 10240 MB, no automation flags) |
| `03-installation.md` §3.6 | Panel check via login URL + master requirement |
| `04-configuration.md` §4.5 | Cross-link to §3.5 for mailbox quota prompts |
| `05-web-panel.md` §5.2 | Two-layer security (allow-list + login); master/admin roles; seed and manual hash remediation |
| `05-web-panel.md` §5.3 | Operator management URL for master |
| `08-security.md` §8.1, §8.5 | Panel auth in threat model; two-layer access section |
| `10-deployment-checklist.md` §9 | Master seed, login, `panel_admins` checks |

## Out of scope (unchanged)

No changes to `delta-transit-install.sh`, `configure_limits.sh`, `schema.sql`, `mail-proxy-daemon.py`, or `web/` behavior.

## Note on workspace copies

The original files under `docs/*.md` (outside `docs/guide/`) remain local/untracked duplicates; the versioned copy is **`docs/guide/`** only.
