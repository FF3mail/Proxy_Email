# PROMPT-51 — Customer Specification vs Current Implementation Audit

**Date:** 2026-09-09  
**Branch:** `prompt-47-panel-authorization-audit`  
**Commit:** `18c6858ddd3a54539c11c48716a6144c87a1a36b`  
**Working tree (pre-audit):** modified `docs/DELTA_transit_admin_guide.pdf`; numerous pre-existing untracked files under `.keys/`, `docs/`, `__pycache__/` (unchanged by this audit)  
**Mode:** READ-ONLY audit — no production artifacts modified  
**Business baseline:** Customer specification reconstructed and approved in PROMPT-51 (sections 1–4 of the audit prompt)

---

## 1. Audit scope and method

This report compares the **approved customer bidirectional attachment-routing algorithm** against the **current executable implementation** (`mail-proxy-daemon.py`, `schema.sql`, `web/`), with independent verification of claims from PROMPT-48/49/50.

**Evidence sources used:**

| Source | Classification |
|--------|----------------|
| `schema.sql` | CODE-OBSERVED |
| `mail-proxy-daemon.py` (repo + deployed copy on test VPS) | CODE-OBSERVED |
| `web/index.php`, `web/lang/*.php` | CODE-OBSERVED |
| Test VPS `192.168.125.116` — `SELECT` / `DESCRIBE` / `SHOW INDEX` only | DB-OBSERVED |
| PROMPT-48/49/50 reports | HISTORICAL (re-verified against current source) |

**Not performed:** live mail send/delete tests (would modify mail state).

---

## 2. Database schema (section 6.1)

### 2.1 Tables involved

| Table | Role in current system | Customer concept mapping |
|-------|------------------------|--------------------------|
| `referents` | One row per referent; holds **one** `local_inbox`, **one** `local_outbox` (Maildir path), `active` | Partial: referent exists, but only **one** local mailbox per referent — not per Client relationship |
| `clients` | Rows linked to `referent_id`; single column `email` | Partial: stores **one** address per row; **no** separate local client / local referent / external referent columns |
| `external_accounts` | IMAP/SMTP credentials linked to `referent_id` only | External referent **mailbox** exists, but scoped to **referent**, not to individual Client |
| `oauth_tokens` | OAuth for `external_accounts` | N/A to routing model |
| `oauth_providers` | Provider metadata | N/A |
| `panel_admins` | Web panel operators | N/A |

### 2.2 Schema evidence (`schema.sql`)

**`referents`**

```sql
CREATE TABLE IF NOT EXISTS referents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL,
    local_inbox VARCHAR(255) UNIQUE NOT NULL,
    local_outbox VARCHAR(255) UNIQUE NOT NULL,
    active TINYINT(1) DEFAULT 1,
    ...
);
```

**`clients`**

```sql
CREATE TABLE IF NOT EXISTS clients (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) UNIQUE NOT NULL,
    referent_id INT UNSIGNED NOT NULL,
    active TINYINT(1) DEFAULT 1,
    FOREIGN KEY (referent_id) REFERENCES referents(id) ON DELETE CASCADE
);
```

**`external_accounts`**

```sql
CREATE TABLE IF NOT EXISTS external_accounts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    referent_id INT UNSIGNED NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    ...
    FOREIGN KEY (referent_id) REFERENCES referents(id) ON DELETE CASCADE
);
```

### 2.3 Keys, uniqueness, cardinality (DB-OBSERVED on VPS + `schema.sql`)

