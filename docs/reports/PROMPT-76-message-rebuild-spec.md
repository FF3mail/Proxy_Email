# PROMPT-76.1 — Message transformation specification: inbound fan-out + outbound 1:1 rebuild

**Branch:** `prompt-76-message-rebuild-spec` (from `origin/master` @ `88339c4`)  
**Type:** Design/specification only — **zero code changes**  
**Date:** 2026-09-15 (revision of PROMPT-76, 2026-09-14)  
**Baseline code:** `88339c412bdf40e6e0f99eea2631fc41a9931552`  
**Predecessor audits:** PROMPT-51 (gap), PROMPT-52 (architecture), PROMPT-53 (data model), PROMPT-75 (relationship_live pilot)  
**Revision trigger:** Customer closed CQ-1…CQ-12 interactively; CQ-2 revealed inbound is **1:N fan-out**, not 1:1 transformation.

---

## Response format (mandatory)

### 1. Revised algorithm description — diffed against PROMPT-76

#### 1.1 What PROMPT-76 assumed (superseded for inbound)

PROMPT-76 framed rebuild as **message-in / message-out**: one internet message → one rebuilt local RFC822 with zero or more attachment parts bundled together. Section 3.2 said multiple attachments go into **one** rebuilt message; the test matrix (T4) expected **three parts in one message**.

#### 1.2 What this revision specifies

| Direction | Shape | Summary |
|-----------|-------|---------|
| **Inbound** | **1:N fan-out** | One internet message with N attachable parts → **N separate** local RFC822 messages, each with **exactly one** attachment |
| **Outbound** | **1:1** | One local Maildir message (already guaranteed single-attachment by house convention) → one rebuilt external RFC822 |

Inbound and outbound are **structurally distinct algorithms** — do not unify them into a shared message-in/message-out description.

#### 1.3 Inbound algorithm (normative — `relationship_live` + rebuild gate)

```text
External Referent mailbox → IMAP fetch one UNSEEN message
  → identify Client by From (relationship lookup — unchanged from today)
  → unknown From → skip (interim: mark Seen, no delivery — PROMPT-78 scope)
  → known → DB: ClientRelationshipDTO (local_client, local_referent, …)
  → parse MIME; collect attachable parts (Content-Disposition: attachment only)
  → N = count of attachable parts
       N = 0  → FAIL CLOSED (no delivery, UNSEEN, loud log)
       N ≥ 1  → for each part i ∈ 1..N:
                    build rebuilt RFC822ᵢ:
                      From = local_client_email
                      To   = local_referent_email   (Cc dropped)
                      Subject = filenameᵢ (with extension) of this part only
                      Date, Message-ID, References — all regenerated (per part)
                      body = empty text/plain + exactly one attachment part
                    (all N must rebuild successfully before any SMTP — see §4.3)
  → deliver rebuilt RFC822₁ … rebuilt RFC822ₙ sequentially via local SMTP
  → IMAP \Seen only when entire fan-out completes successfully (see §4.3)
```

**Per original internet message (once):** routing lookup, MIME parse, attachable-part enumeration, fan-out orchestration, IMAP Seen policy.

**Per fan-out child i (repeated N times):** header regeneration, single-attachment rebuild, SMTP DATA, per-child success/failure logging.

**House convention (customer-confirmed, inbound only):**

- Original internet `Subject` lists attachment filename(s) space-separated — human-readable only; **never copied** to fan-out children.
- Inline parts (embedded images, HTML signatures) are **discarded**, never extracted.
- `message/rfc822` / `.eml` nested forwards **do not occur** in real usage; encountering one is CQ-9 fail-closed (same as any malformed MIME).
- Password-protected archives: password handling is out of scope; daemon passes attachment bytes through without inspection.

#### 1.4 Outbound algorithm (normative — `relationship_live` + rebuild gate)

```text
local Client mailbox → Maildir pickup (watchdog)
  → identify Client by To (relationship lookup — unchanged)
  → unknown → must not send externally (fail closed)
  → known → DB: ClientRelationshipDTO (external_client, external_referent, …)
  → parse MIME; expect exactly one attachable part (house convention guarantee)
       0 attachable parts → FAIL CLOSED (file stays in new/, loud log)
       >1 attachable parts → FAIL CLOSED (unexpected; no fan-out on outbound)
       1 attachable part → build one rebuilt RFC822:
         From = external_referent_email
         To   = external_client_email   (Cc dropped)
         Subject = attachment filename (with extension)
         Date, Message-ID, References — all regenerated
         body = empty text/plain + exactly one attachment part
  → deliver via external SMTP
  → delete Maildir source file only on SMTP 250 (unchanged discipline)
```

