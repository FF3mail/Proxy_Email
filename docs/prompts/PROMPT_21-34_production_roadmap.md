# DELTA-transit — Production Readiness Roadmap

**Status:** Draft — awaiting executor kickoff
**Date:** 2026-09-01
**Prepared for:** Cursor (GitHub-integrated coding agent)
**Repository:** `FF3mail/Proxy_Email` (branch `master`)
**Predecessor documents:** `PROMPT 01–09.txt`, `PROMPT_10-15_production_readiness.txt`,
`Prompt_16.md`–`Prompt_20.md`, `DELTA-transit_anchor.md` (v3.2)
**Successor numbering:** this document continues the existing prompt sequence,
starting at **PROMPT 21**. One prerequisite epic (Epic 0) was inserted after
this document's initial review and is numbered **PROMPT 20.1 / 20.2**,
following the project's own established convention for mid-sequence
insertions (see `PROMPT_10-15_production_readiness.txt`, which uses
`PROMPT 10.2` / `10.3` for the same reason).

---

## 0. Purpose of this document

This document is the execution plan that closes the gap between the current
state of the codebase (audited 2026-09-01) and the GO criteria already defined
in `docs/Ckeck-list_00.md` and `DELTA-transit_anchor.md` §11.

It is written for **Cursor**, operating against the GitHub repository, and
follows the same convention as prior prompt files in `docs/prompts/`:

- Each unit of work is a self-contained **PROMPT**, addressed to the executor.
- Each PROMPT states Context/Problem, Requirements, **HARD CONSTRAINTS**
  (the project's standing "do not" list, repeated in every prompt — this is
  intentional and must not be shortened or paraphrased away), Required
  behavior, and a mandatory Response Format.
- Prompts are grouped into **Epics**. Epics are ordered; within an epic,
  prompts are ordered. Epic 0 is a hard prerequisite for Epic A, and Epic A
  is a hard prerequisite for everything after it.

This roadmap does not replace `Ckeck-list_00.md`. It produces the code,
infrastructure, and evidence that let that checklist be filled in truthfully,
box by box.

---

## 1. Executor & workflow conventions (new — not in v3.2)

The single existing commit in `master` has an unfilled placeholder message
("аше сообщение о изменениях"). Starting with this roadmap, Cursor must
follow standard hygiene so that production sign-off has a real audit trail:

1. One branch per PROMPT: `prompt-21-<slug>`, `prompt-22-<slug>`, etc.
2. One PR per branch, opened against `master`, referencing the PROMPT number
   in the title and pasting the PROMPT's own "Response Format" answer into
   the PR description.
3. Commit messages: `[PROMPT-NN] <imperative summary>` — no placeholder text,
   no empty messages.
4. No PROMPT is merged by the executor itself. Cursor opens the PR and stops;
   a human (or a separate review pass) merges.
5. Every PROMPT in Epic A through Epic H is blocking for the "Критерии GO"
   section of `Ckeck-list_00.md`. Epic I (git hygiene) is non-blocking but
   required before handing the repository to a second developer.

---

## 2. Epics overview

| Epic | Title | Blocks GO? | Depends on |
|---|---|:---:|---|
| 0 | iRedMail base install & mailbox provisioning | ✅ | — |
| A | Test environment provisioning & minimum-requirements verification (MCP SSH) | ✅ | 0 |
| B | Web panel authentication | ✅ | A |
| C | TLS certificate hardening | ✅ | A |
| D | OAuth SSRF — close DNS-rebinding window (TOCTOU) | ✅ | A |
| E | IMAP memory footprint mitigation (P4) | ⚠️ conditional* | A |
| F | Installer preflight & PHP-FPM version consistency | ✅ | A |
| G | Documentation synchronization (anchor doc v3.2 → v4.0) | ✅ | B, C, D, E, F |
| H | Full deployment checklist execution & burn-in | ✅ | A–G |
| I | Git/GitHub workflow hygiene | ❌ | — |

\* Epic E blocks GO only if the target VPS (verified in Epic A) has less
headroom than the worst-case memory model in PROMPT 29 requires. If Epic A
confirms sufficient RAM, Epic E may ship post-pilot as a tracked follow-up.

---

## 3. Epic 0 — iRedMail base install & mailbox provisioning

**Goal:** close a dependency the codebase assumes but never states or
automates: `delta-transit-install.sh` tunes resource limits and deploys the
DELTA-transit application on top of an **already-existing virtual-mailbox
mail system** — it does not create mail domains, mailboxes, or Maildir
structure itself. Confirmed directly in code, not inferred from the anchor
document's wording alone:

- `mail-proxy-daemon.py`, `_setup_watchdog_for_referent()`:
  `maildir_new = Path(ref['local_outbox']) / 'new'` — `local_outbox` is
  consumed as an absolute filesystem Maildir path, not an email address,
  despite the web form labelling and typing the field as one (see PROMPT
  20.2 below).
- `mail-proxy-daemon.py`, `_resolve_local_recipients()` /
  `_stream_file_via_smtp()`: `referent_data['local_inbox']` is used directly
  as an SMTP `RCPT TO` address against the local Postfix on
  `127.0.0.1:25` — Postfix must already have a virtual alias/mailbox map
  that accepts and routes this address.
- `delta-transit-install.sh`, `check_iredmail_hostname_conflict()`: the
  installer explicitly checks the *existing* `postconf -h myhostname`
  against the panel's own vhost name, anticipating that Postfix is already
  configured by something else before this installer runs.
- `mail-proxy-daemon.py`, `MAILDIR_BASE = '/var/vmail/vmail1'`: matches
  iRedMail's default Maildir layout convention
  (`/var/vmail/vmail1/<domain>/<user>/Maildir/`), though this constant is
  currently unused elsewhere in the file (see PROMPT 20.2, item 4).
- Confirmed by exhaustive grep across `delta-transit-install.sh` and
  `mail-proxy-setup.sh`: neither script ever sets
  `virtual_mailbox_domains`, `virtual_mailbox_maps`, `virtual_alias_maps`,
  Dovecot `passdb`/`userdb`, or `mail_location` — the parameters that would
  actually create mail domains/mailboxes. Only size/connection **limits**
  are configured on top of an assumed-existing setup.