| Question | Current schema / DB | Evidence |
|----------|---------------------|----------|
| One Referent → many Clients? | **Allowed by schema** (no `UNIQUE(referent_id)` on `clients`); **UI enforces one** | `SHOW INDEX FROM clients` → indexes on `PRIMARY`, `email` (UNIQUE), `referent_id` (non-unique); `web/index.php` `handleReferentSave()` upserts single row per `referent_id` |
| One Client → many external accounts? | **No** — `external_accounts` has no `client_id` | `schema.sql` L27–47 |
| One external mailbox → many Clients? | **No direct link** — accounts attach to `referent_id` only | Same |
| Where is **local client address** stored? | **Not stored** | No column in any table |
| Where is **external client address** stored? | `clients.email` (single field; semantics used as inbound `To`/`Cc` match key in daemon) | `mail-proxy-daemon.py` L829–832 |
| Where is **local referent address** stored? | `referents.local_inbox` — **one per referent**, not per Client | `schema.sql` L8–9 |
| Where is **external referent mailbox** stored? | `external_accounts.email` (+ IMAP/SMTP settings) — **per referent**, not per Client | `schema.sql` L27–30 |

### 2.4 VPS sample data (DB-OBSERVED, no secrets)

Test VPS `mail_proxy` at audit time:

| referent_id | local_inbox | client_email (`clients.email`) | external_account |
|-------------|-------------|-------------------------------|------------------|
| 3 | `refloc1@testvps.loc` | `clientloc1@testvps.loc` | `refint1@frona.ru` |
| 4 | `refloc2@testvps.loc` | `clientloc2@testvps.loc` | *(none)* |

Two referents each with one client — **not** one referent with two client relationships as in the customer Ivan example.

---

## 3. Inbound flow audit (section 7)

### 3.1 Execution path (CODE-OBSERVED)

```text
ImapPoller._enqueue_imap_tasks()
  → for each active referent: load ALL active external_accounts (no LIMIT)
  → ImapWorker → MailHandler.poll_external_imap(ImapTask)
       → IMAP search UNSEEN
       → fetch RFC822 → temp file
       → _deliver_to_local_smtp(temp_file, referent_data)
            → parse headers
            → _resolve_local_recipients(msg, referent_data)
            → if empty: local_rcpts = [referent_data['local_inbox']]   # fallback
            → SMTP 127.0.0.1:25
                 MAIL FROM = referent_data['local_inbox']
                 RCPT TO   = local_rcpts (always referent local_inbox when matched)
                 DATA      = original RFC822 bytes (streamed unchanged)
       → on SMTP success: IMAP STORE +FLAGS \Seen
```

### 3.2 A — Which field identifies the Client?

| Customer requirement | Current implementation | Classification |
|---------------------|------------------------|----------------|
| Identify Client by external **`From`** | **`From` is not read** for client lookup | FAIL — CODE-OBSERVED |

**Code path:** `MailHandler._resolve_local_recipients()` (`mail-proxy-daemon.py` L812–843):

- Reads **`To` and `Cc`** via `email.utils.getaddresses([to_header, cc_header])`
- SQL: `SELECT email FROM clients WHERE email = %s AND referent_id = %s AND active = 1`
- On match: appends `referent_data['local_inbox']` (not a per-client local address)

### 3.3 B — Unknown sender behavior

| Customer requirement | Current implementation | Classification |
|---------------------|------------------------|----------------|
| Unknown external sender → **delete as spam** | **No `From` check**; message **always delivered** to `local_inbox` (directly or via fallback); IMAP message marked `\Seen` only after successful local SMTP | FAIL — CODE-OBSERVED |

There is **no** IMAP DELETE/EXPUNGE/spam path in `mail-proxy-daemon.py` (grep: no `delete`, `expunge`, `spam` in inbound handler).

### 3.4 C — External Client address mapping (`From: client1@partner.com`)

Daemon matches `clients.email` against **`To`/`Cc`**, not **`From`**. A message with `From: client1@partner.com`, `To: ref1@hmail.de` will match only if `clients.email` equals a **To/Cc** address (e.g. `ref1@hmail.de`), not the sender.

**Classification:** FAIL for customer semantics (identify by `From`).

### 3.5 D — Local Referent address per Client relationship