Outbound does **not** fan out. A locally originated message is already single-attachment before the daemon sees it.

#### 1.5 Diff summary (PROMPT-76 → PROMPT-76.1)

| Area | PROMPT-76 | PROMPT-76.1 |
|------|-----------|-------------|
| Inbound shape | 1:1 (all attachments in one rebuilt message) | **1:N fan-out** (one attachment per rebuilt message) |
| Outbound shape | 1:1 (unchanged intent, now explicit) | **1:1** (explicit; no fan-out) |
| Subject | Open (CQ-6) | **Regenerated = attachment filename per fan-out child** |
| Multiple attachments | Bundle into one message (CQ-2 open) | **Fan out; all kept** |
| Zero attachments | Open (CQ-1) | **Fail closed** |
| Inline parts | Open (CQ-3) | **Discarded** |
| Nested .eml | Open (CQ-12) | **Fail closed (CQ-9 class)** |
| Atomicity | Single-message (implicit) | **Fan-out orchestration rules (§4.3, RD-13)** |
| Open questions | CQ-1…CQ-12 | **All closed — §7** |

---

### 2. Current-code re-verification (unchanged from PROMPT-76 §1)

**Verdict:** PROMPT-51’s core finding **still holds** on `88339c4`. PROMPT-53–75 changed **routing** (which relationship/account/mailbox a message uses) but **never changed message CONTENT**. Both directions still stream the **original RFC822 bytes** through SMTP DATA.

#### Inbound path (relationship_live included)

| Step | Behavior | Evidence (`mail-proxy-daemon.py`) |
|------|----------|-----------------------------------|
| Fetch | Full `RFC822` → temp file | L714–757 |
| Routing | `plan_inbound_delivery()` selects `local_rcpts`, `mail_from` | L792–803; `relationship_routing.py` L106–162 |
| Delivery | **`_stream_file_via_smtp(..., mail_file)`** — original file bytes | L845–847 |
| DATA | Binary stream of `mail_file` with dot-stuffing only | L881–938 |

Under `relationship_live`, only the **SMTP envelope** (`MAIL FROM` / `RCPT TO`) changes to relationship targets; **RFC822 headers and body inside DATA are unchanged** from the external message.

#### Outbound path (relationship_live included)

| Step | Behavior | Evidence |
|------|----------|----------|
| Pickup | Maildir file in referent/relationship watch path | `ProxyDaemon._enqueue_outbound_file` L1869+ |
| Routing | `plan_outbound_delivery()` selects `dto.account` | L1903–1911 |
| Envelope | `MAIL FROM = acc['email']` (external referent mailbox) | L1054 |
| Recipients | `RCPT TO` = addresses parsed from original message **`To`/`Cc`** | L1888–1891, L1062–1068 |
| DATA | **Original Maildir file bytes** streamed | L1087–1107 |

**What changed since PROMPT-51 (must not be conflated with rebuild):**

| Area | PROMPT-51 era | `88339c4` today |
|------|---------------|-----------------|
| Inbound Client ID | `To`/`Cc` vs `clients.email` | **`From`** via `RelationshipLookup.resolve_inbound()` when `relationship_live` |
| Inbound RCPT | Single `referents.local_inbox` | **`clients.local_referent_email`** per relationship when `relationship_live` |
| Outbound account | `LIMIT 1` per referent | **`clients.external_account_id`** when `relationship_live` |
| Outbound RCPT | Raw header `To`/`Cc` | **Still raw header `To`/`Cc`** — not mapped to `external_client_email` |
| Message body | Original RFC822 stream | **Still original RFC822 stream** |

Anchor doc §15 references this report. Rebuild remains **not yet implemented** in code.

---

### 3. Primary-source quote/reference (PROMPT-76 §2 — condensed)

#### 3.1 `docs/DELTA_transit_admin_guide.pdf`

**Finding:** Silent on rebuild semantics (size limits only). Cannot answer transformation edge cases.

#### 3.2 Approved customer algorithm (repository chain — inbound superseded by §1.3)

