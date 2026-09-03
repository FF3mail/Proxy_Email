# PROMPT 20.1 — Base mail system verification report

**Project:** DELTA-transit (mail-proxy)  
**Host:** `192.168.125.116` (`mail.testvps.loc`)  
**Date:** 2026-09-03  
**Mode:** Infrastructure verification (Epic 0 backfill)

---

## 1. Base mail system presence check: **PASS** (already present)

| Evidence | Raw output |
|----------|------------|
| `/etc/iredmail-release` | `1.8.7 MARIADB edition.` (file present, mode 0644, dated 2026-09-01) |
| `vmail` user | `uid=2000(vmail) gid=2000(vmail) groups=2000(vmail),…` |
| `/var/vmail` tree | Present with `vmail1/`, `backup/`, `sieve/`, `public/`, `mlmmj/`, … |
| `postconf virtual_mailbox_maps` | `proxy:mysql:/etc/postfix/mysql/virtual_mailbox_maps.cf` |
| `postconf virtual_alias_maps` | `proxy:mysql:/etc/postfix/mysql/virtual_alias_maps.cf` (+ domain_alias / catchall maps) |
| MariaDB `vmail` database | Present; tables include `domain`, `mailbox`, `alias`, `forwardings`, … |

iRedMail was **not** reinstalled; the host already carried a complete MARIADB-edition install.

### Remediation applied during this PROMPT (mailbox usability)

Observed at verification time:

- `/var/vmail` was `drwxr-x--- root:root` (**0750**), so user `vmail` could not traverse into mailbox trees (`Permission denied … missing +x perm: /var/vmail`).
- DB rows existed for three mailboxes, but only `postmaster` had an on-disk Maildir; `clientloc1` and `refloc1` Maildirs were missing.

Infrastructure actions taken (no DELTA-transit repo edits; no www-data granted Maildir/vmail access):

1. Restored traverse on `/var/vmail` to **0755** (iRedMail-compatible layout: root-owned, world-traversable; mailbox dirs remain `vmail:vmail` 0700).
2. Created missing Maildir trees under `/var/vmail/vmail1/...` owned by `vmail:vmail`.

**Note for Epic A / installer:** `delta-transit-install.sh` contains `chmod o-rwx /var/vmail` (and `chmod 0750 /var/vmail` on create). On an iRedMail host where `/var/vmail` is `root:root`, that strips other-execute and **re-breaks** `vmail` traversal. Tracked as an installer/host drift item for PROMPT 22 / Epic F — not fixed in this PROMPT.

---

## 2. Test domain and mailbox(es)

| Address | Role | Active |
|---------|------|--------|
| `testvps.loc` | Test domain | 1 |
| `postmaster@testvps.loc` | Domain postmaster / local_inbox candidate | 1 |
| `clientloc1@testvps.loc` | Client mailbox fixture | 1 |
| `refloc1@testvps.loc` | Referent mailbox fixture | 1 |

Raw:

```
testvps.loc	1
clientloc1@testvps.loc	testvps.loc/c/l/i/clientloc1-2026.09.01.10.50.00/	/var/vmail	1
postmaster@testvps.loc	testvps.loc/p/o/s/postmaster/	/var/vmail	1
refloc1@testvps.loc	testvps.loc/r/e/f/refloc1-2026.09.01.10.49.35/	/var/vmail	1
```

(`storagenode=vmail1` → on-disk root under `/var/vmail/vmail1/…`)

---

## 3. Confirmed on-disk Maildir paths

Pattern: `/var/vmail/vmail1/<domain>/…/Maildir`

| Mailbox | Absolute Maildir path | Status after remediation |
|---------|----------------------|--------------------------|
| `clientloc1@testvps.loc` | `/var/vmail/vmail1/testvps.loc/c/l/i/clientloc1-2026.09.01.10.50.00/Maildir` | **PASS** — `vmail:vmail` mode `700`, `new/` present |
| `postmaster@testvps.loc` | `/var/vmail/vmail1/testvps.loc/p/o/s/postmaster/Maildir` | **PASS** — `vmail:vmail` mode `700`, `new/` present |
| `refloc1@testvps.loc` | `/var/vmail/vmail1/testvps.loc/r/e/f/refloc1-2026.09.01.10.49.35/Maildir` | **PASS** — `vmail:vmail` mode `700`, `new/` present |

These absolute paths are the values suitable for `referents.local_outbox`.

---

## 4. Hostname-collision check

| Item | Value |
|------|-------|
| Postfix `myhostname` | `mail.testvps.loc` |
| Panel URL candidate (`PARAM_APP_URL`) | `https://panel.mail.testvps.loc` |
| Panel hostname | `panel.mail.testvps.loc` |
| Result | **NO COLLISION** — panel hostname ≠ Postfix myhostname |

**Chosen non-colliding panel hostname:** `panel.mail.testvps.loc`

### Ports observed listening (context for step 6)

`25`, `465`, `587`, `993`, `995`, `80`, `443` on `0.0.0.0`; MariaDB `3306` on `127.0.0.1` only.

---

## 5. Go / no-go for Epic A (PROMPT 21 / 22)

### **GO** — proceed to PROMPT 21 / 22 on this host

Rationale: iRedMail 1.8.7 is present and verified; domain `testvps.loc` and three mailboxes exist; Maildir paths are real and `vmail`-owned after remediation; panel hostname does not collide with Postfix.

**Caveat:** Re-running `delta-transit-install.sh` may again apply `chmod o-rwx /var/vmail` and break mailbox access until permissions are restored. That drift must be recorded in the PROMPT 22 report; do not treat a green installer phase table as proof that Maildir remains usable.