Customer requires `ref1@local.loc` vs `ref2@local.loc` per Client. Current system has **only** `referents.local_inbox` per referent. Inbound delivery RCPT is always that single inbox when any match occurs; `_resolve_local_recipients` cannot distinguish Client 1 vs Client 2 local referent mailboxes.

**Classification:** FAIL — CODE-OBSERVED + DB-OBSERVED.

### 3.6 E — New local message creation

| Aspect | Customer spec | Current behavior | Classification |
|--------|---------------|------------------|----------------|
| Create **new** message | Yes — new From/To + attachment only | **No** — streams **entire original RFC822** | FAIL |
| From | `client1@local.loc` | Envelope `MAIL FROM` = `referent.local_inbox`; original `From` header preserved in body stream | FAIL |
| To | `ref1@local.loc` (per relationship) | RCPT = `referent.local_inbox` always | FAIL |
| Attachment only | Yes | Full message forwarded (body + all parts) | FAIL |
| Local SMTP | Yes | Yes — `127.0.0.1:25` | PASS |

---

## 4. Outbound flow audit (section 8)

### 4.1 Execution path (CODE-OBSERVED)

```text
Watchdog on referent.local_outbox/new/  (MaildirHandler.on_created)
  → parse To/Cc headers only
  → recipients = raw addresses from message (no DB lookup)
  → _load_first_account(): SELECT ... FROM external_accounts
                            WHERE referent_id = %s AND active = 1 LIMIT 1
  → SmtpTask → send_via_external_smtp()
       → MAIL FROM = external_accounts.email
       → RCPT TO = recipients from local message To/Cc
       → DATA = original Maildir file bytes (unchanged)
  → on SMTP 250: delete Maildir file
```

Backlog scan `_scan_existing_outgoing()` uses the same `LIMIT 1` account selection (L1488–1498).

### 4.2 A — Client identified by local recipient (`To: client1@local.loc`)

**No Client lookup on outbound.** Recipients are taken directly from the message `To`/`Cc` headers (`mail-proxy-daemon.py` L1251–1254, L1514–1517). The daemon does **not** query `clients` to identify the Client.

**Classification:** FAIL — CODE-OBSERVED.

### 4.3 B — `From` validated against Client relationship

**Not validated.** Only `To`/`Cc` are parsed; `From` is not read in `MaildirHandler` or `send_via_external_smtp`.

**Classification:** FAIL (explicit) — CODE-OBSERVED.

### 4.4 C — External Referent mailbox from Client relationship

Outbound SMTP identity is `external_accounts.email` from **`LIMIT 1`** row for the **referent**, with **no** `ORDER BY` and **no** `client_id` linkage.

**Classification:** FAIL — CODE-OBSERVED.

### 4.5 D — External Client address from database

No mapping from local client address to external client address. If local message has `To: client1@local.loc`, external SMTP attempts RCPT to **`client1@local.loc`** on the Internet, not `client1@partner.com` from DB.

**Classification:** FAIL — CODE-OBSERVED.

### 4.6 E — Multiple candidate external accounts

| Direction | Behavior | Evidence |
|-----------|----------|----------|
| Inbound IMAP poll | **All** active accounts per referent | `_load_accounts_for_referent()` — no `LIMIT` (L1196–1206) |
| Outbound SMTP | **First** active account (`LIMIT 1`, undefined order) | `_load_first_account()` L1291–1298; `_scan_existing_outgoing()` L1494 |

**Classification:** Asymmetric — CODE-OBSERVED (matches PROMPT-48 finding; **independently verified**).

---

## 5. Attachment processing audit (section 9)

