# PROMPT-43 — Automatic Referent Maildir Resolution

**Date:** 2026-09-08  
**Branch:** `prompt-43-referent-maildir-resolution` (from `master`)  
**Scope:** Web panel referent form, Maildir resolver, installer vmail-lookup provisioning

---

## 1. Current referent/mailbox workflow (before)

1. Administrator creates mailbox in iRedMail (e.g. `refloc1@testvps.loc`).
2. Administrator **SSHs to the server** and runs `doveadm mailbox path` or inspects `/var/vmail/vmail1/...` to find the hashed Maildir path.
3. In the panel (`referent_form`), administrator enters:
   - **Username** (display name)
   - **Local Inbox** — email address
   - **Local Outbox** — **manual absolute filesystem path** (required)
4. `handleReferentSave()` stored the path verbatim; no existence check against iRedMail.

**Root cause of manual workflow:** `local_outbox` was designed as administrator-supplied input; the panel had no resolver and docs instructed SSH/`doveadm` discovery (`docs/05-web-panel.md` §5.4).

---

## 2. Database representation

Table `referents` (`schema.sql`):

| Column | Role |
|--------|------|
| `username` | Display / internal name |
| `local_inbox` | Referent mailbox **email** (UNIQUE) |
| `local_outbox` | Absolute Maildir root path (UNIQUE) — used by `mail-proxy-daemon.py` watchdog |
| `active` | Enable flag |

**No schema migration.** `local_outbox` remains; it is now **populated automatically** from the resolver on create / email change.

---

## 3. Actual iRedMail Maildir layout (verified on lab VPS)

**Not** the naive `{domain}/{user}/Maildir` pattern.

iRedMail on `192.168.125.116` uses **hashed directory tiers** under storage node `vmail1`:

```text
/var/vmail/vmail1/<domain>/<c>/<l>/<i>/<mailbox-dir>/Maildir
```

Where `<c>/<l>/<i>` are the first three characters of the mailbox name (local part).

| Mailbox | Resolved Maildir |
|---------|------------------|
| `postmaster@testvps.loc` | `/var/vmail/vmail1/testvps.loc/p/o/s/postmaster/Maildir` |
| `refloc1@testvps.loc` | `/var/vmail/vmail1/testvps.loc/r/e/f/refloc1-2026.09.01.10.49.35/Maildir` |
| `clientloc1@testvps.loc` | `/var/vmail/vmail1/testvps.loc/c/l/i/clientloc1-2026.09.01.10.50.00/Maildir` |

Canonical source: `vmail.mailbox` columns `storagebasedirectory`, `storagenode`, `maildir`, `mailboxfolder`.

On this host `mailbox.username` stores the **full email address** (not local-part only); the resolver accepts both conventions.

---

## 4. Mail storage root — source of truth

| Item | Source |
|------|--------|
| Storage base | `vmail.mailbox.storagebasedirectory` → `/var/vmail` |
| Storage node | `vmail.mailbox.storagenode` → `vmail1` |
| Relative path | `vmail.mailbox.maildir` → e.g. `testvps.loc/p/o/s/postmaster/` |
| Maildir folder name | `vmail.mailbox.mailboxfolder` → `Maildir` |
| DB credentials | `/etc/mail-proxy/vmail-lookup.conf` (provisioned by installer from `/etc/postfix/mysql/virtual_mailbox_maps.cf`) |

**Why not filesystem scan from PHP?** `www-data` cannot traverse mailbox directories (`0700 vmail:vmail`) — by design (PROMPT-34 / security model). Lookup uses read-only vmail DB access instead.

**Daemon constant** `MAILDIR_BASE = '/var/vmail/vmail1'` in `mail-proxy-daemon.py` aligns with `storagenode=vmail1` but does not encode per-user hash paths — referent paths come from DB `local_outbox`.

---

## 5. Resolver design

**Function:** `resolveReferentMaildir(string $email): string` in `web/includes/maildir_resolver.php`

**Flow:**

```text
email input
    → normalizeReferentEmail() — FILTER_VALIDATE_EMAIL, reject / \ .. NUL
    → splitReferentMailboxEmail()
    → PDO query vmail.mailbox (+ active domain)
         match username = full email OR (username = local AND domain = domain)
    → buildMailboxPathFromRow()
         {storagebasedirectory}/{storagenode}/{maildir}/{mailboxfolder}
    → assertResolvedMaildirPath() — prefix under storage root, no traversal
    → return canonical path
```

