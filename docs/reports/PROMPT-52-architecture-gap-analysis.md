# PROMPT-52 — Architecture Gap Analysis: Can the Existing Daemon Be Adapted?

**Date:** 2026-09-09  
**Branch:** `prompt-47-panel-authorization-audit`  
**Starting commit:** `18c6858ddd3a54539c11c48716a6144c87a1a36f`  
**Mode:** READ-ONLY architectural audit — report only  
**Business baseline:** Approved customer bidirectional attachment-routing specification (PROMPT-51 §§1–4 / this prompt §§2–4); decisions U1–U4 superseded by that approved customer model (not reopened)

---

## 1. Executive conclusion

**Central answer: B. YES, BUT — feasible with substantial internal rework.**

The existing daemon is already the right **transport framework** (IMAP poll → queue → workers → local SMTP; Maildir watchdog → queue → workers → external SMTP; OAuth2; crypto; retry/backpressure; logging; systemd lifecycle). That skeleton maps cleanly onto the target layered architecture.

What does **not** map is the **business-routing / message-transformation layer** and the **data model** that feeds it. Today the daemon implements a **referent-centric whole-message relay**. The approved customer model requires a **per-Client-relationship four-address map**, **`From`-based inbound identification**, **`To`-based outbound identification**, **attachment extraction + new RFC822 construction**, **unknown-sender spam deletion**, and **outbound pickup from local Client Maildirs** (not only the single referent outbox).

Therefore: **do not rewrite the daemon from scratch**; **do plan a substantial redesign of routing, MIME transform, schema, panel configuration model, and Maildir watch targets** inside the existing process architecture.

| Reuse category (engineering estimate, not LOC %) | Assessment |
|--------------------------------------------------|------------|
| Transport infrastructure | **HIGH reuse** |
| IMAP layer | **HIGH reuse** |
| SMTP layer (connect/auth/stream) | **HIGH reuse** |
| OAuth2 | **HIGH reuse** |
| Worker pools / queues | **HIGH reuse** |
| Maildir watchdog *mechanism* | **HIGH reuse** |
| Inbound routing | **LOW reuse / REWORK** |
| Outbound routing | **LOW reuse / REWORK** |
| Message construction | **REWORK** (stdlib capable; logic absent) |
| Database model | **REWORK** (extendable; not a full drop) |
| Panel data model / UI | **REWORK** |

---

## 2. Current architecture summary

### 2.1 Process topology (CODE-OBSERVED — `mail-proxy-daemon.py`)

```text
ProxyDaemon
├── Database (MySQLConnectionPool, DB_POOL_SIZE=12)
├── Cryptor (AES-GCM + legacy CBC)
├── MailHandler (shared business object)
├── ImapPoller (every IMAP_POLL_INTERVAL=60s)
│     └── puts ImapTask(referent, account) → imap_queue (max 5000)
├── ImapWorkerPool (20) → MailHandler.poll_external_imap
├── Observer + MaildirHandler per active referent.local_outbox/new
│     └── puts SmtpTask → smtp_queue (max 1000)
└── SmtpWorkerPool (20) → MailHandler.send_via_external_smtp
```

### 2.2 Inbound path (current)

1. `ImapPoller` loads `referents WHERE active=1`, then **all** `external_accounts` for each (`_load_accounts_for_referent`, no `LIMIT`).
2. Worker opens IMAP (ssl/tls/none), plain or OAuth2 XOAUTH2.
3. `SEARCH UNSEEN`; P4 size probe (`RFC822.SIZE` / `BODYSTRUCTURE`); skip oversized.
4. `FETCH RFC822` into RAM → temp file under `/var/spool/mail-proxy/tmp`.
5. `_deliver_to_local_smtp`: parse headers; `_resolve_local_recipients` matches **`To`/`Cc`** to `clients.email` for this `referent_id`; on empty match **fallback** to `referents.local_inbox`.
6. Envelope `MAIL FROM = local_inbox`; `RCPT TO = local_inbox` (when matched); **DATA = original RFC822 bytes streamed unchanged**.
7. On SMTP success: IMAP `STORE +FLAGS \Seen` (not DELETE).