| Question | Current behavior | Evidence | Classification |
|----------|------------------|----------|----------------|
| Extract attachments? | **No** dedicated extraction | No `walk`, `get_payload`, `MIMEMultipart` in `mail-proxy-daemon.py` | CODE-OBSERVED |
| Forward whole email vs new? | **Whole RFC822** streamed both directions | `_stream_file_via_smtp()` L754–811; outbound L960–980 | CODE-OBSERVED |
| Multiple attachments? | Forwarded as part of original message | Same | CODE-OBSERVED |
| Inline parts as attachments? | No special handling | Same | CODE-OBSERVED |
| No attachment? | Message still forwarded | No attachment gate in code | CODE-OBSERVED |
| Malformed MIME? | Outbound: header parse failure → skip file; inbound: relies on SMTP/Postfix acceptance | L1244–1250, L720–746 | CODE-OBSERVED |
| Filenames/content-types preserved? | Yes (unchanged MIME) | Whole-message relay | CODE-OBSERVED |
| Message loss risk? | Inbound: stays UNSEEN if SMTP fails; outbound: file kept if SMTP fails | L705–707, L1083–1091 | CODE-OBSERVED |
| Source deleted after success? | Inbound: IMAP `\Seen` (not deleted); outbound: Maildir file deleted | L706, L1090–1091 | CODE-OBSERVED |

---

## 6. Activation and lifecycle audit (section 10)

### 6.1 Referent

| Rule (customer) | Current behavior | Evidence | Status |
|---------------|------------------|----------|--------|
| Referent without Clients may exist but **must be inactive** | Referent can be **active with zero clients**; no server-side check | `handleReferentSave()` allows empty `client_email`; `handleToggleActive()` flips bit only | FAIL |
| Activation requires ≥1 Client | **Not enforced** | Same | FAIL |
| Client can be added later; then activate | Possible manually; no coupling logic | UI + toggle | PARTIAL |
| Daemon loads `referents WHERE active = 1` | Yes | L1178–1181, L1543–1546 | PASS |

### 6.2 Client

| Operation | Current behavior | Evidence |
|-----------|------------------|----------|
| Creation | Single client per referent in UI (`INSERT` on referent save) | `handleReferentSave()` L807–851 |
| Edit | Updates single row `WHERE referent_id = ?` | L818–830 |
| Activation | `toggle_active` entity `client` | L1314–1348; button in `renderReferentRowActions()` L511–512 |
| Deletion | **No UI delete**; empty `client_email` on save does **not** delete row | L852 comment |
| Relationship | `clients.referent_id` FK | `schema.sql` |

**Note:** Schema allows multiple `clients` rows per `referent_id`, but panel reads/writes **one** (`fetch()` without ordering).

---

## 7. External mailbox lifecycle (section 11)

| Question | Customer model | Current implementation | Status |
|----------|----------------|------------------------|--------|
| One external referent mailbox per **Client relationship**? | Yes | One-to-many `external_accounts` per **referent**; no `client_id` | FAIL |
| DB constraint for exactly one per Client? | Expected | **None** | FAIL |
| Inbound polls all active accounts? | Per-client mailbox expected | **All** active per referent | PARTIAL (polls all, but not per-client model) |
| Outbound picks one account? | Per-client mapping expected | `LIMIT 1` arbitrary | FAIL |
| Symmetric account selection? | Expected | **No** — inbound all, outbound first | FAIL |

---

## 8. Panel / UI audit (section 12)

### 8.1 Configurable fields per Client relationship

Customer requires per Client:

```text
external client, local client, local referent, external referent mailbox
```

**Current referent form** (`renderReferentForm()` / `handleReferentSave()`):

| Field in UI | Maps to | Customer field |
|-------------|---------|----------------|
| `local_inbox` | `referents.local_inbox` | Referent-level local mailbox only |
| `client_email` | `clients.email` | Ambiguous — labeled «Email клиента»; daemon uses as **To/Cc** match, not `From` |
| *(none)* | — | local client address |
| *(none)* | — | per-client local referent |
| External account form (separate page) | `external_accounts.*` per **referent** | external referent mailbox (not per Client) |

**Classification:** FAIL — operator **cannot** configure the four-address Client relationship model from the panel.