PROMPT-52 §3.2–3.3 established bidirectional rebuild intent. **Inbound** is now refined by customer confirmation: fan-out replaces «extract attachment(s) → NEW local RFC822» as a single message. **Outbound** remains aligned with PROMPT-52 §3.3 (single rebuilt message).

**Four-address data model (PROMPT-53 §5 — unchanged):**

| Logical address | Column |
|-----------------|--------|
| External client (inbound **From** key) | `clients.external_client_email` |
| Local client (rebuilt **From** inbound / outbound pickup key) | `clients.local_client_email` |
| Local referent (rebuilt **To** inbound) | `clients.local_referent_email` |
| External referent (polled mailbox / rebuilt **From** outbound) | `external_accounts.email` via `clients.external_account_id` |

#### 3.3 Phrase «часто нужны только вложения»

Not found verbatim in repository primary sources. Customer interactive confirmation (CQ-1…CQ-12) is now authoritative via §7.

---

### 4. Resolved transformation rules (former item 3 sub-questions)

All twelve customer questions are **closed**. Normative answers are in §7 (verbatim decision table). This section records **how each rule applies** in the fan-out vs 1:1 model.

#### 4.0 Directionality

| Scope | Rule |
|-------|------|
| **Both directions** | Rebuild activates under `relationship_live` (CQ-11). Shadow/legacy remain raw stream. |
| **Inbound only** | 1:N fan-out, Subject = per-child filename, multiple-attachment handling. |
| **Outbound only** | 1:1, single-attachment expectation, no fan-out. |

#### 4.1 No attachments / no attachable parts (CQ-1, CQ-7)

| Direction | N = 0 attachable parts | Outcome |
|-----------|------------------------|---------|
| Inbound | After parse, before fan-out | Fail closed — no local delivery; IMAP **UNSEEN**; `[MESSAGE_REBUILD] zero_attachments` |
| Outbound | After parse | Fail closed — Maildir file **retained** in `new/`; `[MESSAGE_REBUILD] zero_attachments` |

No empty-body rebuild. No fallback to full original relay.

#### 4.2 Multiple attachments (CQ-2) — inbound only

All attachable parts are kept via **fan-out** into N separate local messages. No bundling, no whitelist/filtering.

| Scope | Behavior |
|-------|----------|
| Per original message | Enumerate all `Content-Disposition: attachment` parts → N |
| Per fan-out child | Exactly one of those parts in the rebuilt MIME |

#### 4.3 Inline parts (CQ-3)

Discarded entirely at parse time. Never promoted to attachable parts. Applies both directions (inbound fan-out enumeration and outbound single-part extraction).

#### 4.4 Envelope / From (CQ-4)

**Never rewritten to a fixed address.** Rebuilt RFC822 `From:` is always the natural local mailbox of the actual sender:

| Direction | Rebuilt `From:` | Source |
|-----------|-----------------|--------|
| Inbound | `clients.local_client_email` | Per fan-out child (same value for all N children of one relationship) |
| Outbound | `external_accounts.email` | Single rebuilt message |

SMTP envelope `MAIL FROM` follows the same natural-sender rule per direction (implementation PROMPT must satisfy Postfix/iRedMail `reject_unlisted_sender` — no fixed rewrite).

#### 4.5 Cc (CQ-5)

Always dropped. Rebuilt message has **`To:` only** — per fan-out child (inbound) or per outbound message.

#### 4.6 Subject / Date / Message-ID / References (CQ-6)

**All regenerated, never carried from the original.** Applies **per fan-out child** (inbound) or **per outbound message**.

| Header | Inbound (per child i) | Outbound |
|--------|----------------------|----------|
| `Subject:` | Filename (with extension) of attachmentᵢ | Filename (with extension) of the single attachment |
| `Date:` | Injection time (each child may differ by milliseconds) | Injection time |
| `Message-ID:` | New unique ID per child | New unique ID |
| `In-Reply-To` / `References` | Omitted / not set | Omitted / not set |

The original internet `Subject` (space-separated filename list) is **never** copied.

#### 4.7 Body when attachment present, no text (CQ-8)

Include an **empty `text/plain`** part alongside the attachment in `multipart/mixed` — not a bare attachment-only multipart. Applies per fan-out child and per outbound message.

#### 4.8 Malformed / unparseable MIME, nested forward (CQ-9, CQ-12)

Strict **fail closed** always. No raw relay fallback, even under an operator flag.