### 2.3 Outbound path (current)

1. Watchdog on **`referents.local_outbox/new`** (Maildir of the referent’s single local mailbox).
2. Header-only parse (`BytesHeaderParser`); recipients = raw **`To`/`Cc`** (no `clients` lookup).
3. Account = `external_accounts ... WHERE referent_id=? AND active=1 LIMIT 1` (no `ORDER BY`, no client link).
4. External SMTP: `MAIL FROM = account.email`; `RCPT TO = header recipients`; **DATA = original file unchanged**.
5. On SMTP 250: delete Maildir file; failures leave file for backlog rescan (`_queued_outgoing_files` dedupe).

### 2.4 Data model (current)

| Table | Role |
|-------|------|
| `referents` | One `local_inbox` (email) + one `local_outbox` (Maildir path) per referent |
| `clients` | Single `email` column + `referent_id`; UNIQUE on `email` |
| `external_accounts` | IMAP/SMTP creds linked to **`referent_id` only** (no `client_id` FK for relationship) |
| `oauth_tokens` / `oauth_providers` | OAuth support |
| `panel_admins` | Panel operators |

Panel UI configures **one client email per referent** and manages external accounts at referent scope (`web/index.php`).

---

## 3. Target architecture summary

### 3.1 Relationship model (approved)

One Referent → many Client relationships (zero allowed but then inactive).  
Each relationship has **four independent addresses**:

```text
external_client, local_client, external_referent, local_referent
```

Local-parts need not match; **DB mappings are authoritative**.  
One external Referent mailbox **per Client relationship**.  
**No** shared single `local_inbox` for all Clients.

### 3.2 Inbound algorithm (approved)

```text
External Referent mailbox → IMAP → identify Client by From
  → unknown From → delete as spam
  → known → DB: local_client, local_referent
  → extract attachment(s) → NEW local RFC822
       From=local_client  To=local_referent  attachment(s) only
  → local SMTP → local Referent mailbox
```

### 3.3 Outbound algorithm (approved)

```text
local Client mailbox → Maildir → watchdog → identify Client by To
  → unknown → must not send externally
  → known → DB: external_client, external_referent
  → extract attachment(s) → NEW external RFC822
       From=external_referent  To=external_client  attachment(s) only
  → external SMTP → external Client mailbox
```

---

## 4. Component-by-component classification