### 8.2 One Client / one mailbox presentation

- `renderReferentView()` uses `LEFT JOIN clients` + `LEFT JOIN external_accounts` with **`LIMIT 1`** (L1408–1409) — shows at most one client and one external account.
- Referent list/dashboard JOINs can duplicate rows if multiple clients/accounts exist, but edit form loads **first** client only (`fetch()` L597).
- Lab VPS models **two referents** with one client each — not one referent with two clients.

### 8.3 Labels and help text accuracy

| UI text | Location | Accurate for daemon? |
|---------|----------|----------------------|
| «Демон доставляет входящие письма на локальный ящик, **если в поле «Кому» указан email корреспондента**» | `renderReferentView()` L1461–1462 | **Partially** — uses To/Cc, but **fallback delivers anyway** without match |
| «Email клиента» | `referent.client_email` | **Misleading** vs customer spec (external client identified by `From`) |
| Account list: «синхронизации с **локальным ящиком референта**» | `renderAccountList()` L439 | Matches current referent-centric model, **not** customer per-Client routing |

---

## 9. Verification of previous audit claims (section 16)

| Prior claim | Re-verified? | Current evidence |
|-------------|--------------|------------------|
| Inbound uses `To`/`Cc` for client matching | **YES** | `_resolve_local_recipients()` L816–818 |
| Falls back to `local_inbox` when no match | **YES** | `_deliver_to_local_smtp()` L728–730; present since first commit `3e2f0ec` |
| Outbound `LIMIT 1` for external account | **YES** | `_load_first_account()` L1298; since `3e2f0ec` |
| Schema allows multiple external accounts per referent | **YES** | No unique on `(referent_id)`; VPS index `referent_id` non-unique |
| UI historically one Client in referent workflows | **YES** | Single `client_email` field; `fetch()` one row; `referent_view LIMIT 1` |
| Inbound polls **all** accounts (not LIMIT 1) | **YES** | `_load_accounts_for_referent()` — no LIMIT |

**No commit found** that changed these behaviors since first commit; subsequent commits (e.g. `5a5bc2d`, `f0d6020`) touched envelope sender and IMAP size probing, not routing semantics.

Deployed daemon on VPS (`/usr/local/bin/mail-proxy-daemon.py`, dated 2026-09-08) contains the same line numbers for `_resolve_local_recipients`, `LIMIT 1`, and `\Seen` marking.

---

## 10. Conceptual configuration test (section 17)

### 10.1 Can the system represent Ivan + Client 1 + Client 2 without ambiguity?

**Answer: NO** (CODE-OBSERVED + DB-OBSERVED)

Reasons:

1. No storage for four addresses per Client relationship.
2. `referents.local_inbox` is singular — cannot hold both `ref1@local.loc` and `ref2@local.loc` for one referent.
3. `external_accounts` links to `referent_id`, not `client_id` — cannot bind `ref1@hmail.de` to Client 1 and `ref2@hmail.de` to Client 2 under one referent with integrity constraints.
4. UI cannot enter two Client blocks on one referent.

Workaround of creating **two referents** for Ivan splits the customer model across rows and still lacks local client / mapping fields.

### 10.2 Message traces (static — no mail sent)

#### Test A — inbound Client 1

| Step | Expected (customer) | Actual (current) | Result |
|------|-------------------|------------------|--------|
| Poll mailbox | `ref1@hmail.de` IMAP | Polled if configured as `external_accounts.email` for referent | PARTIAL |
| Identify Client | `From: client1@partner.com` | **`From` ignored**; `To: ref1@hmail.de` checked against `clients.email` | FAIL |
| Unknown handling | N/A | If no To/Cc match → **fallback** to `referent.local_inbox` | FAIL |
| Local delivery | New msg `client1@local.loc` → `ref1@local.loc` + A.pdf | Relay original to `referent.local_inbox`; envelope From = referent inbox; **no** address rewrite; **no** attachment extraction | FAIL |