| Condition | Handling |
|-----------|----------|
| MIME parse failure | No delivery; inbound UNSEEN / outbound file retained |
| `message/rfc822` / `.eml` attachment encountered | Same fail-closed path as any parse failure — **no distinct log category** |
| Unexpected outbound multi-attachment | Fail closed (outbound expects N=1) |

#### 4.9 S/MIME / PGP (CQ-10)

Out of scope for v1. Fail closed if detected (same as CQ-9).

#### 4.10 Rebuild activation (CQ-11)

Coupled to `relationship_live` — no independent toggle. Referent/direction not on `relationship_live` keeps today's raw-stream behavior.

| Mode | Routing | Message body |
|------|---------|--------------|
| `shadow` / `legacy` | Legacy paths | **Original RFC822 stream** |
| `relationship_live` | Relationship lookup | **Rebuild per this spec** (inbound fan-out / outbound 1:1) |

Inbound and outbound effective modes remain independent per referent (PROMPT-73); rebuild gate follows each direction separately.

---

### 5. Failure, rollback, and fan-out atomicity (item 4 + RD-13)

Rebuild must preserve the project's **never silently drop a message** discipline (PROMPT-51 §5: *«Inbound: stays UNSEEN if SMTP fails; outbound: file kept if SMTP fails»*; anchor §3.1 interim policies).

#### 5.1 Processing pipeline — inbound fan-out

```text
[IMAP UNSEEN + temp fetch]
  → routing (once per original)
  → parse + enumerate attachable parts (once per original)
  → N=0 → FAIL (UNSEEN, no SMTP)
  → rebuild temp RFC822₁ … RFC822ₙ (all before any SMTP)
       any rebuild fail → delete all temps; FAIL (UNSEEN, no SMTP)
  → SMTP deliver child 1 … child N sequentially
       all N × SMTP 250 → mark IMAP \Seen
       any SMTP fail    → UNSEEN retained; log per failed child index + filename
  → unlink all temp files in finally
```

#### 5.2 Processing pipeline — outbound 1:1

```text
[Maildir new/ file]
  → routing (once)
  → parse + expect N=1 attachable part
  → N≠1 → FAIL (file retained)
  → rebuild one temp RFC822
  → SMTP 250 → delete Maildir source
  → SMTP fail → file retained (unchanged from today)
```

#### 5.3 Fan-out atomicity decision (RD-13) — **resolved, not open**

**Decision: all-or-nothing orchestration** (rejected: partial-success that delivers some fan-out children while others fail rebuild).

| Phase | Policy | IMAP `\Seen` / source retention |
|-------|--------|--------------------------------|
| **A — Parse / enumerate** | Once per original message | N=0 → fail closed, UNSEEN |
| **B — Rebuild all N children** | **All-or-nothing:** every child must rebuild successfully **before any SMTP attempt** | Any rebuild failure → delete all temps, **zero** deliveries, UNSEEN |
| **C — SMTP deliver children 1…N** | Sequential best-effort; each SMTP 250 is **irreversible** | `\Seen` **only if all N** deliveries succeed; otherwise UNSEEN |
| **Retry** | Re-process entire original UNSEEN message | May re-deliver children already accepted in a prior attempt (see below) |

**Justification (vs partial-success):**

1. **PROMPT-51 §5 precedent:** A single-message failure leaves the source artifact intact for retry (`UNSEEN` / Maildir file). Marking `\Seen` when only k<N children delivered would **abandon** the remaining attachments with no retry path — that is silent loss of the undelivered portion.
2. **Partial-success at rebuild time** would deliver an **incomplete** attachment set (e.g. 2 of 3 filenames) without a machine-readable signal that the original had more — worse than delivering nothing and retrying the full fan-out.
3. **Never silently drop** means both: (a) do not discard the source until the operation fully succeeds, and (b) do not mark success while work remains. All-or-nothing orchestration satisfies both; partial-success at rebuild violates (b) for failed children, and partial-success with early `\Seen` violates (a) for undelivered children.
4. **Delivery-phase partial SMTP:** Already-delivered children cannot be rolled back after SMTP 250. Retaining UNSEEN when k<N succeed ensures the **failed** children are retried. The trade-off — possible **duplicate** local delivery of children 1…k−1 on retry — is an explicit, logged operational artifact (`[MESSAGE_REBUILD] fanout_incomplete delivered=N_ok failed=N_fail retry_will_duplicate_ok=true`). Duplicates are preferable to silently losing failed attachments or marking `\Seen` prematurely.