| Component | Location | Classification | Rationale |
|-----------|----------|----------------|-----------|
| `setup_logging` / SIGUSR1 reopen | daemon | **KEEP** | Independent of routing |
| `Cryptor` | daemon + `web/includes/Cryptor.php` | **KEEP** | Credentials/OAuth blobs unchanged |
| `Database` pool | daemon | **KEEP** | Pool mechanics fine; queries change |
| `validate_oauth_endpoint` | daemon | **KEEP** | SSRF guard |
| `ImapTask` / `SmtpTask` dataclasses | daemon | **ADAPT** | Need relationship/context fields (client_id, mapped addresses, transform artifact path) |
| `ImapWorkerPool` | daemon | **KEEP** | Queue/worker loop unchanged |
| `SmtpWorkerPool` | daemon | **KEEP** | Keep delete-on-success; adapt what “success” means after transform |
| `ImapPoller` scheduling | daemon | **KEEP** | Interval + enqueue pattern |
| `ImapPoller` account discovery SQL | daemon | **ADAPT** | Discover by relationship-linked external mailboxes, not only referent |
| IMAP connect / auth / OAuth refresh | `MailHandler` | **KEEP** | Transport |
| IMAP size guard (P4) | `MailHandler` | **KEEP** | Still valid before fetch |
| IMAP fetch → temp file | `MailHandler` | **KEEP** | Still needed; may feed parser |
| `_resolve_local_recipients` | `MailHandler` | **REWORK** | Wrong key (`To`/`Cc`); wrong result (shared inbox); wrong fallback |
| `_deliver_to_local_smtp` whole-message stream | `MailHandler` | **REWORK** | Must become: lookup → extract → construct → inject |
| Unknown-sender / spam delete path | *(missing)* | **REPLACE** *(new capability)* | No DELETE/EXPUNGE today; mark `\Seen` only |
| `_stream_file_via_smtp` | `MailHandler` | **KEEP** | Reusable for constructed files |
| `send_via_external_smtp` connect/auth/stream | `MailHandler` | **KEEP** | Transport |
| Outbound recipient selection | `MaildirHandler` | **REWORK** | Must DB-map `To` → external pair; not passthrough |
| `_load_first_account` LIMIT 1 | `MaildirHandler` / backlog | **REWORK** | Must select relationship’s external referent mailbox |
| Watchdog Observer mechanism | `ProxyDaemon` | **KEEP** | inotify scheduling reusable |
| Watch targets (`local_outbox` per referent) | `ProxyDaemon` | **REWORK** | Target watches **local Client** Maildirs per relationship |
| `_queued_outgoing_files` / backlog scan | `ProxyDaemon` | **ADAPT** | Keep idempotency; apply to new watch paths |
| `_sync_database_state` | `ProxyDaemon` | **ADAPT** | Sync relationship mailboxes / active rules |
| Activation integrity (0 clients → inactive) | panel + daemon | **REWORK** | Not enforced today |
| `schema.sql` core tables | DB | **ADAPT/REWORK** | Retain tables; extend semantics / add relationship linkage |
| `oauth_*` | DB | **KEEP** | Unchanged |
| `panel_admins` / auth | web | **KEEP** | Orthogonal |
| Referent form (single client email) | `web/index.php` | **REWORK** | Need multi-relationship four-address UI |
| External account form | web | **ADAPT** | Bind account to Client relationship |
| `maildir_resolver.php` | web | **ADAPT** | Resolve Maildir for local_client and local_referent addresses |
| `test_large_attachment.py` | tests | **KEEP** (infra smoke) | SMTP size only; not routing |
| Panel PHP tests | `tests/*` | **KEEP** for panel auth/routing; **gap** for daemon |

**REPLACE used sparingly:** only the *missing* spam-delete / MIME-construction *behaviors* (new modules or methods), not entire transport classes.

**REMOVE:** none mandatory. The permissive inbound fallback path should be **removed as behavior** under the approved spec, but the surrounding delivery function is reworked rather than deleted as a class.

---

## 5. Transport vs business boundary

```text
TRANSPORT / INFRASTRUCTURE          BUSINESS ROUTING / MESSAGE TRANSFORM
--------------------------------    --------------------------------------
ImapPoller schedule                 Which accounts mean which Client
IMAP SSL/TLS/plain connect          Identify Client by From / To
XOAUTH2 + token refresh             Relationship lookup (4 addresses)
imaplib FETCH + size guard          Unknown sender → delete spam
Temp file spool                     Unknown local To → do not send
queue.Queue + worker pools          Attachment extract / MIME rebuild
_stream_file_via_smtp               Envelope From/To rewrite
smtplib external connect/auth       Account selection per relationship
watchdog Observer                   Which Maildir paths to watch
_queued_outgoing_files              Active/inactive integrity rules
Cryptor / logging / systemd         Panel configuration of relationships
```

**Conclusion:** The conceptual architecture below **can be retained**; only the middle “routing/transform” boxes need replacement of current logic:

```text
External IMAP → IMAP workers → inbound queue → [Inbound Router/Transform] → local SMTP
Local Maildir → watcher → outbound queue → [Outbound Router/Transform] → external SMTP
```

---

## 6. Inbound gap analysis

