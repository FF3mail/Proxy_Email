# PROMPT-76 — Message transformation specification: attachment-only rebuild + new From/To

**Branch:** `prompt-76-message-rebuild-spec` (from `origin/master` @ `88339c4`)  
**Type:** Design/specification only — **zero code changes**  
**Date:** 2026-09-14  
**Baseline code:** `88339c412bdf40e6e0f99eea2631fc41a9931552`  
**Predecessor audits:** PROMPT-51 (gap), PROMPT-52 (architecture), PROMPT-53 (data model), PROMPT-75 (relationship_live pilot)

---

## Response format (mandatory)

### 1. Current-code re-verification (item 1)

**Verdict:** PROMPT-51’s core finding **still holds** on `88339c4`. PROMPT-53–75 changed **routing** (which relationship/account/mailbox a message uses) but **never changed message CONTENT**. Both directions still stream the **original RFC822 bytes** through SMTP DATA.

#### Inbound path (relationship_live included)

| Step | Behavior | Evidence (`mail-proxy-daemon.py`) |
|------|----------|-----------------------------------|
| Fetch | Full `RFC822` → temp file | L714–757 |
| Routing | `plan_inbound_delivery()` selects `local_rcpts`, `mail_from` | L792–803; `relationship_routing.py` L106–162 |
| Delivery | **`_stream_file_via_smtp(..., mail_file)`** — original file bytes | L845–847 |
| DATA | Binary stream of `mail_file` with dot-stuffing only | L881–938 |

Under `relationship_live`, only the **SMTP envelope** (`MAIL FROM` / `RCPT TO`) changes to relationship targets; **RFC822 headers and body inside DATA are unchanged** from the external message.

```845:847:mail-proxy-daemon.py
            delivered = self._stream_file_via_smtp(
                smtp, plan.mail_from, plan.local_rcpts, mail_file
            )
```

```881:938:mail-proxy-daemon.py
    def _stream_file_via_smtp(
        self,
        server: smtplib.SMTP,
        mail_from: str,
        recipients: List[str],
        file_path: Path,
    ) -> bool:
        """Потоковая передача содержимого файла через SMTP DATA."""
        ...
        with open(file_path, 'rb') as f:
            ...
                server.sock.sendall(send_data)
        ...
        return True
```

**No MIME rebuild:** `grep` on `88339c4` `mail-proxy-daemon.py` finds **no** `walk()`, `get_payload()`, or `MIMEMultipart` in the daemon. Header parse uses `email.message_from_binary_file` (L779–780) for routing only.

#### Outbound path (relationship_live included)

| Step | Behavior | Evidence |
|------|----------|----------|
| Pickup | Maildir file in referent/relationship watch path | `ProxyDaemon._enqueue_outbound_file` L1869+ |
| Routing | `plan_outbound_delivery()` selects `dto.account` | L1903–1911 |
| Envelope | `MAIL FROM = acc['email']` (external referent mailbox) | L1054 |
| Recipients | `RCPT TO` = addresses parsed from original message **`To`/`Cc`** | L1888–1891, L1062–1068 |
| DATA | **Original Maildir file bytes** streamed | L1087–1107 |

```1053:1107:mail-proxy-daemon.py
            code, resp = server.mail(acc['email'])
            ...
            for rcpt in recipients:
                code, resp = server.rcpt(rcpt)
            ...
            with open(file_path, 'rb') as f:
                ...
                    server.sock.sendall(send_data)
```

**What changed since PROMPT-51 (must not be conflated with rebuild):**

| Area | PROMPT-51 era | `88339c4` today |
|------|---------------|-----------------|
| Inbound Client ID | `To`/`Cc` vs `clients.email` | **`From`** via `RelationshipLookup.resolve_inbound()` when `relationship_live` |
| Inbound RCPT | Single `referents.local_inbox` | **`clients.local_referent_email`** per relationship when `relationship_live` |
| Outbound account | `LIMIT 1` per referent | **`clients.external_account_id`** when `relationship_live` |
| Outbound RCPT | Raw header `To`/`Cc` | **Still raw header `To`/`Cc`** — not mapped to `external_client_email` |
| Message body | Original RFC822 stream | **Still original RFC822 stream** |