**Why this is Epic 0, not folded into Epic A:** Epic A's installer dry-run
(PROMPT 22) will appear to succeed — Postfix/Dovecot/Nginx/MariaDB/the
daemon will all start — even with no real mail domain configured, because
`delta-transit-install.sh` never checks for one. Any GO/NO-GO signal from
Epic A or Epic H is meaningless unless a referent's `local_inbox`/
`local_outbox` point at a mailbox that genuinely exists.

────────────────────────────────────────

### PROMPT 20.1 — Verify or install the base mail system before running the DELTA-transit installer

```
Project: DELTA-transit (mail-proxy)
Deliverable: infrastructure verification/installation report, not
             DELTA-transit application code.
Tooling: MCP SSH connector, target: the same test VPS designated for Epic A.
Sequencing: this PROMPT MUST complete before PROMPT 21/22 (Epic A) runs.
            Do NOT run delta-transit-install.sh before this PROMPT reports
            a base mail system is present and verified.

Context:

DELTA-transit's Postfix/Dovecot configuration work
(configure_dovecot_limits(), set_postfix_parameter(), etc. in
delta-transit-install.sh) only tunes limits on an existing virtual-mailbox
setup. It does not create mail domains, users, or Maildir structure. The
project's own anchor document names iRedMail as the reference
implementation for this layer ("...локальных Maildir референтов
(iRedMail)"). This PROMPT establishes that layer on the test VPS.

Task:

1. Check whether a virtual-mailbox mail system is already present on the
   test VPS. Evidence to look for, in order of reliability:
   - `/etc/iredmail-release` (or equivalent iRedMail marker file/package);
   - an existing `vmail` system user with a populated `/var/vmail` tree;
   - Postfix `virtual_mailbox_maps`/`virtual_alias_maps` already pointing
     at a MySQL/MariaDB-backed map (`postconf virtual_mailbox_maps
     virtual_alias_maps`);
   - a MariaDB database matching iRedMail's default schema naming
     (commonly `vmail`, with tables such as `domain`, `mailbox`, `alias`).
2. If NOT present: install iRedMail using **iRedMail's own official
   installer** (`iRedMail.sh`) for Ubuntu 24.04, following iRedMail's
   documented supported flow. Do NOT hand-roll a substitute
   virtual_mailbox_maps/passdb/userdb configuration as a shortcut — this
   is exactly the kind of "fix around it" the project's standing rules
   prohibit, and a hand-rolled substitute will not match what the anchor
   document and DELTA-transit's Maildir path assumptions expect.
3. After iRedMail (or the confirmed pre-existing system) is in place:
   create one test domain and at least two test mailboxes via iRedAdmin
   (or iRedMail's supported CLI/SQL tooling) — these become the values
   used for a test referent's `local_inbox` and the client's email in
   Epic A/PROMPT 22's dashboard verification pass.
4. Record the exact on-disk Maildir path produced for the test mailbox
   (expected pattern: `/var/vmail/vmail1/<domain>/<user>/Maildir`). This
   exact string is what will be entered into `referents.local_outbox` —
   confirm it is a real, existing, `vmail`-owned directory before handing
   off to Epic A.
5. Re-run `check_iredmail_hostname_conflict()`'s logic manually (or note
   that PROMPT 22 will exercise it): confirm the intended DELTA-transit
   web panel hostname (`PARAM_APP_URL`) differs from the mail system's
   `postconf -h myhostname` — if they collide, choose a different vhost
   name for the panel before proceeding, do not modify Postfix's hostname
   to work around it.
6. Confirm firewall/security-group rules allow whatever ports the base
   mail system needs (this may extend the port list already discussed:
   25 inbound if the base system is meant to receive real internet mail
   directly, in addition to the outbound 25/587 already flagged for
   DELTA-transit's own external-account polling).

HARD CONSTRAINTS (standing project rules — always apply):

1. Do NOT modify any file inside the DELTA-transit repository as part of
   this PROMPT — this is infrastructure-only, exactly like PROMPT 21.
2. Do NOT run delta-transit-install.sh before this PROMPT's verification
   is complete and reported.
3. Do NOT hand-roll Postfix virtual_mailbox_maps/Dovecot passdb/userdb as
   a substitute for a real iRedMail (or equivalent) installation.
4. Do NOT weaken or disable any security feature of the base mail system
   to make DELTA-transit's later installation "easier" (e.g. do not
   disable TLS requirements, do not open relay).
5. Do NOT create the vmail system user manually if iRedMail's installer
   will create it — let iRedMail own that step; delta-transit-install.sh's
   ensure_vmail_user_exists() is idempotent and will detect it later.

Response format:

1. Base mail system presence check: PASS (already present, with evidence)
   or INSTALLED (iRedMail install transcript).
2. Test domain and mailbox(es) created — names/addresses (redact real
   secrets, but the domain/mailbox names themselves are test fixtures and
   may be reported plainly).
3. Confirmed on-disk Maildir path format for the test mailbox.
4. Hostname-collision check result (PARAM_APP_URL candidate vs. Postfix
   myhostname) and the chosen non-colliding panel hostname.
5. Explicit go/no-go to proceed to Epic A (PROMPT 21/22) on this host.
```

────────────────────────────────────────

### PROMPT 20.2 — Fix the `local_outbox` field type mismatch and document the mailbox-provisioning dependency