**Not escalated to customer:** The duplicate-on-retry artifact follows directly from IMAP UNSEEN-as-retry-anchor + irreversible SMTP 250, the same at-least-once class as today's single-message inbound retry. No new customer question required.

#### 5.4 Rollback of rebuild feature itself

| Mechanism | Effect |
|-----------|--------|
| Revert referent to `shadow`/`legacy` routing | Raw RFC822 stream returns after daemon restart (PROMPT-73 pattern) |
| Independent `message_rebuild_mode=off` while staying `relationship_live` | **Not in v1** (CQ-11 closed: coupled to `relationship_live`) |

Rebuild failure must **never** fall back to silent raw relay under `relationship_live`.

---

### 6. Test matrix (item 6)

Isolated function/module tests (e.g. `rebuild_inbound_fanout(raw_bytes, dto) → FanoutRebuildResult` and `rebuild_outbound_message(raw_bytes, dto) → RebuildResult`) with **synthetic fixtures**. Separate entry points reflect inbound vs outbound shape difference.

#### 6.1 Inbound fan-out tests

| ID | Fixture | Gate | Expected outcome |
|----|---------|------|------------------|
| **F1** | `multipart/mixed`: text/plain + **one** `attachment` PDF | `relationship_live` | **N=1:** one rebuilt message; From=`local_client_email`, To=`local_referent_email`; Subject=`doc.pdf`; one attachment + empty `text/plain` |
| **F2** | `multipart/mixed`: text/plain + **three** attachments (pdf, docx, png) | `relationship_live` | **N=3:** three **separate** rebuilt messages; each Subject = that file's filename; each contains exactly one attachment; shared From/To |
| **F3** | Same as F2 | `relationship_live` | Assert original multi-filename Subject **not** present on any child |
| **F4** | Single `text/plain` only, no attachment | `relationship_live` | **N=0:** fail closed; `FanoutRebuildResult.success=false`; zero SMTP calls |
| **F5** | Three attachments; **rebuild fails on child 2** (e.g. oversize / IO error injected) | `relationship_live` | **RD-13:** zero deliveries (children 1 and 3 not sent); UNSEEN; per-child failure logged |
| **F6** | Three attachments; all rebuild OK; **SMTP fails on child 2** | `relationship_live` | Child 1 delivered; child 2 failed; child 3 not attempted or failed per implementation order; UNSEEN; `[MESSAGE_REBUILD] fanout_incomplete` |
| **F7** | F6 state; **retry same original** | `relationship_live` | Documents duplicate delivery of child 1 (acceptable per RD-13); eventual all-3 success → `\Seen` |
| **F8** | `Content-Disposition: inline` PNG + one real attachment | `relationship_live` | **N=1** (inline discarded); only real attachment fanned out |
| **F9** | Nested `message/rfc822` (.eml) as sole part | `relationship_live` | Fail closed (CQ-9 class); no distinct forward log marker |
| **F10** | `multipart/signed` S/MIME | `relationship_live` | Fail closed (CQ-10) |
| **F11** | Truncated MIME boundaries | `relationship_live` | Fail closed; no output |
| **F12** | Raw fixture, gate **`shadow`** | `shadow` | Passthrough bytes unchanged (regression) |
| **F13** | Relationship miss (wrong From) | `relationship_live` | No rebuild attempted (routing fail-closed first) |

#### 6.2 Outbound 1:1 tests

| ID | Fixture | Gate | Expected outcome |
|----|---------|------|------------------|
| **O1** | `multipart/mixed`: text/plain + one `attachment` PDF | `relationship_live` | One rebuilt message; From=`external_referent_email`, To=`external_client_email`; Subject=filename; one attachment + empty `text/plain` |
| **O2** | Single `text/plain` only | `relationship_live` | Fail closed; file retained |
| **O3** | **Two** `attachment` parts | `relationship_live` | Fail closed (unexpected multi-attachment; no fan-out) |
| **O4** | Inline image + one attachment | `relationship_live` | One rebuilt message (inline discarded) |
| **O5** | `multipart/signed` | `relationship_live` | Fail closed |
| **O6** | Rebuilt headers | `relationship_live` | Subject=filename; new Message-ID; no Cc; Date regenerated |
| **O7** | Cross-relationship isolation | `relationship_live` | DTO A rebuild must not contain addresses from relationship B |