Anchor doc explicitly lists rebuild as **not yet implemented** (`docs/DELTA-transit_anchor.md` §3.1 L194–198).

---

### 2. Primary-source quote/reference (item 2)

#### 2.1 `docs/DELTA_transit_admin_guide.pdf` (audited by PROMPT-51)

**Finding:** The admin guide (v3.4 text extract, 10 pages) covers installation, limits (150 MB attachment **size**), systemd, monitoring, backup, and troubleshooting. It does **not** describe bidirectional routing, attachment-only relay, or From/To rewriting.

Relevant excerpt (size limits only — **not** a rebuild requirement):

> «Целевой лимит вложения для конечного пользователя — 150 МБ.»  
> — `docs/DELTA_transit_admin_guide.pdf`, §4

**Conclusion:** The PDF is **silent** on message-rebuild semantics. It cannot answer item 3 sub-questions alone.

#### 2.2 Approved customer algorithm (repository chain)

The rebuild requirement is **not** lost — it was reconstructed and approved in the PROMPT-51 business baseline and restated as the **approved target architecture** in PROMPT-52 §3. That chain is the strongest in-repo “primary” specification for routing + transformation intent:

**Inbound (approved target — PROMPT-52 §3.2):**

```text
External Referent mailbox → IMAP → identify Client by From
  → unknown From → delete as spam
  → known → DB: local_client, local_referent
  → extract attachment(s) → NEW local RFC822
       From=local_client  To=local_referent  attachment(s) only
  → local SMTP → local Referent mailbox
```

**Outbound (approved target — PROMPT-52 §3.3):**

```text
local Client mailbox → Maildir → watchdog → identify Client by To
  → unknown → must not send externally
  → known → DB: external_client, external_referent
  → extract attachment(s) → NEW external RFC822
       From=external_referent  To=external_client  attachment(s) only
  → external SMTP → external Client mailbox
```

**Four-address data model (PROMPT-53 §5 — authoritative column mapping):**

| Logical address | Column |
|-----------------|--------|
| External client (inbound **From** key) | `clients.external_client_email` |
| Local client (outbound pickup / rebuilt **From** inbound) | `clients.local_client_email` |
| Local referent (inbound rebuilt **To**) | `clients.local_referent_email` |
| External referent (polled mailbox / outbound rebuilt **From**) | `external_accounts.email` via `clients.external_account_id` |

#### 2.3 Phrase «часто нужны только вложения»

**Not found** in `DELTA_transit_admin_guide.pdf`, `schema.sql`, anchor doc body, or PROMPT-51/52/53 reports. It appears in the PROMPT-76 task brief (customer confirmation context) but **has no verbatim primary-source citation in this repository**. Treat it as **customer intent signal**, not as an auditable spec line — see open questions §7.

---

### 3. Answers to item 3 sub-questions

Each sub-question: **Resolved** (from approved in-repo baseline) or **Customer question** (primary source silent/ambiguous).

#### 3.0 Directionality — one-way or both?

| | Status |
|---|--------|
| **Resolved (in-repo baseline)** | **Both directions.** PROMPT-52 §3.2 (inbound) and §3.3 (outbound) each mandate `extract attachment(s) → NEW RFC822` with direction-specific From/To. The customer **example** was inbound-flavored; the **approved spec** is bidirectional. |

Implementation PROMPT must implement **both** unless the customer explicitly narrows scope in writing (would be a spec change, not an implementer default).

---

#### 3.1 Message with **no** attachment

| | Status |
|---|--------|
| **Customer question (CQ-1)** | Approved PROMPT-52 text says «extract attachment(s)» but defines **no rule** when zero MIME parts qualify as attachments. PROMPT-52 §9.3 explicitly lists «Missing attachment» as **unresolved**. |