```
Project: DELTA-transit (mail-proxy)
Files: web/index.php (renderReferentForm(), handleReferentSave()),
       docs/DELTA-transit_anchor.md (documentation note only — full sync is
       PROMPT 30/Epic G; this PROMPT adds a minimal, accurate note now so
       the dependency found in Epic 0 is not lost before Epic G runs).

Context:

renderReferentForm() renders the "Local Outbox" field as
`<input type="email" name="local_outbox" ...>`, but
_setup_watchdog_for_referent() in mail-proxy-daemon.py consumes
ref['local_outbox'] as `Path(ref['local_outbox']) / 'new'` — an absolute
filesystem path to a Maildir root, not an email address. Browsers
performing standard HTML5 client-side validation on an `type="email"`
field will reject a valid Maildir path (e.g.
`/var/vmail/vmail1/example.com/r1/Maildir`) as malformed input, blocking
legitimate administrator entry. `local_inbox`, by contrast, genuinely is
used as an email address (SMTP RCPT TO in _resolve_local_recipients()) and
its `type="email"` is correct — do not touch that field.

Requirements:

1. Change the `local_outbox` input's `type` from `email` to `text` in
   renderReferentForm() (verify there is only one occurrence in the
   create/edit form; the same form is reused for both per the existing
   code structure).
2. Add a `placeholder` attribute and adjacent help text clarifying the
   expected value is an absolute filesystem path to the referent's Maildir
   root as provisioned by the base mail system (e.g. iRedMail), for
   example: `placeholder="/var/vmail/vmail1/example.com/username/Maildir"`.
3. Add minimal server-side validation in handleReferentSave() for
   local_outbox: reject empty values, reject values not starting with `/`
   (not an absolute path), reject values containing `..` path-traversal
   segments or embedded null bytes. Do NOT attempt to verify the path
   exists on disk from the PHP web panel — the panel process may not have
   filesystem access to /var/vmail, or may run on a host separate from the
   daemon; existence is confirmed operationally in Epic 0/PROMPT 20.1 and
   at daemon runtime (_setup_watchdog_for_referent() already logs an error
   and returns if the path cannot be created/accessed — do not duplicate
   or weaken that runtime check).
4. Add a short, accurate note to DELTA-transit_anchor.md (a new bullet
   under the existing "Ключевые классы демона" area or wherever the
   current document best fits it) stating plainly: `referents.local_outbox`
   is a filesystem Maildir path, not an email address, and must correspond
   to a mailbox already provisioned by the base mail system (iRedMail or
   equivalent) — this is a minimal, factual addition, not a rewrite; Epic
   G/PROMPT 30 will fold it into the full v4.0 sync later.

HARD CONSTRAINTS:

1. Do NOT change the `local_inbox` field — it is correctly typed and used
   as a genuine email address.
2. Do NOT change the `referents` table schema.
3. Do NOT change handleReferentSave()'s existing transaction structure,
   logging calls, or CSRF handling beyond adding the new validation check.
4. Do NOT attempt filesystem existence checks for local_outbox from PHP.
5. Do NOT rewrite DELTA-transit_anchor.md beyond the single accurate
   addition described in Requirement 4 — full synchronization is Epic
   G/PROMPT 30's job, sequenced after Epics B–F land.

Response format:

1. Unified diff, web/index.php.
2. Unified diff, docs/DELTA-transit_anchor.md (the single added note only).
3. Confirmation that the new validation rejects at least one malformed
   case (e.g. a relative path, an empty string, a path containing `..`)
   and accepts a valid absolute path.
4. Confirmation that no existing referent-save regression occurred (a
   referent with a previously-valid local_outbox value continues to save
   correctly).
```

---

## 4. Epic A — Test environment provisioning & minimum-requirements verification

**Goal:** stand up a disposable test VPS, connect to it over MCP SSH, and
mechanically verify it against the requirements the project *implies* but has
never written down or checked in code (`phase_preflight()` in
`delta-transit-install.sh` currently checks only for root, required files,
and a MySQL root password — it does not check OS, RAM, disk, or CPU).

**Why this is Epic A, not Epic Z:** every other epic in this roadmap will be
verified by Cursor running commands against this environment. Without it,
every later "Regression tests" section is a claim, not a result.

### Declared minimum requirements baseline (derived from the current codebase)

Cursor must verify each row below is genuinely met, not just plausible:

| Requirement | Source in repo | Verification command |
|---|---|---|
| OS: Debian 12 or Ubuntu 22.04/24.04 (apt-based) | `APT_PACKAGES` array, `delta-transit-install.sh` | `cat /etc/os-release` |
| Python ≥ 3.10 | `mail-proxy-daemon.py` typing/syntax (`str \| None` not used, but f-strings + `Path` usage assume ≥3.8; `cryptography>=42.0.0` requires ≥3.7) — set floor at 3.10 for safety margin | `python3 --version` |
| MariaDB server reachable on 127.0.0.1:3306 | `schema.sql`, `Database.__init__` in daemon, `getPdo()` in `helpers.php` | `mysqladmin ping` |
| PHP-FPM present, version pinned to what `configure_limits.sh` expects | `PHP_INI="/etc/php/8.1/fpm/php.ini"` in `configure_limits.sh` vs. `enable_services_if_present()` in installer, which tolerates 8.1/8.2/8.3 | `php -v`; `ls /etc/php/*/fpm/php.ini` |
| Nginx present | `create_mail_proxy_virtual_host()` | `nginx -v` |
| Postfix + Dovecot present | `mail-proxy.service` `After=`/`Requires=` | `postconf mail_version`; `doveconf -n \| head -1` |
| RAM sufficient for worst-case concurrency | `IMAP_WORKER_COUNT=20`, `SMTP_WORKER_COUNT=20`, `DB_POOL_SIZE=12`, PHP `memory_limit=512M`, attachment target 150 MB (see Epic E) | `free -m` — compare against PROMPT 21 formula below |
| Disk space for Maildir + logs + 30-day rotation | `logrotate-mail-proxy` (`rotate 30`), `mailbox_size_limit=300MB` per referent | `df -h /var/vmail /var/log` |
| Outbound HTTPS reachability to OAuth providers | `oauth_providers` seed rows (`accounts.google.com`, `oauth.yandex.ru`, `login.microsoftonline.com`) | `curl -sI https://oauth2.googleapis.com` etc. |
| systemd version supports all unit directives used | `mail-proxy.service` uses `ProtectProc`, `RestrictNamespaces`, `SystemCallFilter` (needs systemd ≥ 247) | `systemd-analyze --version` |

---

### PROMPT 21 — Provision and connect to the test VPS over MCP SSH