**Code path:** `poll_external_imap` → `_deliver_to_local_smtp` → `_resolve_local_recipients`  
**DB path:** `clients.email` + `referent_id` on To/Cc only; `referents.local_inbox`

#### Test B — inbound Client 2

Same failures as Test A for a second Client under one referent (schema/UI cannot model; routing cannot target `ref2@local.loc`).

**Result:** FAIL

#### Test C — unknown inbound sender

| Expected | Actual | Result |
|----------|--------|--------|
| DELETE AS SPAM | Delivered to `referent.local_inbox`; marked `\Seen` on SMTP success | FAIL |

**Code path:** No `From` validation branch; fallback L728–730 always delivers.

#### Test D — outbound Client 1

| Step | Expected | Actual | Result |
|------|----------|--------|--------|
| Discover message | Local client mailbox | Watchdog on **`referent.local_outbox`/new** (referent Maildir), not `client1@local.loc` mailbox | FAIL |
| Identify Client | `To: client1@local.loc` | Recipients passed through; **no** `clients` lookup | FAIL |
| Validate From | `ref1@local.loc` | **Not checked** | FAIL |
| External mapping | `ref1@hmail.de` → `client1@partner.com` | SMTP MAIL FROM = first `external_accounts.email`; RCPT = **`client1@local.loc`** from headers | FAIL |
| New Internet message + attachment | Yes | Whole local file relayed | FAIL |

**Result:** FAIL

---

## 11. Comparison matrix (section 14)

| ID | Customer requirement | Current implementation | Evidence | Status |
|----|----------------------|------------------------|----------|--------|
| IN-01 | Identify inbound Client by external **From** | Uses **To/Cc** vs `clients.email`; `From` unused | `mail-proxy-daemon.py` L812–836 | **FAIL** |
| IN-02 | Unknown sender deleted as spam | Always delivered; IMAP marked Seen on success | L728–730, L706; no DELETE | **FAIL** |
| IN-03 | External Client (`From`) maps to Client | `clients.email` matched on To/Cc only | L829–832 | **FAIL** |
| IN-04 | Client determines **local Client** address | No local client address column | `schema.sql` | **FAIL** |
| IN-05 | Client relationship determines **local Referent** address | Single `referents.local_inbox` per referent | `schema.sql`, L836 | **FAIL** |
| IN-06 | Create **new** local message | Streams original RFC822 | L741–742, L787–804 | **FAIL** |
| IN-07 | Attach extracted attachment only | Full MIME relay | No extraction code | **FAIL** |
| IN-08 | Deliver through local mail system | SMTP to `127.0.0.1:25` | L737–742 | **PASS** |
| OUT-01 | Local **recipient** identifies Client | No DB lookup; raw To/Cc to external SMTP | L1251–1254 | **FAIL** |
| OUT-02 | Local Referent relationship validated (`From`+`To`) | `From` not validated | MaildirHandler | **FAIL** |
| OUT-03 | Local Client maps to external Client | No mapping; RCPT = local address | L1254, L936 | **FAIL** |
| OUT-04 | Local Referent maps to external Referent mailbox | `LIMIT 1` account per referent | L1291–1298 | **FAIL** |
| OUT-05 | Create new Internet message | Streams original file | L960–980 | **FAIL** |
| OUT-06 | Attach extracted attachment only | Full MIME relay | No extraction code | **FAIL** |
| OUT-07 | Send through external SMTP | Yes, with auth | `send_via_external_smtp()` | **PASS** |
| DATA-01 | One Referent → many Clients | Schema yes; UI one client; routing not per-client | `schema.sql`, `index.php` | **PARTIAL** |
| DATA-02 | Each Client has exactly one external Referent mailbox | Accounts per referent; no client link | `external_accounts` | **FAIL** |
| DATA-03 | Local/external addresses may differ (DB explicit) | Only `clients.email` + `referents.local_inbox`; no local client field | `schema.sql` | **FAIL** |
| LIFE-01 | Referent with zero Clients inactive | Can be active | `handleReferentSave`, toggle | **FAIL** |
| LIFE-02 | Referent requires ≥1 Client to activate | Not enforced | `handleToggleActive` | **FAIL** |
| ATT-01 | Attachment extraction / relay | Whole-message relay only | `mail-proxy-daemon.py` | **FAIL** |
| UI-01 | Configure four addresses per Client | Not possible | `renderReferentForm` | **FAIL** |
| UI-02 | Multiple Clients per Referent in UI | Single client section | `index.php` L674–696 | **FAIL** |
| EXT-01 | Inbound polls all active external accounts | Yes | `_load_accounts_for_referent` | **PASS** |
| EXT-02 | Outbound symmetric account selection | `LIMIT 1` vs poll-all | L1298 vs L1202 | **FAIL** |