| Concern | Current | Target | Gap class |
|---------|---------|--------|-----------|
| Account discovery | All active accounts per referent | External referent mailbox per Client relationship | ADAPT discovery SQL + linkage |
| Client ID field | `To`/`Cc` vs `clients.email` | **`From`** | REWORK |
| Unknown sender | Deliver to `local_inbox` | **Delete as spam** | New IMAP delete/expunge path |
| Local addresses | Single `referents.local_inbox` | Per-relationship `local_client` + `local_referent` | Schema + lookup REWORK |
| Message body | Original RFC822 relay | **New** message, attachment(s) only | MIME REWORK |
| Envelope From | `local_inbox` (referent) | Spec: header From=`local_client`; envelope policy TBD for Postfix `reject_unlisted_sender` | ADAPT + verify with local MTA |
| Success flag | `\Seen` after local SMTP OK | Keep success gate; spam path uses delete instead of deliver | ADAPT |
| Retry | UNSEEN retained if SMTP fails | Same pattern viable after transform failure | KEEP semantics |

**Reusable:** IMAP poll, auth, size guard, temp spool, local SMTP stream, `\Seen` on success.  
**Not reusable:** `_resolve_local_recipients`, fallback delivery, unchanged DATA body.

---

## 7. Outbound gap analysis

| Concern | Current | Target | Gap class |
|---------|---------|--------|-----------|
| Watch root | Referent `local_outbox/new` | **Local Client mailbox** Maildir | REWORK watch registration |
| Client ID | None (passthrough recipients) | Message **`To`** → relationship | REWORK |
| External From | `LIMIT 1` account email | Relationship `external_referent` | REWORK |
| External To | Header addresses as-is | Relationship `external_client` | REWORK |
| Message body | Original file | New message + attachments only | MIME REWORK |
| Unknown Client | May attempt external send | Must not send | New guard |
| Delete on success | Yes after 250 | Keep | KEEP |
| Retry / backlog | File retained + rescan | Keep | KEEP |

**Highest structural outbound gap:** watch target semantics change from “one Maildir per referent” to “one Maildir per local Client address (per relationship).” The Observer API still fits; the **registry of paths and handler context** must be redesigned.

---

## 8. Database gap analysis

### 8.1 Retain vs incorrect semantics

| Artifact | Verdict |
|----------|---------|
| Tables `referents`, `clients`, `external_accounts`, `oauth_*`, `panel_admins` | **Retain** as base entities |
| `referents.username`, `active`, timestamps | **Retain** |
| `referents.local_inbox` / `local_outbox` as **the only** local mailbox pair | **Incorrect semantics** for target (must not be sole shared inbox for all Clients) |
| `clients.email` as single ambiguous address | **Incorrect semantics** — need explicit external vs local |
| `external_accounts.referent_id` only | **Insufficient** — cannot bind one external mailbox per Client with integrity |
| `password_enc`, OAuth columns, `oauth_tokens` | **Retain unchanged** |
| UNIQUE(`clients.email`), UNIQUE(`external_accounts.email`) | Likely **retain** (global uniqueness still useful) |

### 8.2 Can tables be extended?

**Yes.** Conceptual options (analysis only — no migration written):

1. **Preferred conceptual shape:** treat each `clients` row as a **Client relationship** and add columns such as:
   - `external_client_email` (rename/split from today’s `email`)
   - `local_client_email`
   - `local_referent_email`
   - `external_account_id` (FK → `external_accounts`, UNIQUE for 1:1 mailbox-per-client)
2. Or introduce `client_relationships` table linking `referent_id`, four addresses, and `external_account_id`.
3. `external_accounts` can remain credential store; add `client_id` / relationship FK (nullable during migration) while keeping `referent_id` for ownership.

### 8.3 Four-address representability today

**No** — cannot unambiguously store `external_client`, `local_client`, `external_referent`, `local_referent` per relationship (PROMPT-51 confirmed; re-verified on VPS `DESCRIBE`).

### 8.4 One external Referent mailbox per Client

**Not with integrity today.** Schema allows many accounts per referent; outbound picks arbitrary `LIMIT 1`. Target needs **exactly one** external mailbox per Client relationship (DB constraint + UI).