```
Project: DELTA-transit (mail-proxy)
Deliverable: infrastructure verification report, not application code.
Tooling: MCP SSH connector (executor-side), target: a disposable test VPS
         provided for this purpose. Do not target any host that is not
         explicitly designated as the DELTA-transit test environment.

Context:

The project has never been deployed and verified end-to-end. Before any
code-level fix in this roadmap is trusted, the executor must have a real
VPS reachable over SSH, and must confirm — mechanically, not by inspection
of the installer's intent — that the host satisfies the baseline in
Section 4 of PROMPT_21-34_production_roadmap.md.

Task:

1. Establish an MCP SSH session to the designated test VPS.
2. Run the verification command for every row in the "Declared minimum
   requirements baseline" table and capture raw output.
3. Do NOT install or modify anything on the host in this PROMPT. This is a
   read-only verification pass. Installation happens only in PROMPT 22,
   after the report from this PROMPT is reviewed.
4. Compute the RAM headroom check explicitly:
   - PHP-FPM pool: (pm.max_children, if already configured, else assume
     default) × php memory_limit (512M once configure_limits.sh has run;
     note current default if not yet run).
   - Daemon worst case: assume up to IMAP_WORKER_COUNT (20) concurrent
     poll_external_imap() calls each temporarily holding one full RFC822
     message in memory (see Epic E — this is the open P4 risk) at up to
     ~200 MB (150 MB attachment × ~1.33 base64 overhead) = worst-case
     ceiling of ~4 GB transient. Report this number explicitly next to the
     host's actual `free -m` output; do not silently round it down.
   - MariaDB: default `innodb_buffer_pool_size` × DB_POOL_SIZE=12
     connections' worth of per-connection buffers (report the config value
     found, do not assume a number).
5. If any requirement fails, do not attempt to "fix around" it in this
   PROMPT. Record the failure and stop.

HARD CONSTRAINTS (standing project rules — always apply):

1. Do NOT change the worker pool architecture (ImapWorkerPool/SmtpWorkerPool,
   max 20 IMAP + 20 SMTP workers).
2. Do NOT replace the MariaDB connection pool
   (mysql.connector.pooling.MySQLConnectionPool) with single connections.
3. Do NOT remove or weaken OAuth2 (XOAUTH2) support.
4. Do NOT revert to a "1 referent = 1 thread" model.
5. Do NOT weaken systemd hardening in mail-proxy.service.
6. Do NOT grant www-data access to Maildir or the vmail group.
7. Do NOT break encryption format compatibility between the PHP Cryptor and
   the Python Cryptor (AES-256-GCM with legacy AES-CBC read support, shared
   "gcm:" prefix).
8. Do NOT trust X-Real-IP/X-Forwarded-For without checking REMOTE_ADDR.
9. This PROMPT touches infrastructure only — no file in the repository is
   modified as part of this PROMPT.

Response format:

1. Per-requirement PASS/FAIL table (mirror the baseline table above,
   one row per requirement, with raw command output attached or quoted).
2. Explicit RAM headroom calculation as specified in step 4.
3. Go/no-go recommendation for proceeding to PROMPT 22 on this host.
4. If FAIL on any row: what would need to change on the host (OS package,
   resize, etc.) — infrastructure action only, not code.
```

────────────────────────────────────────

### PROMPT 22 — Execute the installer on the verified test VPS and capture drift

```
Project: DELTA-transit (mail-proxy)
Files: delta-transit-install.sh, mail-proxy-setup.sh, configure_limits.sh
Tooling: MCP SSH connector, against the host verified PASS in PROMPT 21.

Context:

phase_preflight() in delta-transit-install.sh checks for root, required
distribution files, and a MySQL root password — it does not check OS
version, RAM, disk, or PHP-FPM version before proceeding. This has never
been run against a real host end-to-end. Running it now, on a disposable
VPS, is how latent assumptions (e.g. the PHP 8.1 path hardcoded in
configure_limits.sh — see Epic F) get discovered instead of guessed.

Task:

1. Clone the repository onto the test VPS over the established MCP SSH
   session (or transfer the working tree — do not hand-edit files on the
   host; the host must run exactly what is in `master` at the commit
   Cursor is testing).
2. Run delta-transit-install.sh through to completion, capturing full
   stdout/stderr.
3. Run configure_limits.sh, capturing full stdout/stderr.
4. Enable and start mail-proxy.service; capture `systemctl status
   mail-proxy` and the first 100 lines of `journalctl -u mail-proxy`.
5. For every step that logged a warning, an error, or a silent no-op
   (e.g. configure_limits.sh not finding the expected php.ini path),
   record it verbatim — do not summarize away discrepancies.
6. Do NOT patch the scripts in this PROMPT even if a bug is found. This is
   an observation pass; fixes are scoped to Epic F.

HARD CONSTRAINTS: identical to PROMPT 21, items 1–8, plus:
9. Any deviation discovered between installer assumptions and host reality
   must be reported, not silently worked around by hand-editing the host.

Response format:

1. Full installer transcript (or a linked log artifact) with pass/warn/fail
   annotations per phase (Preflight, Packages/OS, MariaDB/Schema/Secrets,
   web deployment, systemd, Nginx, hardening).
2. List of every discrepancy between installer assumption and host reality
   (this feeds Epic F's prompt list directly — do not fix here).
3. Confirmation that mail-proxy.service reaches `active (running)`.
4. Regression check: with the daemon running, confirm `db.conf` still
   rejects the CHANGE_ME placeholder path (this can be tested by
   temporarily reverting db_pass to CHANGE_ME on a scratch copy of the
   config file, confirming the daemon refuses to start, then restoring
   the real value — never leave the host on the placeholder).
```

────────────────────────────────────────

## 5. Epic B — Web panel authentication (blocker)

**Goal:** close finding #1 from the 2026-09-01 audit — the web panel that
stores and edits plaintext-adjacent secrets (encrypted at rest, but
decrypted and displayed/used in the UI flow) has no login, relying solely on
`checkLocalNetworkAccess()` IP allow-listing. `Ckeck-list_00.md` §10 already
expects "Авторизация проходит успешно" ("Login succeeds") — the checklist
was written assuming this would exist.

### PROMPT 23 — Design review before implementation

