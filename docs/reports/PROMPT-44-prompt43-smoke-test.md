# PROMPT-44 — Push executable-bit fix and PROMPT-43 smoke test

**Date:** 2026-09-08  
**Host:** `192.168.125.116` (`~/Proxy_Email`)  
**Final commit on `master`:** `7849786`

---

## 1. Repository state (before push)

| Check | Result |
|-------|--------|
| Branch | `master` |
| Local commit `7849786` | Present on VPS |
| Working tree | Clean (VPS) |
| `delta-transit-install.sh` index mode | `100755` |
| Backup branch `vps-backup-before-prompt43-merge` | Present |
| PROMPT-43 files | Present (`maildir_resolver.php`, panel changes, report) |

VPS `git push` had failed: HTTPS password auth not supported (no credential helper / `gh` on VPS).

---

## 2. GitHub push result

| Item | Result |
|------|--------|
| Auth method | Local `gh` CLI (keyring) — `FF3mail` account |
| Push type | Normal fast-forward |
| `origin/master` before | `a845e7e` |
| `origin/master` after | `7849786` |
| Commit preserved | `7849786` unchanged (mode-only: `100644` → `100755`) |

VPS synchronized after push: `git pull origin master` → `master...origin/master` aligned.

No `reset --hard`, `clean`, force-push, or amend used.

---

## 3. Executable-bit verification

```text
100755 c06314290130574d6b2c4210a2731c9fd7117109 0  delta-transit-install.sh
test -x delta-transit-install.sh  → OK (VPS)
```

---

## 4. PROMPT-43 smoke-test results

| Test | Description | Result |
|------|-------------|--------|
| **A** | Existing mailbox (`postmaster@testvps.loc`, `refloc1@testvps.loc`) | **PASS** — resolver returns hashed Maildir under `/var/vmail/vmail1/` |
| **A′** | Panel save simulation (create referent, auto `local_outbox`, delete) | **PASS** — `local_outbox=/var/vmail/vmail1/testvps.loc/r/e/f/refloc1-2026.09.01.10.49.35/Maildir` |
| **B** | Multiple mailboxes (`postmaster`, `refloc1`, `clientloc1`) | **PASS** — distinct hashed paths |
| **C** | Missing mailbox (`nobody@testvps.loc`) | **PASS** — clean Russian error, no path stored |
| **D** | Invalid / traversal emails | **PASS** — rejected |
| **E** | Existing referents | **N/A** — no pre-existing referents on host before test; create/delete cycle passed |
| **F** | Daemon contract | **PASS** — daemon queries `local_outbox`; `mail-proxy.service` active; no daemon code changes |

**SSH/manual Maildir path entry:** not required — administrator supplies email only.

**Note:** Initial validation run failed with `Placeholder APP_BASE_URL found` because a full `rsync web/` during smoke prep overwrote `/var/www/mail-proxy/config.php` with repo placeholder. Restored `APP_BASE_URL=https://panel.testvps.loc` from install marker; validation then passed. This is an operational test artifact, not a PROMPT-43 regression.

---

## 5. Validation result

After restoring deployed `config.php`:

```text
[OK] Validation phase completed
[OK] Validation-only run completed
```

Exit: **0**

---

## 6. Failures / limitations

| Issue | Severity | Status |
|-------|----------|--------|
| VPS cannot `git push` without credential setup | Operational | Resolved via local `gh` push |
| `rsync web/` overwrites deployed `config.php` | Test hygiene | Documented; restore before validation |
| No browser UI test | Coverage gap | Resolver + DB save simulation used instead |
| Second domain unavailable on lab host | Test B partial | Three mailboxes on `testvps.loc` verified |

---

## 7. Recommendation

**PROMPT-43: ACCEPTED** — Maildir resolution works against live iRedMail `vmail.mailbox` data; panel no longer requires manual path entry; validation passes on correctly configured host; executable-bit fix `7849786` is on `origin/master`.

---

## 8. Final repository state

| Check | Result |
|-------|--------|
| Branch | `master` |
| `HEAD` | `7849786` |
| `master == origin/master` | Yes |
| `delta-transit-install.sh` | `100755` |