### 8.5 Incremental migration strategy (conceptual only)

1. Add new columns / optional relationship table **without dropping** existing columns.
2. Dual-read period: daemon feature-flag or version gate (future work).
3. Data transform heuristics for lab rows (e.g. map `clients.email` → provisional `external_client` or `local_client` — **operator confirmation required**; current lab values look local `@testvps.loc`).
4. Bind each existing `external_accounts` row to the single client under its referent where cardinality is 1:1.
5. Deprecate shared-only use of `referents.local_inbox` for multi-client referents; may remain as display/legacy or first-relationship seed.
6. Enforce activation: referent `active=1` only if ≥1 active Client relationship.

**Existing encrypted credentials:** can remain byte-compatible.  
**Existing configured data:** transform is **possible but not automatic-safe** without operator validation of address roles.

---

## 9. Message / MIME transformation analysis

### 9.1 Current state

- Daemon uses `email.message_from_binary_file` / `BytesHeaderParser` for **headers only**.
- **No** `walk()`, `get_payload`, `MIMEMultipart`, or attachment extraction in `mail-proxy-daemon.py`.
- Both directions stream **entire RFC822**.
- `test_large_attachment.py` shows stdlib MIME **generation** is already used elsewhere in the repo (`MIMEMultipart`, `MIMEBase`, `encoders`).

### 9.2 Library sufficiency

| Capability | Available? | Notes |
|------------|------------|-------|
| MIME parse | Yes — Python stdlib `email` | Sufficient for approved scope |
| MIME generate | Yes — stdlib (+ proven in test script) | Sufficient |
| Attachment extract | Not implemented; stdlib supports | Need policy for `Content-Disposition` |
| Preserve filename / content-type | Feasible when copying MIME parts | Must copy headers carefully |
| Multiple attachments | Feasible | Spec says attachment(s); implement N parts |
| Streaming vs reconstruct | **Tension** | Today streams file; transform requires parse → new file → stream. Compatible with existing temp-dir + `_stream_file_via_smtp` |

### 9.3 Required vs options vs unresolved

| Topic | Classification |
|-------|----------------|
| Create NEW message with mapped From/To | **Required** |
| Transfer attachment(s) only | **Required** (approved spec) |
| Discard original HTML/plain body | **Required by “attachment only”** unless product later softens |
| Multiple attachments | **Required to support if present**; exact empty-attachment policy |
| Missing attachment | **Unresolved product detail** — fail closed vs quarantine vs skip (security section recommends fail closed / no external send / no local deliver) |
| Inline `Content-Disposition: inline` images | **Unresolved** — treat as attachment or discard |
| Max size after rebuild (base64) | Align with existing 200 MB inbound guard / Postfix limits — **implementation option** |
| Envelope sender vs header From on local inject | **Unresolved ops detail** — current code forces envelope=`local_inbox` for iRedMail `reject_unlisted_sender`; target headers use `local_client` — must validate Postfix policy |

**Streaming architecture does not forbid transform:** fetch/spool → transform to new temp file → stream. Peak memory remains dominated by `imaplib.fetch` (already documented open limit).

---

## 10. Queue / worker / performance analysis

| Topic | Assessment |
|-------|------------|
| Message / attachment size | Existing 150 MB attachment / ~200 MB SMTP design remains the envelope; rebuild adds CPU/base64 work, not a new transport class |
| Memory | Transform may hold decoded parts; prefer write attachments to temp files; still bounded by current fetch-in-RAM limit |
| Temp files | Already central (`TEMP_DIR`); outbound may need construct-temp then send (do not delete Maildir source until 250) |
| Concurrency | 20/20 workers still viable; relationship DB lookups are cheap vs IMAP/SMTP |
| Backpressure | `queue.Full` skip + backlog rescan remains valid |
| Duplicate delivery | Inbound: `\Seen` only after success — keep. Outbound: file registry — keep. Transform must be deterministic per source message |
| Failure after IMAP fetch before local SMTP | Message stays UNSEEN — **compatible** |
| Failure after Maildir pickup before external SMTP | File retained — **compatible**; if transform writes side file, delete side file on failure, keep source |
| Partial processing | Avoid marking `\Seen` / deleting Maildir until final SMTP 250 of **constructed** message |