```
Project: DELTA-transit (mail-proxy)
Files: web/index.php, web/includes/helpers.php, schema.sql
Deliverable: design proposal only — no code in this PROMPT.

Context:

There is currently no users/admins table, no password hashing, no login
form, no session-based authentication check anywhere in web/. Access
control is exclusively checkLocalNetworkAccess() (IP allow-list) called at
the top of index.php and monitor.php. This must become defense-in-depth
(network restriction AND login), not a replacement of one for the other.

Task:

Propose, in writing, before touching any file:

1. A minimal schema addition (new table, e.g. `panel_admins`: id, username,
   password_hash, active, created_at, updated_at — or fold into an existing
   table if a stronger reason exists; justify the choice).
2. Password hashing approach: use PHP's password_hash()/password_verify()
   (bcrypt/argon2i, whichever is the PHP-FPM version's default — confirm
   against the PHP version found in Epic A/PROMPT 21, do not assume 8.1).
3. Session handling: reuse the existing $_SESSION mechanism already used for
   csrf_token and flash messages — do not introduce a second session store.
4. Login throttling / lockout approach appropriate for a single-tenant
   internal admin tool (simple fixed-window rate limit is sufficient; do
   not over-engineer with a new dependency).
5. Where the login gate sits relative to checkLocalNetworkAccess() (must be
   network check first, then login — never the reverse, so that an
   unauthenticated request from outside the allow-list gets the existing
   403 before it can even reach a login form that would leak the panel's
   existence).
6. Whether monitor.php needs its own session check or can share index.php's
   session cookie (it is a separate PHP entry point today).

HARD CONSTRAINTS:

1. Do NOT remove or replace checkLocalNetworkAccess() — this is additive,
   not a substitute.
2. Do NOT introduce a new external dependency (no Composer, no third-party
   auth library) — PHP's built-in password_hash()/password_verify() and
   $_SESSION are sufficient and keep the project's zero-Composer footprint
   intact (confirm this footprint is intentional by checking there is no
   composer.json anywhere in the repo before assuming it).
3. Do NOT change the Cryptor class or its use for account secrets — panel
   login credentials are a separate concern from external_accounts
   password_enc/client_secret_enc.
4. Do NOT weaken CSRF protection already in place (requireValidCsrfToken()).
5. Do NOT change existing action routing in index.php beyond adding the
   gate itself.

Response format:

1. Schema diff proposal (DDL, not yet applied).
2. Sequence description: request → checkLocalNetworkAccess() →
   login-gate check → existing $action switch.
3. Open questions requiring a human decision before PROMPT 24 proceeds
   (e.g.: is a single shared admin account acceptable for pilot, or is
   per-referent-operator login required later?).
```

────────────────────────────────────────

### PROMPT 24 — Implement panel authentication

```
Project: DELTA-transit (mail-proxy)
Files: schema.sql, web/index.php, web/includes/helpers.php,
       new file web/includes/auth.php (or equivalent — follow existing
       includes/ naming convention), delta-transit-install.sh (to seed the
       first admin account during install, prompting for a password rather
       than hardcoding one).

Context:

Implements the design approved from PROMPT 23. This PROMPT assumes that
design has been reviewed by a human; do not re-derive the design here —
follow it. If PROMPT 23's proposal has open questions still unresolved,
STOP and request resolution before writing code.

Requirements:

1. Add the approved admin-accounts table to schema.sql, following the
   existing style (utf8mb4, InnoDB, created_at/updated_at with
   ON UPDATE CURRENT_TIMESTAMP, matching other tables in the file).
2. New session keys: $_SESSION['admin_id'], set only after successful
   password_verify(). Do not reuse oauth_state or csrf_token session keys
   for this purpose.
3. Gate placement in index.php: after checkLocalNetworkAccess(), before the
   $action switch, except for a new 'login' / 'login_submit' action pair
   which must remain reachable pre-authentication (but still behind
   checkLocalNetworkAccess()).
4. monitor.php must also require an authenticated session (per PROMPT 23's
   answer on shared vs. separate session).
5. Login form POST must go through requireValidCsrfToken() exactly like
   every other state-changing action already does.
6. Failed login attempts: writeLog() them at the same level/format as
   existing OAuth2 error logging, including the client IP from
   getClientIp() (not raw REMOTE_ADDR) for consistency with existing log
   parsing in monitor.php's parseLogLine().
7. Installer: delta-transit-install.sh must, on first run, prompt for (and
   confirm) an initial admin password interactively (following the same
   pattern as ask_mysql_root_password()) and store only the hash in the
   database — never write the plaintext password anywhere, including
   install-secrets.txt.

HARD CONSTRAINTS: identical to PROMPT 23, items 1–5, plus:
6. Do NOT log plaintext passwords, hashed or otherwise, anywhere —
   including in writeLog() failure messages.
7. Do NOT change monitor.php's tailFile()/parseLogLine()/loadLogEvents()
   log-format parsing — the new auth log lines must match the existing
   `[YYYY-MM-DD HH:MM:SS] message` PHP-panel log format so they render
   correctly in the "Критические события" section already built.

Response format:

1. Unified diff, file by file.
2. Explanation of the login flow end-to-end (5–10 sentences).
3. Manual test script (curl-based or step-by-step browser steps) that
   proves: (a) unauthenticated request outside the IP allow-list still
   gets 403 before reaching login; (b) unauthenticated request inside the
   allow-list is redirected to login; (c) wrong password is rejected and
   logged; (d) correct password reaches the dashboard; (e) monitor.php
   enforces the same gate.
4. Confirmation that this does not regress any existing CSRF/SSRF/XSS
   protection already verified in the 2026-09-01 audit.
```

────────────────────────────────────────

### PROMPT 25 — Verify Epic B against the test VPS

```
Project: DELTA-transit (mail-proxy)
Tooling: MCP SSH connector, against the host from Epic A.

Task:

Deploy the branch from PROMPT 24 to the test VPS (via the installer re-run
or targeted file sync — state which method was used and why). Execute the
five-point manual test script from PROMPT 24's Response Format item 3
against the live host, over HTTPS, not just localhost curl. Capture actual
HTTP responses (status codes, redirect targets, and confirm the login
cookie is marked Secure and HttpOnly if PHP session config allows it —
if not, note it as a follow-up, do not silently add ini_set() calls that
are out of scope for this PROMPT).

HARD CONSTRAINTS: same as PROMPT 21, items 1–9.

Response format:

1. Test-by-test PASS/FAIL against the five scenarios.
2. Session cookie flags observed (Secure/HttpOnly/SameSite) vs. PHP
   session.cookie_* ini defaults on the verified host.
3. Any deviation from PROMPT 24's design — reported, not silently patched.
```

---

## 6. Epic C — TLS certificate hardening

### PROMPT 26 — Replace self-signed default with a real certificate path