**Do not silently default in implementation.** Candidate policies (for customer decision only):

| Option | Effect |
|--------|--------|
| A — Fail closed | Do not deliver; inbound stays UNSEEN / outbound file stays in `new/` (aligns with «attachment-only» strict reading) |
| B — Deliver empty rebuilt message | New From/To, no MIME parts (likely useless to referent) |
| C — Fall back to full-body relay | Contradicts «attachment(s) only» |

**Spec recommendation for customer review:** Option **A** (fail closed + loud log) pending explicit customer choice — matches PROMPT-52 §11 security posture, but **is not adopted as normative until CQ-1 is answered**.

---

#### 3.2 Message with **multiple** attachments

| | Status |
|---|--------|
| **Resolved (in-repo baseline)** | **Include all qualifying attachment parts** in the rebuilt message. PROMPT-52 §3 uses plural «attachment(s)»; §9.2 «Multiple attachments | Feasible | Spec says attachment(s)». |

| | Status |
|---|--------|
| **Customer question (CQ-2)** | No primary-source rule for **selection** (whitelist by MIME type, filename pattern, max count, or max aggregate size beyond existing 150 MB transport limit). Default spec: **no filtering** — all `Content-Disposition: attachment` parts (see CQ-3 for inline) — **pending customer confirmation**. |

---

#### 3.3 «New From/To» — header mapping to existing DB columns

**Resolved — use existing ClientRelationship columns only (no new schema fields for addresses).**

| Direction | Rebuilt RFC822 header | Source column | Notes |
|-----------|----------------------|---------------|-------|
| **Inbound** | `From:` | `clients.local_client_email` | Matches PROMPT-52 «From=local_client» |
| **Inbound** | `To:` | `clients.local_referent_email` | Matches PROMPT-52 «To=local_referent»; aligns with `relationship_live` RCPT today |
| **Outbound** | `From:` | `external_accounts.email` (= `ClientRelationshipDTO.external_referent_email`) | Matches PROMPT-52 «From=external_referent» |
| **Outbound** | `To:` | `clients.external_client_email` | Matches PROMPT-52 «To=external_client»; **not** today’s behavior (daemon still RCPTs original header addresses) |

**SMTP envelope (operational, separate from RFC822 headers):**

| Direction | Today (`88339c4`) | Target after rebuild (spec) |
|-----------|-------------------|----------------------------|
| Inbound `MAIL FROM` | `plan.mail_from` → `local_referent_email` under `relationship_live` | **Customer question (CQ-4):** envelope sender = `local_client_email`, `local_referent_email`, or Postfix-mandated mailbox? PROMPT-53 OQ-3 / PROMPT-52 §9.3 flag Postfix `reject_unlisted_sender` tension |
| Inbound `RCPT TO` | `local_referent_email` | Unchanged — `local_referent_email` |
| Outbound `MAIL FROM` | `external_accounts.email` | Unchanged — external referent mailbox |
| Outbound `RCPT TO` | Original `To`/`Cc` | **Must become** `external_client_email` only (single RCPT per approved model) |

`Cc`/`Bcc` on rebuilt messages: **Customer question (CQ-5)** — approved baseline specifies single To pair only.

---

#### 3.4 Subject, Date, Message-ID, In-Reply-To / References (threading)

| | Status |
|---|--------|
| **Customer question (CQ-6)** | Approved PROMPT-52 algorithm is **silent** on these headers. |

**Options for customer (not chosen in this spec):**

| Header | Preserve original | Regenerate | Hybrid |
|--------|-------------------|------------|--------|
| `Subject:` | Keeps client subject visible to referent | Loses context | Prefix e.g. `[via proxy]` |
| `Date:` | Historical accuracy | Injection time | — |
| `Message-ID:` | Threading with external side | New ID breaks cross-mailbox threading | New ID with `References` to original |
| `In-Reply-To` / `References` | Preserve if present | Drop | — |

**Impact:** Referent mail client conversation view depends on CQ-6. **No implementer default.**

---

#### 3.5 Inline (non-attachment) body text