**Panel integration (`handleReferentSave`):**

- Administrator enters **email only** (`local_inbox`).
- **New referent** or **email changed** → `resolveReferentMaildir()`.
- **Edit with unchanged email** → keep existing `local_outbox` (upgrade compatibility).
- Removed manual `local_outbox` form field; show resolved path read-only when editing.

**Installer:** `provision_vmail_lookup_config()` writes `/etc/mail-proxy/vmail-lookup.conf` (mode `0640`, group `mail-proxy-crypto`) from Postfix iRedMail map on first install/upgrade.

---

## 6. Security considerations

| Risk | Mitigation |
|------|------------|
| Path traversal via email | Email validation; reject `/`, `\`, `..`, NUL; constructed path prefix-checked under `{storagebasedirectory}/{storagenode}/` |
| Administrator path override | `local_outbox` no longer accepted from POST |
| www-data Maildir read | No filesystem access; DB lookup only |
| vmail DB credentials | `vmail-lookup.conf` readable only by `mail-proxy-crypto` (www-data member) |
| Alias as referent | Only `mailbox` rows with `enabledeliver=1`; aliases without mailbox fail cleanly |

---

## 7. Compatibility

| Scenario | Behaviour |
|----------|-----------|
| Existing referent, email unchanged | Keeps stored `local_outbox` |
| Existing referent, email changed | Re-resolves path |
| Fresh install with iRedMail | `vmail-lookup.conf` auto-provisioned |
| Host without iRedMail map | Resolver fails: «Не удалось определить расположение почтового хранилища» |
| `mail-proxy-daemon.py` | Unchanged — still uses `local_outbox` from DB |

---

## 8. Tests performed

Lab VPS `192.168.125.116`, PHP CLI tests after deploying `web/` and provisioning `vmail-lookup.conf`.

| Test | Expected | Result |
|------|----------|--------|
| A. `postmaster@testvps.loc` | Resolve to real Maildir | **PASS** |
| A. `refloc1@testvps.loc` | Resolve to hashed Maildir | **PASS** |
| B. Multiple mailboxes same domain | Different paths | **PASS** (postmaster vs refloc1 vs clientloc1) |
| C. `nobody@testvps.loc` | Clean error, no DB row | **PASS** |
| D. Invalid / traversal emails | Rejected | **PASS** |
| E. Existing referent unchanged email | Code path preserves path | **PASS** (code review + logic) |
| F. Installer validation | No validation-phase changes | N/A — panel-only; validation-only unaffected on properly configured host |

---

## 9. Test results (log excerpt)

```text
[PASS] postmaster@testvps.loc => /var/vmail/vmail1/testvps.loc/p/o/s/postmaster/Maildir
[PASS] refloc1@testvps.loc => /var/vmail/vmail1/testvps.loc/r/e/f/refloc1-2026.09.01.10.49.35/Maildir
[PASS] clientloc1@testvps.loc => /var/vmail/vmail1/testvps.loc/c/l/i/clientloc1-2026.09.01.10.50.00/Maildir
[PASS] missing mailbox => Ящик для nobody@testvps.loc не найден...
[PASS] invalid email => Указан некорректный email референта
[PASS] path traversal => Указан некорректный email референта
```

---

## 10. Files changed

| File | Change |
|------|--------|
| `web/includes/maildir_resolver.php` | **New** — resolver, validation, vmail DB lookup |
| `web/index.php` | Referent form UI; auto-resolve on save |
| `delta-transit-install.sh` | `provision_vmail_lookup_config()`, `WEB_FILES` entry |

---

## 11. Risk assessment

| Risk | Level | Notes |
|------|-------|-------|
| Wrong path for non-standard iRedMail | Low | Path built from same DB fields Postfix uses |
| `username` column format variance | Low | Query matches full email OR local+domain |
| Missing vmail-lookup.conf on manual deploy | Medium | Clear error; run installer Web phase or provision manually |
| Existing referents with wrong manual paths | Low | Preserved until email changed |

---

## 12. Recommendation for merge

**Merge PR** — removes SSH/manual path discovery for normal panel administration, uses deployed iRedMail configuration as source of truth, preserves schema and daemon behaviour, and passes lab VPS resolver tests.

**SSH/manual path discovery no longer required** for adding referents when iRedMail vmail DB is reachable via `vmail-lookup.conf`.