```
Project: DELTA-transit (mail-proxy)
Files: delta-transit-install.sh (generate_self_signed_certificate(),
       create_mail_proxy_virtual_host())

Context:

generate_self_signed_certificate() always produces a 10-year self-signed
cert when NGINX_SSL_CERT/NGINX_SSL_KEY are absent. This is acceptable as a
zero-config fallback for a throwaway test VPS, but the installer currently
gives the operator no guided path to a real certificate (e.g. Let's
Encrypt via certbot, or "I already have a cert, here are the paths") for
production hosts with a real public DNS name.

Requirements:

1. Add an interactive choice, in the same style as ask_public_url() /
   ask_mysql_root_password(): "self-signed (default, test only) /
   existing certificate files (prompt for paths) / certbot (if the
   NGINX_SERVER_NAME resolves publicly)".
2. Do NOT bundle certbot invocation logic that silently fails if the host
   has no public DNS — detect via a simple resolvable-public-IP heuristic
   and fall back to prompting, do not attempt network trickery.
3. Self-signed must remain the default for a bare "just install it" run —
   do not make TLS acquisition a hard blocker for the test-VPS workflow in
   Epic A/PROMPT 21–22.
4. Whatever path is chosen, generate_self_signed_certificate() and the new
   logic must both still leave SSL_MODE set to one of "existing" /
   "self-signed" / "certbot" so render_report() at the end of the
   installer accurately reflects what happened (check render_report()'s
   current phase list before changing SSL_MODE's possible values).

HARD CONSTRAINTS:

1. Do NOT change the nginx allow-list block (private ranges only) in
   create_mail_proxy_virtual_host() — TLS certificate source and network
   access control are independent concerns; this PROMPT touches only the
   former.
2. Do NOT add a dependency beyond certbot itself (already an official
   Debian/Ubuntu package — confirm via Epic A's package list which repo it
   comes from on the verified host before assuming it is preinstalled).
3. Do NOT remove the self-signed fallback.

Response format:

1. Unified diff.
2. Decision-tree description of the new prompt flow.
3. Manual verification steps for all three paths (existing / self-signed /
   certbot), to be run against the Epic A test VPS in a follow-up pass.
```

---

## 7. Epic D — OAuth SSRF: close the DNS-rebinding window

### PROMPT 27 — Bind the validated IP to the actual outbound connection

```
Project: DELTA-transit (mail-proxy)
Files: web/includes/oauth2.php, web/includes/helpers.php
       (assertSafeOAuthEndpoint(), isBlockedOAuthIp()),
       mail-proxy-daemon.py (validate_oauth_endpoint(),
       _refresh_oauth2_token())

Context:

Both the PHP and Python SSRF guards resolve the hostname, check the
resolved IP(s) against a private/loopback/link-local/metadata block-list,
and THEN make a separate network call (cURL in PHP, requests.post in
Python) that performs its own independent DNS resolution. Between the two
resolutions, an attacker controlling the target hostname's DNS could
return a public IP for the guard's check and a private/metadata IP for the
actual request (classic TOCTOU / DNS-rebinding). This is a genuine, not
theoretical, gap in an otherwise solid SSRF defense.

Requirements:

1. PHP (oauth2.php): after assertSafeOAuthEndpoint() passes, resolve the
   host once, pick one validated IP, and use CURLOPT_RESOLVE (or
   CURLOPT_CONNECT_TO) to pin the actual cURL request to that exact IP
   while still sending the original hostname in the Host header / SNI —
   this is the standard cURL-level fix for DNS-rebinding and does not
   require changing the URL passed to curl_setopt_array's CURLOPT_URL.
2. Python (mail-proxy-daemon.py): apply the equivalent pinning for the
   requests.post() call in _refresh_oauth2_token() — e.g. via a custom
   HTTPAdapter/Transport that resolves once in validate_oauth_endpoint()
   and reuses that IP, or an equivalent standard-library-compatible
   approach. Do not silently skip this side because "PHP already covers
   the initiation step" — the Python daemon performs its own independent
   token-refresh HTTP calls and needs its own pin.
3. Both implementations must re-run the block-list check against the
   pinned IP immediately before connecting (defense against the resolver
   itself being compromised between the two steps within the same
   request, not just between requests).
4. Preserve existing certificate validation (CURLOPT_SSL_VERIFYPEER=true /
   CURLOPT_SSL_VERIFYHOST=2 in PHP; default SSL context in Python) — IP
   pinning must not disable hostname verification in the TLS handshake.

HARD CONSTRAINTS:

1. Do NOT change the public function signatures of
   assertSafeOAuthEndpoint() / validate_oauth_endpoint() beyond what is
   strictly required to return/pass along the resolved IP.
2. Do NOT weaken the existing block-list (isBlockedOAuthIp() /
   the private/reserved/link-local checks in Python).
3. Do NOT add a new external dependency for this — cURL options and
   Python's ssl/socket/requests transport-adapter mechanisms are
   sufficient.
4. Do NOT change the OAuth provider seed data in schema.sql.

Response format:

1. Unified diff, PHP and Python separately.
2. Explanation of the pinning mechanism chosen for each language and why.
3. Regression test: confirm a legitimate call to
   https://oauth2.googleapis.com/token still succeeds end-to-end against
   the Epic A test VPS (this requires a real, even if inactive, Google
   OAuth client — coordinate with whoever holds test credentials; do not
   fabricate a client secret).
4. Attack-scenario description proving the DNS-rebinding window is closed
   (may be described, not necessarily reproduced against a live malicious
   DNS server).
```

---

## 8. Epic E — IMAP memory footprint mitigation (P4)

### PROMPT 28 — Reduce peak memory in poll_external_imap()