**Harness notes:** Golden-file comparison per rebuilt RFC822 (normalized line endings); inbound F2 asserts **count of output messages == attachment count**, not part count within one message.

---

### 7. Resolved decisions (replaces PROMPT-76 open questions)

All twelve customer questions are **closed**. Authoritative source (verbatim):

| Item | Resolution |
|------|------------|
| Architecture | Inbound is 1:N fan-out (N attachments → N single-attachment local messages). Outbound remains 1:1 (already guaranteed single-attachment). |
| No attachments / no attachable parts (CQ-1/CQ-7) | Fail closed — do not deliver, log loudly. No empty-body rebuild, no fallback to full original. |
| Multiple attachments (CQ-2) | All are kept — via fan-out into separate messages, not bundled and not filtered/whitelisted. |
| Inline parts — embedded images, HTML signatures (CQ-3) | Discarded entirely, never extracted as attachments. |
| Envelope/From (CQ-4) | Never rewritten to a fixed address — always the natural local mailbox of the actual sender (local_client_email if originating from the client, local_referent_email if from the referent). |
| Cc (CQ-5) | Always dropped. Rebuilt message has To only. |
| Subject / Date / Message-ID / References (CQ-6) | All regenerated, never carried over from the original. Subject specifically = the filename (with extension) of that single fan-out message's own attachment — not the original multi-file subject line. |
| Body when attachment present, no text (CQ-8) | Include an empty `text/plain` part alongside the attachment (not a bare attachment-only multipart) — for mail client compatibility. |
| Malformed/unparseable MIME, including any unexpected nested/forwarded message (CQ-9, CQ-12) | Strict fail-closed always — never fall back to raw relay, even under an operator flag. No distinct handling or logging category for "forwarded" vs. any other malformed case. |
| S/MIME / PGP (CQ-10) | Out of scope for v1. Fail closed if encountered (same as CQ-9). |
| Rebuild activation (CQ-11) | Coupled to `relationship_live` — no independent toggle. A referent/relationship not on relationship_live keeps today's raw-stream behavior unchanged. |

**Additional design decision (fan-out atomicity — not a CQ item):**

| Item | Resolution |
|------|------------|
| Fan-out atomicity (RD-13) | All-or-nothing orchestration: rebuild all N children before any SMTP; any rebuild failure delivers nothing; `\Seen` only when all N SMTP deliveries succeed; UNSEEN retry may duplicate already-delivered children (logged). See §5.3. |

---

### 8. Explicit non-goals (unchanged)

| ID | Non-goal | Deferred PROMPT |
|----|----------|-----------------|
| NG-1 | Spam / unknown-sender **deletion** policy & IMAP EXPUNGE | PROMPT-78 |
| NG-2 | Full spec reconciliation (all PROMPT-51 gaps) | PROMPT-79 |
| NG-3 | Implementation code / tests | PROMPT-77 (follow-up) |
| NG-4 | New DB address columns | Reuse PROMPT-53 four-address model |
| NG-5 | Changing routing lookup rules | Already shipped in relationship_live |
| NG-6 | Archive password extraction / decryption | External applications; out of scope |
| NG-7 | Fan-out duplicate suppression on IMAP retry | Future hardening if duplicates prove operationally unacceptable |

---

### 9. Implementation pointer (for PROMPT-77, not this PROMPT)

Suggested insertion point: new module (e.g. `message_rebuild.py`) with **two entry points**:

- `rebuild_inbound_fanout(raw_bytes, dto) → FanoutRebuildResult` — returns 0..N temp paths + metadata
- `rebuild_outbound_message(raw_bytes, dto) → RebuildResult` — returns 0..1 temp path

Called from `_deliver_to_local_smtp` / `send_via_external_smtp` **after** `plan_*_delivery` succeeds and effective mode is `relationship_live`, **before** `_stream_file_via_smtp`. Inbound orchestrator loops N SMTP calls; outbound calls once.

---

## Report integrity

| Check | Result |
|-------|--------|
| Production code changed | **NO** |
| Specification only | **YES** |
| PROMPT-76 structurally revised (not appended) | **YES** |
| Fan-out inbound / 1:1 outbound distinguished | **YES** |
| CQ-1…CQ-12 closed (verbatim table §7) | **YES** |
| RD-13 atomicity decided with justification | **YES** |
| Anchor doc §15 update required | **YES** (see companion edit) |