| | Status |
|---|--------|
| **Resolved (interpretive, from approved «attachment(s) only»)** | Non-attachment body parts (plain/html) are **not copied** into the rebuilt message unless CQ-3 overrides. |

| | Status |
|---|--------|
| **Customer question (CQ-3)** | Primary source does not define **`Content-Disposition: inline`** parts (embedded images, signature HTML). PROMPT-52 §9.3: «Inline … **Unresolved** — treat as attachment or discard». |

| | Status |
|---|--------|
| **Customer question (CQ-7)** | If no attachable parts remain after extraction (e.g. HTML-only email): same as CQ-1 — **no silent default**. |

**Placeholder body text:** Not specified in primary source — **Customer question (CQ-8)** (empty body vs fixed line vs omitted `text/plain` entirely).

---

#### 3.6 Malformed / unparseable MIME

| | Status |
|---|--------|
| **Resolved (failure mode direction)** | Rebuild failure must follow §4 (no message loss). |

| Path | Today (`88339c4`) | Spec for rebuild step |
|------|-------------------|----------------------|
| Outbound header parse | `BytesHeaderParser` failure → log error, **file stays in Maildir** (L1884–1886) | Same: **do not delete** source file |
| Inbound | `message_from_binary_file` for routing; stream even if body is odd | If rebuild parser **cannot** extract policy-compliant parts → **fail closed**, no local delivery, inbound **UNSEEN** (match inbound error policy in `finalize_inbound_process_result`) |
| Fallback to original unchanged stream | N/A today | **Rejected for `relationship_live`+rebuild path** — would violate attachment-only requirement; only acceptable under `shadow`/`legacy` raw relay modes |

**Customer question (CQ-9):** Whether a **deliberate** «parse failure → raw relay» escape hatch is ever desired (likely **no** given customer priority on rebuild).

---

#### 3.7 Multi-part signed / encrypted (S/MIME, PGP)

| | Status |
|---|--------|
| **Resolved (spec proposal — out of scope v1)** | **Do not apply attachment-extraction rebuild** to messages detected as S/MIME (`multipart/signed`, `application/pkcs7-mime`) or PGP (`multipart/encrypted`, `application/pgp-encrypted`) in v1. |

**Justification:** Extracting «attachments» from signed/encrypted wrappers **breaks signature verification** and cannot produce a meaningful rebuilt message without decryption keys (not in scope). v1 behavior under `relationship_live`+rebuild:

| Detection | Proposed handling |
|-----------|-------------------|
| Signed/encrypted outer structure | **Fail closed** — log `[MESSAGE_REBUILD] signed_or_encrypted skip relationship_id=…` — no delivery; preserve UNSEEN / Maildir file |

| | Status |
|---|--------|
| **Customer question (CQ-10)** | Confirm out-of-scope vs future «decrypt → rebuild → re-sign» workflow. |

---

### 4. Failure and rollback semantics (item 4)

Rebuild must preserve the project’s **never silently drop a message** discipline (PROMPT-51 §5; anchor §3.1).

#### 4.1 Processing pipeline (normative for implementation PROMPT)

```text
[source artifact] → parse/extract → [rebuilt temp RFC822 in TEMP_DIR] → SMTP DATA → success?
                      ↓ fail                              ↓ fail
              keep source unchanged                   delete temp only;
              no IMAP Seen / no Maildir delete        keep source unchanged
```

| Stage | Inbound | Outbound |
|-------|---------|----------|
| Source artifact | IMAP message (UNSEEN) + temp fetch file | Maildir `new/` file |
| Rebuild throws / zero attachments (pending CQ-1) | Do **not** mark `\Seen`; do **not** inject | Do **not** delete Maildir file; release queue reservation per today |
| Rebuild succeeds, SMTP fails | UNSEEN retained (`mark_imap_seen=False`) — L190–197 | File retained — L1211–1214 |
| SMTP 250 on **rebuilt** message | Mark `\Seen` (when policy says so) | Delete Maildir source file — L1217–1218 |
| Temp rebuilt file | Always unlink in `finally` after attempt | Same |