```
Project: DELTA-transit (mail-proxy)
File: mail-proxy-daemon.py
Affected function: poll_external_imap() (imaplib fetch path)

Context:

This is documented in DELTA-transit_anchor.md §7 as "P4 — открыт" (open,
unchanged) and was independently confirmed in the 2026-09-01 audit: today,
mail.fetch(num, '(RFC822)') returns the entire message into process memory
as data[0][1] before it is streamed to a temp file. With
IMAP_WORKER_COUNT=20 and a 150 MB target attachment size (~200 MB after
IMAP's own base64/quoted-printable transport encoding), a worst case of
many large messages arriving concurrently produces multi-gigabyte transient
memory pressure. The anchor document already notes that a fully streaming
fetch is not available through stdlib imaplib without replacing the
protocol layer — that replacement is explicitly out of scope here.

Task — evaluate options in this order and pick the least invasive one that
actually bounds memory, stopping and reporting rather than forcing a bad
fit:

1. Can BODY.PEEK[] with partial fetch (IMAP4rev1 partial fetch:
   `FETCH n BODY[]<0.65536>` in a loop) be used instead of a single RFC822
   fetch, writing each partial chunk to the temp file as it arrives,
   bounding memory to one chunk regardless of message size? This stays
   within stdlib imaplib (imaplib.IMAP4.fetch already accepts arbitrary
   message-data-item strings) — confirm this experimentally against the
   Epic A test VPS's IMAP test account before committing to it, since
   provider-side partial-fetch support varies.
2. If partial fetch proves unreliable across the providers actually seeded
   in oauth_providers (Google/Yandex/Microsoft) plus plain IMAP, propose a
   concurrency-based bound instead: a semaphore limiting how many
   ImapWorkerPool threads may be inside the "hold a full message in
   memory" critical section simultaneously, sized so that
   (semaphore_count × max_expected_message_size) stays within the RAM
   headroom confirmed in Epic A/PROMPT 21. This does not reduce
   per-message peak, but bounds the aggregate.
3. Do not silently choose option 2 without first attempting option 1 and
   reporting why it was rejected, if it was.

HARD CONSTRAINTS:

1. Do NOT replace imaplib with a different IMAP library — this was
   explicitly ruled out of scope in the anchor document and remains so.
2. Do NOT change IMAP_WORKER_COUNT/SMTP_WORKER_COUNT.
3. Do NOT change the on-disk temp file format or TEMP_DIR location/
   permissions (0700, vmail-owned).
4. Do NOT change _deliver_to_local_smtp() or _stream_file_via_smtp() beyond
   what is strictly required to accept a partially-written temp file if
   option 1 is chosen (they must still only begin SMTP delivery after the
   full message is confirmed complete on disk).
5. If neither option 1 nor option 2 is viable without an architectural
   change, STOP and explain why, per the project's standing rule.

Response format:

1. Which option was evaluated, in what order, with pass/fail per provider
   tested against Epic A's test VPS.
2. Chosen approach and unified diff.
3. Before/after peak memory measurement for a single 150 MB test message
   (use test_large_attachment.py or an IMAP-side equivalent — note that
   test_large_attachment.py currently only exercises the outbound SMTP
   path, not IMAP fetch; state clearly if a new IMAP-side test script was
   needed and add it alongside test_large_attachment.py, not inside it).
4. Regression tests confirming normal-sized mail (no attachment) still
   flows correctly end-to-end.
```

---

## 9. Epic F — Installer preflight & PHP-FPM version consistency

### PROMPT 29 — Fix the PHP-FPM version mismatch and add a real preflight phase