**Verdict:** Target transform does **not** invalidate queue/worker architecture. It adds a CPU/IO stage inside workers.

---

## 11. Failure / idempotency / security implications

| Scenario | Target behavior | Current | Implication |
|----------|-----------------|---------|-------------|
| Unknown external sender | Delete spam | Deliver + `\Seen` | New hard security boundary |
| Unknown local Client `To` | Do not send | May send to literal address | Must hard-stop |
| Inactive Referent | Not polled / not watched | `active=0` already excluded | Extend to zero-client rule |
| Inactive Client relationship | Skip routing | `clients.active` only used inbound today | Enforce both directions |
| Missing external mailbox | Cannot poll/send | Partial | Config integrity checks |
| Missing local mailbox | Local SMTP RCPT fail / watch path missing | Exists for referent path | Need resolver for client+referent locals |
| Malformed MIME | Fail closed | Header parse skip outbound; inbound relies on Postfix | Explicit reject paths |
| Missing / multiple attachments | Policy needed | N/A (full relay) | Recommend: 0 → do not route; N → include all attachment parts |
| SMTP/IMAP failure | Retry via UNSEEN / file retain | Present | Preserve |
| Duplicate / replay | `\Seen` / file delete | Present | Preserve after successful transform send |
| Credential / OAuth fail | Skip account | Present | Preserve |
| www-data vs Maildir | www-data not in `vmail` | Documented | **Preserve** — panel must not gain Maildir rights |
| Crypto key handling | groups `mail-proxy-crypto` | Present | **Preserve** |
| Worker pools | Present | Present | **Preserve** |

Spam deletion must use IMAP `\Deleted` + `EXPUNGE` (or provider-specific trash) **only after** confident classification as unknown sender — never delete before relationship decision completes.

---

## 12. Existing test coverage gaps

| Area | Existing coverage | Target requirement | Gap |
|------|-------------------|--------------------|-----|
| IMAP polling | Manual/VPS scripts in `.keys/`; no unit suite | Reliable poll of relationship mailboxes | **Large** — no automated daemon routing tests |
| Client identification inbound | None asserting `From` | Identify by **From** | **Missing** |
| Unknown sender | None | Delete | **Missing** |
| Relationship lookup | None | Four-address map | **Missing** |
| Attachment extraction | `test_large_attachment.py` generates MIME only | Extract + rebuild | **Missing** |
| New local message | None | Required | **Missing** |
| Local Maildir detection | Implicit ops scripts | Watch client Maildirs | **Missing** |
| Outbound Client ID | None | By **To** | **Missing** |
| External address mapping | None | Required | **Missing** |
| New external message | None | Required | **Missing** |
| OAuth2 | Ops checklist / panel OAuth code | Preserve | **Partial** (no daemon unit tests) |
| Retry/idempotency | Code paths exist; few automated asserts | Required | **Partial** |
| Panel auth/UI routing | `tests/panel_*.php` | Still needed; plus multi-client forms | **Panel OK; model tests missing** |

---

## 13. VPS observations (read-only)

**Host:** `mail` / `192.168.125.116`  
**Performed:** `systemctl show`, `ls`, `md5sum`, `grep` constants, `mysql` `SHOW`/`DESCRIBE`/`SELECT` only.  
**Not performed:** restarts, writes, mail send/delete, schema changes.