**Original file preserved until rebuilt+delivery both succeed** — mirrors existing «delete Maildir file only after SMTP 250» pattern; inbound temp fetch file is already unlinked separately (L758–763) but IMAP UNSEEN is the retry anchor.

#### 4.2 Rollback of rebuild feature itself

| Mechanism | Effect |
|-----------|--------|
| Per-referent override: leave routing `relationship_live`, add future **`message_rebuild_mode=off`** | **Not in schema today** — would require separate PROMPT if decoupling requested (§5) |
| Revert referent to `shadow`/`legacy` routing | Raw RFC822 stream behavior returns immediately after daemon restart (PROMPT-73 pattern) |
| Disable rebuild gate (implementation flag) | Engineering option for PROMPT-77 — not specified here |

Rebuild failure must **never** fall back to silent raw relay under `relationship_live` without an explicit logged policy change (would violate customer priority #1).

---

### 5. Routing-mode coupling decision (item 5)

| | Decision |
|---|----------|
| **Resolved (spec)** | **Gate message rebuild on `relationship_live`** for the relevant direction (inbound and/or outbound per referent effective modes). |

**Reasoning:**

1. **Semantic coupling:** Customer «algorithm» = correct relationship routing **plus** attachment-only rebuild. `relationship_live` already selects the relationship and address pair; rebuild consumes that same `ClientRelationshipDTO`.
2. **Safety:** Referents still on `shadow`/`legacy` continue **today’s raw-stream behavior** — preserves observability comparisons and avoids changing messages before routing is trusted (PROMPT-75 staged rollout).
3. **Operational alignment:** Customer deferred production rollout until rebuild exists; pilot acceptance (PROMPT-75) validated routing only — rebuild is the **next** capability increment on the same referents.
4. **No independent toggle in v1** unless customer requires partial rollout (routing live, rebuild off). PROMPT-73 override columns could be extended later — **Customer question (CQ-11)**. Default spec: **rebuild ON iff direction is `relationship_live`** (single coupling, simplest).

| Mode | Routing | Message body |
|------|---------|--------------|
| `shadow` / `legacy` | Legacy paths | **Original RFC822 stream** (unchanged) |
| `relationship_live` | Relationship lookup | **Attachment-only rebuild** (this spec) |

Inbound and outbound modes are already independent per referent (PROMPT-73) — rebuild gate follows each direction’s effective mode independently.

---

### 6. Test matrix sketch (item 6)

Isolated function/module tests (e.g. `rebuild_message_for_relationship(raw_bytes, dto, direction) → RebuildResult`) with **synthetic fixtures** — no IMAP/SMTP integration required for matrix coverage.

| ID | Fixture | Direction | Routing gate | Expected outcome (pending CQ answers marked *) |
|----|---------|-----------|--------------|--------------------------------------------------|
| T1 | `multipart/mixed`: text/plain + one `attachment` PDF | Inbound | `relationship_live` | Rebuilt From=`local_client_email`, To=`local_referent_email`, exactly one PDF part* |
| T2 | Same as T1 | Outbound | `relationship_live` | Rebuilt From=`external_referent_email`, To=`external_client_email`, one PDF part* |
| T3 | Single `text/plain` only, no attachment | Both | `relationship_live` | **CQ-1*** — fail closed (recommended) or customer-chosen policy |
| T4 | Three `attachment` parts (pdf, docx, png) | Both | `relationship_live` | Rebuilt message contains **three** parts with preserved filenames/types* |
| T5 | `multipart/alternative` html + plain, no attachment | Both | `relationship_live` | **CQ-1/CQ-7*** |
| T6 | `Content-Disposition: inline` PNG in html related | Both | `relationship_live` | **CQ-3*** |
| T7 | Nested `message/rfc822` (forward) with attachment inside | Inbound | `relationship_live` | **Customer question** — one-level vs recursive extraction |
| T8 | `multipart/signed` S/MIME | Both | `relationship_live` | Fail closed (§3.7 proposal) |
| T9 | Truncated / invalid MIME boundaries | Both | `relationship_live` | Rebuild error; no output artifact |
| T10 | 150 MB attachment (boundary size) | Both | `relationship_live` | Rebuild succeeds; output size ≤ transport limit |
| T11 | Raw fixture through pipeline with gate **`shadow`** | Inbound | `shadow` | **Passthrough bytes unchanged** (regression) |
| T12 | Relationship miss (wrong From) | Inbound | `relationship_live` | No rebuild attempted (routing fail-closed first) |
| T13 | Rebuilt headers | Both | `relationship_live` | **CQ-6*** — assert chosen Subject/Message-ID policy |
| T14 | Cross-relationship isolation | Both | `relationship_live` | DTO A rebuild must not contain addresses from relationship B |

**Harness notes:** Golden-file comparison of rebuilt RFC822 (normalized line endings); optional round-trip parse assert counts of `Content-Disposition: attachment` parts.

---

### 7. Open questions for the customer

Separated from resolved spec decisions. Short list — primary source is good on **directionality and address mapping**, weak on **edge MIME policy**.

| ID | Question |
|----|----------|
| **CQ-1** | Zero attachment parts: drop (fail closed), empty rebuilt body, or full-body fallback? («часто нужны только вложения» implies not always — need rule for the «no attachment» case.) |
| **CQ-2** | Multiple attachments: confirm «all attachment parts, no filter» vs whitelist/size/count limits. |
| **CQ-3** | Inline (`Content-Disposition: inline`) parts: extract as attachments or discard? |
| **CQ-4** | Inbound SMTP envelope `MAIL FROM`: which mailbox (`local_client_email` vs `local_referent_email`) satisfies Postfix/iRedMail policy? |
| **CQ-5** | Rebuilt messages: single `To` only, or preserve/copy `Cc`? |
| **CQ-6** | Subject / Date / Message-ID / References: preserve, regenerate, or hybrid? |
| **CQ-7** | HTML-only or text-only messages with no attachable parts — same as CQ-1? |
| **CQ-8** | If rebuilt message has attachments but no body part, include empty `text/plain` or attachment-only multipart? |
| **CQ-9** | Parse failure: strict fail closed only, or ever allow raw relay under operator flag? |
| **CQ-10** | S/MIME / PGP: confirm v1 out-of-scope + fail closed. |
| **CQ-11** | Must rebuild be independently disableable while staying on `relationship_live`? |
| **CQ-12** | Nested `message/rfc822` / `.eml` attachments: extract inner attachments recursively or one level only? |

---

## Explicit non-goals (item 7 / HARD CONSTRAINTS)

| ID | Non-goal | Deferred PROMPT |
|----|----------|-----------------|
| NG-1 | Spam / unknown-sender **deletion** policy & IMAP EXPUNGE | PROMPT-78 |
| NG-2 | Full spec reconciliation (all PROMPT-51 gaps) | PROMPT-79 |
| NG-3 | Implementation code / tests | PROMPT-77 (follow-up) |
| NG-4 | New DB address columns | Reuse PROMPT-53 four-address model |
| NG-5 | Changing routing lookup rules | Already shipped in relationship_live |

---

## Implementation pointer (for PROMPT-77, not this PROMPT)

Suggested insertion point: new module (e.g. `message_rebuild.py`) called from `_deliver_to_local_smtp` / `send_via_external_smtp` **after** `plan_*_delivery` succeeds and effective mode is `relationship_live`, **before** `_stream_file_via_smtp`. Feed rebuilt temp path to existing stream helper (PROMPT-52 §4 KEEP classification for `_stream_file_via_smtp`).

---

## Report integrity

| Check | Result |
|-------|--------|
| Production code changed | **NO** |
| Specification only | **YES** |
| Primary PDF searched for rebuild wording | **YES — silent** |
| PROMPT-51 finding re-verified on `88339c4` | **YES — confirmed** |