---

## 12. Final conclusions

### A. Executive conclusion

**Does the current implementation implement the customer's original bidirectional attachment-routing algorithm?**

## **NO**

The daemon implements a **referent-centric whole-message relay** between external IMAP/SMTP accounts and a **single local Maildir per referent**, with inbound discrimination on **`To`/`Cc`** (not **`From`**), unconditional inbound fallback, no address rewriting, no attachment extraction, and outbound recipient passthrough without Client/address mapping. The database and panel lack the per-Client four-address relationship model that the approved customer specification requires.

### B. Confirmed deviations

**Deviation 1 — Inbound Client identification field**

- Customer requirement: identify Client by external **`From`**; unknown sender deleted as spam.
- Current behavior: match **`To`/`Cc`** to `clients.email`; ignore `From`; always deliver to `referents.local_inbox` (fallback).
- Evidence: `mail-proxy-daemon.py` L728–730, L812–836.
- Impact: Wrong routing key; spam/unknown senders are delivered; customer Test A/C fail.

**Deviation 2 — No per-Client local/external address map**

- Customer requirement: explicit DB mapping for local client, external client, local referent, external referent per Client relationship.
- Current behavior: `referents.local_inbox` (one), `clients.email` (one field), `external_accounts` per referent.
- Evidence: `schema.sql`; VPS `DESCRIBE`.
- Impact: Cannot represent Ivan/Client1/Client2 model; non-identical local parts unsupported.

**Deviation 3 — No attachment-extraction relay**

- Customer requirement: new message with extracted attachment only.
- Current behavior: stream complete RFC822 unchanged both directions.
- Evidence: `_stream_file_via_smtp`, no MIME walk/extract.
- Impact: Headers/body preserved; not customer algorithm.

**Deviation 4 — Outbound routing**

- Customer requirement: `To` local client identifies Client; DB maps to external addresses; validate referent relationship.
- Current behavior: To/Cc sent to external SMTP as-is; `LIMIT 1` external account; no `clients` query; `From` not checked.
- Evidence: `MaildirHandler` L1251–1270, `_load_first_account` L1291–1298.
- Impact: Customer Test D fails; wrong SMTP identities/recipients.

**Deviation 5 — External account cardinality model**

- Customer requirement: one external referent mailbox **per Client relationship**.
- Current behavior: zero-to-many `external_accounts` per **referent**; outbound picks arbitrary first.
- Evidence: `schema.sql`, `LIMIT 1` queries.
- Impact: Multi-client referent cannot select correct outbound mailbox.

**Deviation 6 — Lifecycle rules**

- Customer requirement: referent without clients must be inactive; activation requires client.
- Current behavior: independent `active` toggles; no enforcement.
- Evidence: `handleToggleActive`, `handleReferentSave`.
- Impact: Active referents can run without valid routing configuration.

**Deviation 7 — UI model**