```
Project: DELTA-transit (mail-proxy)
Files: delta-transit-install.sh (phase_preflight(),
       enable_services_if_present()), configure_limits.sh

Context:

enable_services_if_present() in the installer already tolerates
php8.1-fpm / php8.2-fpm / php8.3-fpm as unit names. configure_limits.sh,
however, hardcodes PHP_INI="/etc/php/8.1/fpm/php.ini" — on a Debian 12 host
(default PHP 8.2) or Ubuntu 24.04 (default PHP 8.3), this path silently
does not exist, and none of the PHP-side limits (upload_max_filesize,
post_max_size, memory_limit, max_execution_time) get applied, with no
loud failure — this was confirmed as a live discrepancy risk during the
2026-09-01 audit and should be treated as confirmed, not hypothetical,
once Epic A/PROMPT 22 reports the actual host's PHP version.

Also: phase_preflight() performs no OS/RAM/disk check at all today (see
Epic A's baseline table) — this PROMPT adds that check into the installer
itself, so future installs on unverified hosts fail loudly and early
instead of partially succeeding.

Requirements:

1. configure_limits.sh: detect the actual installed PHP-FPM version's ini
   path (e.g. via `php -v` parsing, or by globbing `/etc/php/*/fpm/php.ini`
   and picking the version matching the running php-fpm binary) instead of
   hardcoding 8.1. Fail loudly (non-zero exit, clear message) if no
   php.ini is found, rather than silently skipping the PHP limit section.
2. phase_preflight(): add explicit checks for
   - OS is Debian or Ubuntu (parse /etc/os-release ID field) — fatal() if
     not, with the exact message naming what was found vs. expected;
   - available RAM (free -m) meets a documented minimum (state the number
     you derive from Epic A/PROMPT 21's headroom calculation — do not
     invent a round number without tracing it back to that calculation);
   - available disk on the partition backing /var/vmail and /var/log meets
     a documented minimum;
   - these are warnings (log_warn, continue) if the installer is running
     with an explicit --force-unverified-host flag (new, opt-in), and
     fatal otherwise — this preserves the ability to test on an
     intentionally undersized box without silently shipping that as the
     default behavior.
3. Update the "Declared minimum requirements baseline" table in this
   roadmap document (Section 4) if the numbers chosen here differ from
   what was assumed there — the roadmap and the installer must agree.

HARD CONSTRAINTS:

1. Do NOT change APT_PACKAGES beyond what is strictly required.
2. Do NOT change the order of existing installer phases beyond adding
   checks inside phase_preflight() itself.
3. Do NOT remove the existing require_root/require_file checks.
4. Do NOT change the Postfix/Dovecot/Nginx limit values themselves (200MB/
   300MB/210M) — only how configure_limits.sh locates the PHP ini file.

Response format:

1. Unified diff, both files.
2. Table of PHP-FPM versions/paths now handled vs. before.
3. Verification run against the Epic A test VPS's actual PHP version,
   showing configure_limits.sh now finds and edits the correct php.ini.
4. Verification that phase_preflight() now fails loudly on a deliberately
   undersized/mismatched scratch host (describe how this was simulated,
   e.g. a second, intentionally minimal test VPS or a container), and
   passes cleanly on the real Epic A host.
```

---

## 10. Epic G — Documentation synchronization

### PROMPT 30 — Bring the anchor document current

```
Project: DELTA-transit (mail-proxy)
File: docs/DELTA-transit_anchor.md
Deliverable: docs/DELTA-transit_anchor.md, version bumped to v4.0.

Context:

The anchor document is dated 2026-06 and still lists P7 (whitelist
validation of auth_type/imap_encryption/smtp_encryption before use) as
"открыт" (open) in §7 and §9. The current code
(_validate_account_settings() in mail-proxy-daemon.py, explicitly commented
"FIX P7") already implements this. The document also predates Epic B
through Epic F of this roadmap. Shipping to production with a stale anchor
document is itself a finding from the 2026-09-01 audit (§15) — the next
model or engineer who reads this file first must not be misled.

Requirements:

1. Update §7/§9 P7 status to reflect the code as it exists after this
   roadmap's PROMPTs are merged — do not mark it closed until PROMPT 28's
   equivalent work for P4 and this roadmap's other epics have actually
   landed; sequence this PROMPT to run AFTER B–F are merged, not before.
2. Add a new §12 (or renumber as appropriate) covering: panel
   authentication (Epic B), TLS certificate strategy (Epic C), the OAuth
   IP-pinning fix (Epic D), the IMAP memory approach chosen (Epic E),
   and the installer preflight additions (Epic F) — same terse,
   table-driven style as the rest of the document, not prose paragraphs.
3. §10 "Инструкция для следующей модели" ("НЕ ДЕЛАТЬ") list must be
   updated to include any new standing constraints introduced by this
   roadmap (e.g. "do not remove the panel login gate", "do not revert
   OAuth IP pinning").
4. Bump the document version marker and "Синхронизирован с" line to name
   the actual commit/PR range this sync corresponds to.

HARD CONSTRAINTS:

1. Do NOT alter the historical §7 FIX-3.2-* table — those are closed
   historical entries; append, do not rewrite history.
2. Keep the existing document's language and terseness — this is a
   reference anchor for future automated agents, not a narrative report.
3. Do NOT remove the "Подтверждённые исправления (не трогать)" list;
   only append to it.

Response format:

1. Full replacement content for DELTA-transit_anchor.md.
2. Diff-style summary of what changed vs. v3.2, section by section.
```

---

## 11. Epic H — Full deployment checklist execution & burn-in

### PROMPT 31 — Execute Ckeck-list_00.md against the Epic A test VPS end-to-end

```
Project: DELTA-transit (mail-proxy)
File: docs/Ckeck-list_00.md
Tooling: MCP SSH connector, against the Epic A test VPS, now carrying every
         fix from Epics B–G.

Context:

As of the 2026-09-01 audit, none of the ~90 checklist items in
Ckeck-list_00.md were checked off on any environment. This PROMPT is the
first time the checklist is executed for real, and it is only meaningful
once Epics A–G have actually landed on the test host.

Task:

1. Work through Ckeck-list_00.md sections 1–14 in order, checking each box
   only after running the listed verification command (or an equivalent
   command if none is given) and recording the actual output — not a
   restatement of the checklist item as if it were the result.
2. Section 15–16 (service restart, server reboot) and the "Burn-in" section
   at the end require sustained observation — schedule these as a
   long-running pass (48 hours minimum per the checklist's own "Burn-in"
   requirement) rather than a single SSH session; report interim status if
   this PROMPT's response is produced before the 48 hours complete, and
   file a follow-up PROMPT 32 to close it out.
3. Section 11 (OAuth2: Gmail/Microsoft/Yandex) requires real, even if
   scoped-down, OAuth client credentials for each provider — coordinate
   with whoever holds them; do not fabricate credentials or simulate
   success.
4. Section 17 (backup) requires an actual restore test, not just a backup
   file existing — perform the restore into a scratch database on the
   same test VPS and confirm row counts match.
5. If any GO criterion in the checklist's own "Критерии GO" section fails,
   or any NO-GO criterion is triggered, stop and report — do not mark
   overall status GO while a listed NO-GO condition is present.

HARD CONSTRAINTS: same as PROMPT 21, items 1–9.

Response format:

1. Ckeck-list_00.md with every box updated to [x] or left [ ] with a
   one-line reason, committed back to the repository (this is the one
   deliverable in this roadmap that is a checklist-file edit, not code).
2. Burn-in log summary (CPU/RAM/log-growth/queue-depth samples over the
   48-hour window).
3. Explicit GO / NO-GO recommendation, quoting which specific checklist
   criteria drove the recommendation.
```

---

## 12. Epic I — Git/GitHub workflow hygiene (non-blocking)

### PROMPT 32 — Establish branch protection and PR conventions

```
Project: DELTA-transit (mail-proxy), GitHub repository FF3mail/Proxy_Email

Context:

The repository currently has a single commit on master with an unfilled
template commit message. Section 1 of this roadmap already commits future
work (PROMPT 21 onward) to branch-per-PROMPT and PR-per-branch. This PROMPT
formalizes that so it is enforced by GitHub, not just by convention.

Task:

1. Enable branch protection on master: require PR review before merge,
   require the branch to be up to date before merging, disallow force
   pushes to master.
2. Add a minimal PR template (.github/PULL_REQUEST_TEMPLATE.md) with
   fields: PROMPT number, files touched, HARD CONSTRAINTS confirmed
   unbroken, regression tests run and result.
3. Add a CONTRIBUTING.md (or extend docs/) documenting the PROMPT/branch/PR
   convention from Section 1 of this roadmap so it is discoverable without
   this document present.

HARD CONSTRAINTS:

1. Do NOT rewrite the existing commit history.
2. Do NOT change repository visibility (remains public unless a human
   explicitly decides otherwise — this is out of scope for this PROMPT).

Response format:

1. Confirmation of branch protection settings applied.
2. Content of the new template/documentation files.
```

---

## 13. Summary — path to GO

```
Epic 0  → base mail system (iRedMail) verified/installed, test mailboxes provisioned
Epic A  → real test VPS, requirements verified, installer run once end-to-end
Epic B  → panel login shipped (closes audit finding #1, the sole blocker)
Epic C  → real TLS path available (self-signed remains safe default)
Epic D  → OAuth SSRF hardened against DNS rebinding
Epic E  → IMAP memory footprint bounded and measured
Epic F  → installer fails loudly instead of silently on unmet requirements
Epic G  → anchor document tells the truth again
Epic H  → Ckeck-list_00.md fully executed, burn-in complete, GO/NO-GO issued
Epic I  → future changes get a real audit trail
```

Only after Epic H reports **GO** should `docs/Ckeck-list_00.md`'s own
"Критерии GO" be considered satisfied and a pilot user population
onboarded, per the audit's original recommendation.
