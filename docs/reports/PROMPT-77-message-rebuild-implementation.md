# PROMPT-77 — Message rebuild implementation

**Branch:** `prompt-77-message-rebuild-implementation` (from `origin/master` @ `1b19175`)  
**Type:** Implementation + unit tests (synthetic fixtures only)  
**Date:** 2026-09-15  
**Spec:** [`PROMPT-76-message-rebuild-spec.md`](PROMPT-76-message-rebuild-spec.md) (PROMPT-76.1)  
**Code baseline before:** `1b19175b40c028c00386e6ce516c7e2aaa279f71`

---

## 1. `message_rebuild.py` — entry points and spec §4 mapping

| Spec rule | Implementation |
|-----------|----------------|
| §4.2 Inbound fan-out (`Content-Disposition: attachment`) | `_walk_for_attachments()` collects only parts with `attachment` in disposition |
| §4.3 Inline discarded | Non-attachment parts skipped in walk |
| §4.1 / §4.8 / §4.9 Zero attachments / malformed / nested `.eml` | `zero_attachments`, `malformed_mime` (incl. `message/rfc822`), `signed_or_encrypted` |
| §4.4 From (inbound) | `dto.local_client_email` in `_build_rebuilt_message()` |
| §4.4 From (outbound) | `dto.external_referent_email` |
| §4.4 To (inbound) | `dto.local_referent_email` |
| §4.4 To (outbound) | `dto.external_client_email` |
| §4.5 Cc dropped | No `Cc` header set |
| §4.6 Subject / Date / Message-ID regenerated | `filename`, `formatdate()`, `make_msgid()` per child |
| §4.7 Empty `text/plain` + attachment | `MIMEText('')` + cloned `MIMEBase` in `multipart/mixed` |
| §1.4 Outbound N=1 only | `rebuild_outbound_message()` fails on 0 or >1 attachable parts |
| RD-13 rebuild phase | `rebuild_inbound_fanout()` builds **all** temps before returning `success=True` |

**Entry points (spec §9):**

- `rebuild_inbound_fanout(raw_bytes, dto) -> FanoutRebuildResult`
- `rebuild_outbound_message(raw_bytes, dto) -> RebuildResult`

---

## 2. RD-13 atomicity walkthrough

**Rebuild phase (all-or-nothing):** `rebuild_inbound_fanout()` loops all attachable parts; any `_build_rebuilt_message()` / `_write_temp_file()` failure triggers `_cleanup_temp_paths()` on every temp created so far and returns `success=False` with zero deliverable paths.

**SMTP phase (daemon):** `MailHandler._deliver_inbound_fanout_via_smtp()` (`mail-proxy-daemon.py`):

1. Calls `rebuild_inbound_fanout()` — **no SMTP** until this returns success.
2. Sequential `_stream_file_via_smtp()` per child with `mail_from=dto.local_client_email`.
3. On any SMTP failure: logs `[MESSAGE_REBUILD] fanout_incomplete delivered=<k> failed=<N-k>` and returns `(False, 'fanout_incomplete')`.
4. `finalize_inbound_process_result(plan, False)` → `mark_imap_seen=False` (UNSEEN retained).
5. All rebuild temps unlinked in `finally`.

**Seen only on full success:** `finalize_inbound_process_result(plan, True)` when all N SMTP calls succeed.

---

## 3. Wiring at both call sites

### Inbound — `_deliver_to_local_smtp()` (~L845)

```python
if plan.mode == InboundRoutingMode.RELATIONSHIP_LIVE:
    delivered, smtp_error = self._deliver_inbound_fanout_via_smtp(...)
else:
    delivered = self._stream_file_via_smtp(..., mail_file)  # unchanged raw stream
```

**Shadow/legacy confirmation:** Test **F12** asserts `rebuild_inbound_fanout` is never called and `_stream_file_via_smtp` receives the **original** `mail_file` path when `effective_inbound_routing_mode=shadow`.

### Outbound — `send_via_external_smtp()` (~L1057)

When `effective_outbound_routing_mode == relationship_live`:

1. Resolve `dto` via existing `_relationship_lookup.resolve_outbound()`.
2. `rebuild_outbound_message()` → temp file.
3. SMTP DATA from rebuilt temp; `recipients = [dto.external_client_email]`.
4. Temp unlinked in outer `finally`.

Shadow/legacy outbound: no rebuild branch entered; original `task.file_path` and `task.recipients` unchanged.

---

## 4. Temp file discipline

| Location | Convention |
|----------|------------|
| Prefix | `rebuild_in_` / `rebuild_out_` via `tempfile.mkstemp()` |
| Directory | `TEMP_DIR` (`/var/spool/mail-proxy/tmp`) — passed from daemon, matches existing `in_` fetch pattern |
| Inbound cleanup | `_deliver_inbound_fanout_via_smtp` `finally` + `rebuild_inbound_fanout` internal cleanup on failure |
| Outbound cleanup | `send_via_external_smtp` outer `finally` unlinks `rebuild_temp` |

---

## 5. Test matrix results

| ID | Description | Result |
|----|-------------|--------|
| F1 | Single attachment inbound | **PASS** |
| F2/F3 | N=3 fan-out, per-file Subject | **PASS** |
| F4 | N=0 fail closed | **PASS** |
| F5 | Rebuild failure → zero deliveries | **PASS** |
| F6 | SMTP fails child 2 → fanout_incomplete | **PASS** |
| F7 | Retry after partial → eventual success | **PASS** |
| F8 | Inline discarded | **PASS** |
| F9 | Nested `message/rfc822` fail closed | **PASS** |
| F10 | S/MIME fail closed | **PASS** |
| F11 | Truncated MIME fail closed | **PASS** |
| F12 | Shadow passthrough unchanged | **PASS** |
| F13 | Cross-relationship isolation | **PASS** |
| O1 | Outbound single attachment | **PASS** |
| O2 | Outbound zero attachments | **PASS** |
| O3 | Outbound multi-attachment fail closed | **PASS** |
| O4 | Outbound inline discarded | **PASS** |
| O5 | Outbound S/MIME fail closed | **PASS** |
| O6 | Outbound headers regenerated | **PASS** |
| O7 | Outbound cross-relationship isolation | **PASS** |

**Existing suite re-run (item 6):**

| Suite | Tests | Result |
|-------|-------|--------|
| `tests.test_referent_mode_overrides` | 9 | **PASS** |
| `tests.test_outbound_watch` | 16 | **PASS** |
| `tests.test_relationship_routing` | 25 | **PASS** |
| `tests.test_message_rebuild` | 19 | **PASS** |
| **Total** | **69** | **PASS** |

---

## 6. Spec ambiguity / conflicts

**None found.** CQ-4 SMTP `MAIL FROM` for inbound rebuild uses `dto.local_client_email` (natural sender per spec §4.4). RCPT remains `plan.local_rcpts` (`local_referent_email`).

---

## 7. PROMPT-77.1 scope (explicitly out of this PROMPT)

**PROMPT-77.1** — lab VPS deploy + live verification (mirroring PROMPT-73→PROMPT-74/75):

- Deploy `message_rebuild.py` + daemon wiring to test VPS
- Enable `relationship_live` on pilot referent(s) already cleared for routing
- Live inbound fan-out proof (multi-attachment external message → N local Maildir deliveries)
- Live outbound 1:1 rebuild proof
- RD-13 partial-failure / retry observation on real IMAP UNSEEN cycle
- Rollback verification (revert to shadow → raw stream restored)

No VPS deployment or live traffic in PROMPT-77.