- Customer requirement: configure each Client relationship separately under one Referent.
- Current behavior: one client email field; one external account emphasized per referent view.
- Evidence: `renderReferentForm`, `renderReferentView LIMIT 1`.
- Impact: Operators cannot enter approved configuration.

### C. Confirmed matches

- External IMAP polling for active `external_accounts` with plain/OAuth auth (CODE-OBSERVED).
- Inbound size guard before full fetch (CODE-OBSERVED).
- Local injection via Postfix on `127.0.0.1:25` (CODE-OBSERVED).
- Outbound Maildir watchdog on `referent.local_outbox/new` (CODE-OBSERVED).
- External SMTP send with TLS/OAuth and streaming DATA (CODE-OBSERVED).
- Outbound Maildir file deleted only after SMTP 250 (CODE-OBSERVED).
- Inbound IMAP marked `\Seen` after successful local delivery (CODE-OBSERVED).
- Schema FK `clients.referent_id`, `external_accounts.referent_id` with CASCADE (DB-OBSERVED).
- Panel can manage referents, one client email, and multiple external account rows at DB level (CODE-OBSERVED).

### D. Unknowns

| Item | Classification |
|------|----------------|
| Exact Postfix/Dovecot rewrite of envelope vs header recipients on local injection | UNKNOWN — not traced in this audit (would require live trace without sending mail) |
| Behavior when multiple `clients` rows exist per referent (inserted via SQL bypass) | PARTIALLY CODE-OBSERVED — first To/Cc match wins, still delivers to same `local_inbox` |
| Whether lab `clients.email` values (`*@testvps.loc`) are intended as local or external addresses in operator practice | UNKNOWN — field semantics undocumented in schema |

### E. Human decisions still required

Per PROMPT-51 instruction, **U1–U4 from earlier prompts are not reopened here.** The customer specification in PROMPT-51 sections 1–4 is treated as the approved baseline for this audit.

No additional product decisions are required **to state the gap** between that baseline and current code. Implementation planning (if authorized later) is a separate phase.

### F. Implementation implications (factual summary)

If implementation work were later authorized, evidence shows these areas diverge from the approved customer model and would need alignment:

1. **Data model** — per-Client relationship storage for four explicit addresses; link external mailbox to Client, not only Referent.
2. **Inbound algorithm** — `From`-based Client lookup; spam/delete path for unknown senders; per-relationship local From/To; attachment extraction and new message composition.
3. **Outbound algorithm** — watch correct local mailboxes; Client lookup by local recipient; map to external referent/client addresses; relationship validation.
4. **External account selection** — deterministic per-Client account choice; symmetric inbound/outbound semantics.
5. **Lifecycle enforcement** — server-side rules for referent activation vs client count.
6. **Panel** — multi-Client configuration UI matching the relationship model.

The existing daemon provides reusable infrastructure (IMAP poll, OAuth refresh, SMTP streaming, Maildir watchdog, worker pools) but **does not implement** the approved routing algorithm.

---

## 13. Report integrity (section 19)

| Check | Result |
|-------|--------|
| Production code changed | **NO** |
| Database data changed | **NO** (SELECT/`DESCRIBE`/`SHOW INDEX` only on VPS) |
| Database schema changed | **NO** |
| Configuration changed | **NO** |
| Services restarted | **NO** |
| Mail sent/deleted for testing | **NO** |
| Secrets in report | **NO** |
| Commit created | **NO** |
| Push performed | **NO** |

**Pre-existing unrelated working-tree changes (unchanged):** modified `docs/DELTA_transit_admin_guide.pdf`; untracked `.keys/`, `docs/**`, `__pycache__/`, etc.

```text
Audit mode: READ-ONLY
Production code changed: NO
Database data changed: NO
Database schema changed: NO
Configuration changed: NO
Services restarted: NO
Mail sent/deleted for testing: NO
Commit created: NO
Push performed: NO
```