| Fact | Observation |
|------|-------------|
| `mail-proxy` systemd | **active**, **enabled**; `User=vmail`; ExecStart=`/opt/delta-transit/venv/bin/python3 /usr/local/bin/mail-proxy-daemon.py` |
| Deployed daemon | `/usr/local/bin/mail-proxy-daemon.py`, 1754 lines, md5 `f77a6c3e…`, dated 2026-09-08 |
| Repo daemon | 1755 lines, md5 `04449855…` — **slightly newer/different** than VPS; routing architecture same class |
| Deploy tree git | `/root/Proxy_Email` at `960f8da` (older than this branch’s `18c6858`) |
| Worker constants | IMAP/SMTP workers 20/20; queues 5000/1000; DB pool 12; poll 60s — match anchor |
| Schema | Same six tables; no relationship columns; `external_accounts.client_id` is OAuth **client id string**, not FK |
| Sample data | Referent 3: `refloc1@testvps.loc` + client `clientloc1@testvps.loc` + ext `refint1@frona.ru`; Referent 4: client only, no external account |

**Separation:** Repository analysis drives architectural conclusions; VPS confirms production-like deployment still runs the **referent-centric relay** schema and service topology.

---

## 14. Target architecture proposal (not implemented)

```text
                 +----------------------+
                 |   Transport Layer    |
                 | ProxyDaemon / pools  |
                 +----------------------+
                    /                \
                   v                  v
             External IMAP         Local Maildir(s)
             ImapPoller            Observer (per local_client path)
                  |                    |
                  v                    v
             IMAP workers         Maildir handlers
                  |                    |
                  v                    v
             Inbound queue        Outbound queue
                  |                    |
                  v                    v
        +-------------------+  +-------------------+
        | Inbound Router    |  | Outbound Router   |
        | (new/rework in    |  | (new/rework in    |
        |  MailHandler)     |  |  MailHandler)     |
        +-------------------+  +-------------------+
                  |                    |
                  v                    v
        RelationshipLookup    RelationshipLookup
        (DB: 4 addresses)     (DB: 4 addresses)
                  |                    |
                  v                    v
        MimeTransform         MimeTransform
        extract+construct     extract+construct
                  |                    |
                  v                    v
             Local SMTP           External SMTP
             (_stream_*)          (send_via_* connect/auth)
```

### Module mapping

| Target layer | Current module |
|--------------|----------------|
| Transport / lifecycle | `ProxyDaemon`, systemd unit |
| External IMAP | `ImapPoller`, IMAP methods in `MailHandler` |
| Local Maildir watch | `MaildirHandler` + `Observer` (**repoint paths**) |
| Queues / workers | `ImapWorkerPool`, `SmtpWorkerPool`, `queue.Queue` |
| Relationship lookup | **New** helper used by inbound/outbound (replaces `_resolve_local_recipients` / `_load_first_account`) |
| MIME transform | **New** methods/module; feed `_stream_file_via_smtp` / external send |
| Credentials | `Cryptor`, `get_oauth2_token` |
| Config UI | `web/index.php` forms + `maildir_resolver.php` |

---

## 15. Recommended implementation sequence

Dependency-driven order (safer than “code first”):

1. **Finalize target data model** (four addresses + 1:1 external mailbox per Client + activation rules) — blocks everything.
2. **Introduce `RelationshipLookup` abstraction** (pure DB API, feature-tested) — no mail side effects.
3. **Panel/DB incremental migration + UI** so operators can enter the Ivan/Client1/Client2 model — daemon cannot be validated without data.
4. **MIME transform library** (extract + construct) with unit tests on fixtures — shared by both directions.
5. **Inbound Router** (From lookup, spam delete, local inject) behind clear success/`\Seen` semantics.
6. **Outbound Router** (watch local Client Maildirs, To lookup, external construct/send, no-send guards).
7. **Activation/integrity enforcement** in panel + daemon sync.
8. **Automated routing tests** (unit + staging fixtures).
9. **VPS integration testing** (controlled mailboxes).
10. **Migration/rollout** of existing referent/client/account rows with operator confirmation.

**Why not daemon-first:** Without the relationship schema and panel entry, inbound/outbound algorithms cannot be configured or verified. **Why MIME before wiring both directions:** same transform is the shared riskiest new code.

---

## 16. Explicit answer to the central question

### **B. YES, BUT — feasible with substantial internal rework**

**Technical reasons:**

1. **Transport topology already matches** the approved high-level flow (IMAP workers / Maildir watcher / queues / local+external SMTP).
2. **Business logic inside `MailHandler` and watch registration is the wrong algorithm** (relay + referent-centric keys), not a small parameter tweak — hence **not A**.
3. **Nothing fundamental about imaplib/smtplib/watchdog/queues prevents** attachment extraction, new message construction, per-relationship account selection, or spam deletion — hence **not C**.
4. **Schema can be extended incrementally**; OAuth/crypto/panel auth can survive.
5. Risk of a greenfield rewrite would discard proven P4 size guards, OAuth refresh, streaming SMTP, backlog idempotency, and systemd hardening for little architectural gain.

---

## 17. Risks and assumptions

### Risks

| Risk | Severity |
|------|----------|
| Underestimating MIME edge cases (multipart/alternative, inline, signed/encrypted mail) | High |
| Postfix rejecting envelope From=`local_client` if unlisted | High (ops) |
| Watching many Client Maildirs (inotify watches / sync complexity) | Medium |
| Ambiguous migration of existing `clients.email` role (local vs external) | High (data) |
| Spam delete irreversibility if From parsing false-negative | High |
| Dual-write period bugs if old relay and new transform coexist | High |
| VPS/repo daemon drift during development | Medium |

### Assumptions

- Approved customer spec in this prompt / PROMPT-51 §§1–4 is authoritative; U1–U4 historical options are superseded and not reopened.
- “Attachment only” means bodies are not copied unless later specified.
- Local mailboxes for `local_client` and `local_referent` continue to be provisioned **outside** the proxy (iRedMail), as today.
- External providers allow IMAP delete/expunge for spam path.
- Engineering reuse percentages above are **estimates**, not measured coverage.

---

## 18. What should NOT be rewritten

```text
DO NOT REWRITE unless evidence later requires it:
- IMAP transport (connect, STARTTLS/SSL, SEARCH/FETCH scaffolding)
- SMTP transport (connect, STARTTLS/SSL, AUTH plain/XOAUTH2, chunked DATA)
- OAuth2 token read/refresh persistence
- Worker pool mechanism (ImapWorkerPool / SmtpWorkerPool)
- In-memory queue mechanism and backpressure behavior
- Maildir watchdog Observer mechanism (repoint, don’t replace)
- Outgoing file reservation / backlog rescan idempotency pattern
- Temp spool directory model
- Cryptor + key file permission model
- Logging + SIGUSR1 log reopen
- systemd service user (vmail) and www-data isolation from Maildir
- Panel auth (panel_admins master/admin) as an orthogonal concern
- P4 inbound size-guard approach
```

---

## 19. Report integrity

| Check | Result |
|-------|--------|
| Production code changed | **NO** |
| Database data/schema changed | **NO** |
| Configuration / systemd changed | **NO** |
| Services restarted | **NO** |
| Mail sent/deleted | **NO** |
| Maildirs modified | **NO** |
| Only deliverable | `docs/reports/PROMPT-52-architecture-gap-analysis.md` |
| Pre-existing dirty/untracked tree | Preserved (not cleaned/stashed) |

---

## 20. Sources used

1. `mail-proxy-daemon.py` (repo) — primary architecture trace  
2. `docs/DELTA-transit_anchor.md`  
3. `docs/Ckeck-list_00.md`  
4. `schema.sql` + VPS `DESCRIBE`/`SHOW TABLES`  
5. `tests/*`, `test_large_attachment.py`  
6. `docs/reports/PROMPT-51-…`, PROMPT-48/49/50 (context; customer model from approved spec)  
7. Read-only SSH inspection of test VPS `192.168.125.116`  
8. `web/index.php`, `web/includes/maildir_resolver.php`, `web/includes/panel_migration.php`
