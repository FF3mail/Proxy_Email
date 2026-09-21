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

---

## 8. PROMPT-77.1 — lab VPS deploy + live verification (2026-09-17)

**Host:** `192.168.125.116` (`mail.testvps.loc`, panel `https://panel.testvps.loc`)  
**Master tip after merge:** `1d5f8c1` (`Merge pull request #21` — PROMPT-77)  
**Token base:** `PROMPT771-1789630328`  
**Type:** Deploy + observe only (no application code changes in this PROMPT)

### 8.1 Merge confirmation

| Item | Result |
|------|--------|
| Branch | `prompt-77-message-rebuild-implementation` (from `origin/master` @ `1b19175`) |
| Ancestry | Fast-forward: `origin/master` was ancestor of branch tip `fa96669` |
| PR | [#21](https://github.com/FF3mail/Proxy_Email/pull/21) — merge commit (no squash/rebase) |
| Master tip | `1d5f8c11890b4ca4ddd43e11bc4647f8ce819e72` |
| Branch delete | Remote branch deleted with PR merge |

### 8.2 Pre-flight state

| Check | Observed | Result |
|-------|----------|--------|
| Referent #1 overrides | `relationship_live` / `relationship_live` / `referent_only` | **PASS** (PROMPT-75 accepted baseline) |
| VPS `git rev-parse HEAD` | `7e627a4` (PROMPT-74 deploy tip) | **PASS (documented drift)** — commits through `88339c4`/`1b19175` were docs-only; first code deploy since PROMPT-74 |
| Deployed `message_rebuild.py` | Absent | Expected pre-PROMPT-77 |
| Systemd drop-in | `shadow` / `shadow` / `referent_only` | Unchanged |
| Stuck outbox file | `1789371282.M251919P290047.mail,...` (PROMPT-75 inbound left in watched `new/`, From=`clientint1@frona.ru`) | Quarantined to `/root/prompt77_quarantine/` before shadow flip (would be mis-sent under shadow legacy outbound) |

### 8.3 Shadow-isolation deploy checkpoint (items 3–5)

**Restarts (3 total before live flip):**

| # | When | Why |
|---|------|-----|
| R1 | After clearing overrides to NULL | Load shadow effective modes (isolation before new code) |
| R2 | After deploy of `1d5f8c1` modules | Load PROMPT-77 code while still shadow |
| — | (R1 initially failed ExecStartPre `chown` on log; fixed `chown vmail:mail-proxy-logs` then restarted — same PROMPT-74/67 class issue) | |

**Override clear (panel path):** referent #1 → empty mode selects → DB `NULL`/`NULL`/`NULL` (inherit global). Effective watch remains `referent_only` via global drop-in (watch tier untouched).

**R1 startup (old code, shadow):**

```text
2026-09-17 07:13:14 [INFO] (MainThread) INBOUND_ROUTING_MODE=shadow OUTBOUND_ROUTING_MODE=shadow OUTBOUND_WATCH_MODE=referent_only ...
2026-09-17 07:13:14 [INFO] (MainThread) [REFERENT_EFFECTIVE_MODES] referent_id=1 inbound=shadow outbound=shadow watch=referent_only
```

**Deploy (PROMPT-74 method):** `git fetch` + detached checkout `1d5f8c1`; `rsync web/` preserving `config.php`; `install` daemon modules to `/usr/local/bin/` (`root:vmail` `0750`) including new `message_rebuild.py`; `py_compile` OK; `requirements.txt` unchanged.

**Post-deploy MD5:**

| File | MD5 |
|------|-----|
| `mail-proxy-daemon.py` | `c8f1480a3f0f33bcffac0a6f17bb63f5` |
| `message_rebuild.py` | `2c5c0c33ae69aa11fd69d75ae91d2336` |

**R2 startup (new code, still shadow):**

```text
2026-09-17 07:21:15 [INFO] (MainThread) INBOUND_ROUTING_MODE=shadow OUTBOUND_ROUTING_MODE=shadow OUTBOUND_WATCH_MODE=referent_only ...
2026-09-17 07:21:15 [INFO] (ImapPoller) [REFERENT_EFFECTIVE_MODES] referent_id=1 inbound=shadow outbound=shadow watch=referent_only
```

**Shadow traffic probe** (`PROMPT771-SHADOW-1789629865`): delivered raw-stream to `refloc1@testvps.loc` (`mode=shadow`); **no** `[MESSAGE_REBUILD]` lines after R2. (Side-note: same Maildir is watched for outbound under `referent_only`, so the delivered file was also outbound-relayed — pre-existing shadow/watch coupling, not rebuild.)

### 8.4 Rollback plan (written before live flip)

| Item | Detail |
|------|--------|
| **Action** | Panel → referent #1 → clear all three overrides to inherit (NULL) → save → `systemctl restart mail-proxy` |
| **Effect** | Effective modes → `shadow`/`shadow`/`referent_only`; rebuild path not entered (deploy alone is harmless) |
| **Trigger: content corruption / attachment loss** | Immediate rollback (stricter than PROMPT-75 routing-only triggers) |
| **Trigger: `fanout_incomplete` unresolved** | If not cleared by a subsequent successful retry within **10 minutes** → immediate rollback |
| **Trigger: cross-relationship leakage** | Immediate rollback |

### 8.5 Re-enable + restart (item 6)

Panel set: `inbound=relationship_live`, `outbound=relationship_live`, `outbound_watch_mode=referent_only` (watch tier unchanged).

```text
LIVE_RESTART_MARK=2026-09-17T07:29:33Z
2026-09-17 07:30:16 [INFO] (ImapPoller) [REFERENT_EFFECTIVE_MODES] referent_id=1 inbound=relationship_live outbound=relationship_live watch=referent_only
```

### 8.6 Live verification (item 7)

#### Inbound fan-out (2 attachments from `clientint1@frona.ru`)

Delivery log:

```text
2026-09-17 07:32:16 [INFO] (ImapWorker-0) Delivering incoming external mail to local SMTP: ['refloc1@testvps.loc'] (mode=relationship_live relationship_id=1 target=refloc1@testvps.loc maildir=.../clientloc1-.../Maildir)
```

Captured local children (before outbound watchdog consumed them):

| Subject | From | To | text/plain | Attachments |
|---------|------|----|------------|-------------|
| `alpha-report.pdf` | `clientloc1@testvps.loc` | `refloc1@testvps.loc` | empty | exactly `alpha-report.pdf` |
| `beta-notes.txt` | `clientloc1@testvps.loc` | `refloc1@testvps.loc` | empty | exactly `beta-notes.txt` |

**Note:** Successful fan-out does not emit `[MESSAGE_REBUILD]` info lines (only error-class tags exist in code). Proof is delivery log + captured RFC822 children.

**Watch coupling / echo (defect):** same children were then outbound-routed and arrived at `clientint1@frona.ru` as rebuilt outbound (`From=refint1@frona.ru`, `Subject=<filename>`). Log:

```text
2026-09-17 07:32:24 [INFO] (Thread-1) [OUTBOUND_ROUTING] relationship_live referent=1 file=1789630344.M647420P366395.mail,... identity=clientloc1@testvps.loc relationship_id=1
2026-09-17 07:32:24 [INFO] (Thread-1) [OUTBOUND_ROUTING] relationship_live referent=1 file=1789630344.M665834P366400.mail,... identity=clientloc1@testvps.loc relationship_id=1
2026-09-17 07:32:25 [INFO] (SmtpWorker-*) Email rebuild_out_* sent via external SMTP
```

#### Inbound zero-attachment

```text
2026-09-17 07:32:17 [ERROR] (ImapWorker-0) [MESSAGE_REBUILD] zero_attachments relationship_id=1
```

No local fan-out child for `PROMPT771-1789630328-IN-ZERO`. **However:** on `refint1@frona.ru` the message had `FLAGS (\Seen)` and subsequent polls showed `Found 0 unread` — **UNSEEN not retained**. Root cause: daemon `FETCH (RFC822)` sets `\Seen` on the server before rebuild fail-closed returns `mark_imap_seen=False` (which only skips an additional `STORE +FLAGS \Seen`). Spec CQ-1/CQ-7 / RD-13 retry contract is not met on live IMAP.

#### Outbound 1:1

Injected single-attachment into watched referent outbox (`From=clientloc1@testvps.loc`). External mailbox proof on `clientint1@frona.ru`:

- `Subject=out-single.bin` (regenerated from filename; original subject not preserved — per spec)
- `From=refint1@frona.ru` `To=clientint1@frona.ru`
- Exactly one attachment `out-single.bin`

```text
2026-09-17 07:32:16 [INFO] (MainThread) [OUTBOUND_ROUTING] relationship_live referent=1 file=PROMPT771-1789630328-OUT-1x1.eml identity=clientloc1@testvps.loc relationship_id=1
2026-09-17 07:32:17 [INFO] (SmtpWorker-6) Email rebuild_out_rcfgzm0p sent via external SMTP (717 bytes)
```

#### Cross-relationship isolation

| Check | Result |
|-------|--------|
| Rel2 inbound → `Subject=iso-rel2.bin` `From=clientloc2` `To=refloc2` | Captured; not delivered into rel1 identities |
| Rel2 outbound → `out-iso-b.bin` on `clientint2@bofoma.net` only | **PASS** |
| Rel1 outbound / echoed fan-out subjects on `clientint2` | **MISSING** (**PASS**) |
| Rel1 subjects on `clientint1` only | **PASS** |

#### Natural `fanout_incomplete`

**Did not occur** (honest report). Synthetic F5/F6/F7 already cover the path.

### 8.7 Observation window (item 8)

**Proposed duration: 60 minutes** (vs PROMPT-75’s 4 h).

**Justification:** rebuild is layered on an already-ACCEPTED `relationship_live` routing pilot; risk is rebuild behavior, not first-time routing. 60 minutes covers ~60 IMAP poll cycles at 60 s cadence — enough to see unexpected `[MESSAGE_REBUILD]` error-class lines and rel2 anomalies without equating rebuild observation to a net-new routing cutover.

**Window:** `2026-09-17T07:40:18Z` → (see §8.8 after window close).

### 8.8 Observation results / rollback / verdict

**Window:** `2026-09-17T07:40:56Z` → `2026-09-17T08:40:57Z` (60 minutes).

| Signal | Result |
|--------|--------|
| `[MESSAGE_REBUILD]` beyond deliberate zero-attach | **None** — only the expected `zero_attachments` line from §8.6 |
| Rel2 rebuild anomalies | **None** |
| IMAP poll cadence | Steady ~60 s (`ImapPoller enqueued 2 IMAP tasks` through end of window) |
| Daemon | Remained `active` |

**Rollback executed:** **Yes** — after observation, panel cleared overrides to NULL; restart `2026-09-17T08:41:35Z`.

```text
2026-09-17 08:42:17 [INFO] (ImapPoller) [REFERENT_EFFECTIVE_MODES] referent_id=1 inbound=shadow outbound=shadow watch=referent_only
```

DB after rollback: `NULL` / `NULL` / `NULL`. PROMPT-77 code remains deployed (harmless under shadow).

**Triggers that fired the rollback (post-window, planned):**

1. **Fail-closed UNSEEN not retained** — content-path severity (blocks RD-13 retry for any future `fanout_incomplete` / rebuild failure).
2. **Inbound fan-out → outbound echo** under `referent_only` — local children deleted after external re-send to `clientint1@frona.ru` (attachment loss from local Maildir + unintended external re-delivery).

**Final verdict: NOT ACCEPTED.**

| Field | Resulting state |
|-------|-----------------|
| Referent #1 overrides | All `NULL` (inherit global shadow / shadow / referent_only) |
| Effective modes | `inbound=shadow outbound=shadow watch=referent_only` |
| VPS code | `1d5f8c1` deployed (rebuild inactive in shadow) |
| PROMPT-75 routing pilot | Rolled back with this PROMPT; re-enable only after PROMPT-77.2 |

### 8.9 Scope for follow-ups

**PROMPT-77.2** (required before re-enabling `relationship_live` with rebuild):

1. **IMAP UNSEEN on fail-closed:** fetch with `BODY.PEEK[]` (or explicitly clear `\Seen` when `mark_imap_seen=False`) so zero-attachment / rebuild failures remain retryable on live IMAP.
2. **Inbound delivery vs outbound watch coupling:** rebuilt inbound children land in `local_referent` Maildir `new/`, which `referent_only` watches — causing immediate outbound echo. Fix options (pick one in 77.2): deliver inbound to client maildir instead; exclude freshly delivered inbound from outbound watch; or require `relationship_only` watch before rebuild pilot.

**PROMPT-78** (roadmap, after 77.2): spam / unknown-sender deletion — **not** started here.

---

## 9. PROMPT-77.2 — Defect fixes (code + config recommendation)

**Date:** 2026-09-18  
**Branch:** `prompt-77-2-rebuild-live-defects` (from `origin/master` = `c55aa9e`)  
**VPS action in this PROMPT:** **none** — no `relationship_live` re-enable; live re-verification is PROMPT-77.3.

### 9.1 Defect 1 — IMAP Seen-on-fetch

**Fix:** `poll_external_imap()` now fetches with `mail.fetch(num, '(BODY.PEEK[])')` instead of `(RFC822)`.

**Response shape (traced, not assumed):** imaplib assembles FETCH literals as `data = [(header_bytes, literal_bytes), b')']` for both RFC822 and BODY.PEEK[]. The server replies to PEEK as `BODY[]` (PEEK omitted from the untagged line). Existing `raw_email = data[0][1]` remains correct.

**Regression:** `tests/test_imap_fetch_seen.py`

| Test | Role |
|------|------|
| `test_rfc822_fetch_marks_seen` | Documents pre-fix protocol model (RFC822 → `\Seen`) |
| `test_peek_fetch_leaves_unseen` | PEEK leaves UNSEEN |
| `test_daemon_poll_fetch_uses_body_peek` | Source contract — **fails if fetch reverted to `(RFC822)`** |
| `test_fail_closed_gate_store_only_marks_seen` | `mark_imap_seen=False` → no STORE → UNSEEN; STORE is sole gate |
| `test_peek_response_tuple_shape_matches_rfc822` | `data[0][1]` shape parity |

**Revert verification (PROMPT-73.1 style):** temporarily replace `'(BODY.PEEK[])'` with `'(RFC822)'` in `mail-proxy-daemon.py` → `test_daemon_poll_fetch_uses_body_peek` **FAIL**; restore PEEK → **PASS**.

### 9.2 Defect 2 — Investigation findings

**Theory from §8.9:** content-dependent outbound match — raw external `From` does not match `resolve_outbound`; rebuilt `From=local_client_email` does.

**Verdict: CONFIRMED** by code tracing + unit evidence.

| Step | Evidence |
|------|----------|
| Inbound delivery target | `relationship_target_email()` → `local_referent_email` (PROMPT-53 convention; rebuild and raw-stream alike) |
| Rebuild child headers | `message_rebuild._build_rebuilt_message(from_addr=dto.local_client_email, to_addr=dto.local_referent_email)` |
| Outbound identity | `extract_outbound_identity_from_message` → RFC822 **From** |
| Lookup | `RelationshipLookup.resolve_outbound` SQL: `WHERE c.local_client_email = %s` |
| Raw-stream | External `From` (e.g. `clientint1@frona.ru`) → `no_relationship_match` → silently skipped |
| Rebuild | `From=clientloc1@testvps.loc` → match → external re-relay |

Path coupling (`referent_only` watches the same Maildir inbound lands in) existed since PROMPT-53/63–65 but was **harmless for raw-stream** because From never matched. PROMPT-77 rebuild made the coupling fire.

Unit proof: `TestRebuildOutboundEchoMechanism.test_rebuilt_from_matches_outbound_identity_external_does_not`.

### 9.3 Defect 2 — Resolution choice

| Option | Decision | Why |
|--------|----------|-----|
| **(a)** Redirect inbound to `local_client_maildir` address | **Reject** | Breaks `relationship_target_email()` used since PROMPT-53 for **all** `relationship_live` inbound (raw + rebuild). Would require reopening PROMPT-53’s delivery convention, not a narrow rebuild patch. |
| **(b)** Exclude freshly delivered inbound from outbound watch (time-window / provenance) | **Reject** | Fragile/racy: Maildir delivery vs watchdog timing races; provenance markers need cross-process durable state; false negatives drop legitimate outbound; false positives still echo. Patches the symptom while leaving path coupling intact. |
| **(c)** Cut `outbound_watch_mode` to `relationship_only` for rebuild pilot | **Adopt** | Watches only per-relationship `local_client_maildir/new` — does **not** include the referent inbox inbound lands in. Eliminates physical path coupling. Mechanism already built (PROMPT-66/73); collision guard (PROMPT-67) and per-referent `relationship_only` tests already cover the mode. |

**Recommendation implemented here:** code does **not** change delivery/watch logic. PROMPT-77.3 **must** set referent #1 overrides to:

`inbound=relationship_live` / `outbound=relationship_live` / `outbound_watch_mode=relationship_only`

(not `referent_only` as in 77.1). Global systemd drop-in stays `referent_only` (safe default for non-pilot referents).

**Safety notes for (c):**

- PROMPT-66 fail-closed: `relationship_only` requires `outbound_routing=relationship_live` — satisfied by the same pilot flip.
- PROMPT-67 collision guard: if `local_client_maildir` == referent outbox (misconfig), relationship observer is skipped; lab paths for rel1/rel2 are distinct from referent outbox (PROMPT-75/77.1 evidence).
- Residual risk: legitimate outbound must be placed in `local_client_maildir/new` under `relationship_only` — already the PROMPT-66/73 target end state; confirm on VPS in 77.3 with a deliberate outbound inject into the client maildir (not the referent outbox).

### 9.4 Defect 2 regression coverage

No new daemon code path for (c) → no new unit test that “echo does not occur” after a code patch.

**Substitutes:**

1. Existing: `test_relationship_only_effective_skips_referent_watch` (`tests/test_referent_mode_overrides.py`) — referent outbox not watched under `relationship_only`.
2. Existing: PROMPT-67 collision + PROMPT-73 override tests.
3. Mechanism evidence test in `tests/test_imap_fetch_seen.py` (§9.2) — locks the content-dependent match explanation.
4. **PROMPT-77.3 VPS:** after PEEK deploy + `relationship_only` flip, inject multi-attach inbound; assert local children remain in referent Maildir and are **not** externally re-relayed; inject outbound into `local_client_maildir/new` and assert external delivery still works.

### 9.5 Test suite (local)

```text
python -m unittest tests.test_imap_fetch_seen tests.test_message_rebuild \
  tests.test_outbound_watch tests.test_relationship_routing \
  tests.test_referent_mode_overrides
```

**Result (2026-09-18):** `Ran 75 tests in ~3.4s` — **OK**.

**Revert check:** temporarily replace `BODY.PEEK[]` with `RFC822` → `test_daemon_poll_fetch_uses_body_peek` **FAIL** (exit 1); restore → **PASS**.

### 9.6 Scope for PROMPT-77.3

1. Deploy this branch’s code to lab VPS (shadow-first isolation pattern from §8).
2. Do **not** flip live until PEEK is confirmed on a fail-closed probe (zero-attach → IMAP UNSEEN retained).
3. Flip referent #1 to `relationship_live` / `relationship_live` / **`relationship_only`** (watch tier change vs 77.1).
4. Re-run inbound fan-out + zero-attach + outbound 1:1 + cross-rel isolation.
5. Confirm no inbound→outbound echo; confirm outbound from client maildir still works.
6. Observation window + accept/reject verdict.

---

## 10. PROMPT-77.3 — VPS deploy + controlled rebuild pilot (2026-09-18)

**Host:** `192.168.125.116` (`mail.testvps.loc`)  
**Code:** `master` @ `6718ce6` (PR #22 merge)  
**Type:** Deploy + observe only (no application code changes in this PROMPT)  
**Token base:** `PROMPT773-1789733144`

### 10.1 Deployment (shadow-first)

| Item | Value |
|------|-------|
| Previous VPS repo / daemon tip | `1d5f8c1` (still `FETCH (RFC822)` in `/usr/local/bin/`) |
| Deployed revision | `6718ce6` |
| Rollback binaries | `/root/prompt773_rollback_20260918T112834Z` |
| Method | PROMPT-74/77.1: detached checkout → rsync `web/` (preserve `config.php`) → `install` daemon modules → `py_compile` → restart |
| Post-deploy full-body fetch | `mail.fetch(num, '(BODY.PEEK[])')` |
| Shadow restart | `2026-09-18T11:29:27Z` — effective `shadow` / `shadow` / `referent_only` |

### 10.2 Defect #1 — PEEK hard gate (before live flip)

Independent IMAP probe (no relationship/rebuild path):

| Field | Observed |
|-------|----------|
| Probe | `PROMPT773-PEEK-1789731041` (zero-attach) |
| Initial flags | `9 (FLAGS (\Recent))` |
| Fetch | `BODY.PEEK[]` |
| Final flags | `9 (FLAGS (\Recent))` |
| UNSEEN retained | **YES** |
| Gate | **PASS** |

Later, under live rebuild path, deliberate zero-attach `…-IN-ZERO` also remained UNSEEN and retried each poll with `[MESSAGE_REBUILD] zero_attachments` (expected fail-closed retry).

### 10.3 Topology hard gate

Runtime diagnostic lines (existing daemon log format):

**Pre-flip (shadow):**
```text
ProxyDaemon operational: 20 IMAP workers, 20 SMTP workers, global OUTBOUND_WATCH_MODE=referent_only, 1 referent watches, 0 relationship maildir paths
```

**Post-flip (live):**
```text
ProxyDaemon operational: 20 IMAP workers, 20 SMTP workers, global OUTBOUND_WATCH_MODE=referent_only, 0 referent watches, 2 relationship maildir paths
[REFERENT_EFFECTIVE_MODES] referent_id=1 inbound=relationship_live outbound=relationship_live watch=relationship_only
```

| Count | Pre-flip | Post-flip |
|-------|----------|-----------|
| referent watches | 1 | **0** |
| relationship maildir paths | 0 | **2** |

### 10.4 Defect #2 — live config + pilot

Referent #1 overrides (panel): `relationship_live` / `relationship_live` / **`relationship_only`**.  
Global systemd drop-in unchanged: `shadow` / `shadow` / `referent_only`.  
Live restart mark: `PROMPT773_LIVE_RESTART=2026-09-18T12:03:52Z` (effective modes logged `12:04:28`).

| Check | Observed |
|-------|----------|
| Inbound fan-out (2 attach) | Delivered to `refloc1` Maildir; children `Subject=alpha773.bin` / `beta773.bin`, `From=clientloc1@testvps.loc`, `To=refloc1@testvps.loc` |
| Outbound echo of fan-out | **None** — children remained in `refloc1/…/Maildir/new` (`CHILD_COUNT=2` at `12:08:24Z` and `12:13:52Z`); `NO_ECHO_OF_FANOUT_SUBJECTS` |
| Outbound inject path | **Only** `…/clientloc1-…/Maildir/new/PROMPT773-1789733144-OUT-1x1.eml` |
| Outbound external proof | `clientint1@frona.ru`: `Subject=out-773.bin` `From=refint1@frona.ru` `To=clientint1@frona.ru` |
| Zero-attach fail-closed | `[MESSAGE_REBUILD] zero_attachments`; IMAP UNSEEN retained |

### 10.5 Observation window

| Item | Value |
|------|-------|
| Documented target (PROMPT-77.1 §8.7) | **60 minutes** |
| Hard evidence captured | **≈ 6.5 minutes** (`12:07:22Z` → `12:13:52Z`) |
| Full 60-minute soak | **Not completed** in this PROMPT |

Signals in the captured window: fan-out children retained locally; no outbound echo of fan-out subjects; service `active`; only expected ERROR class = deliberate `zero_attachments` retries.

### 10.6 Verdict / production state left

| Field | Result |
|-------|--------|
| Rollback required | **NO** |
| Referent #1 overrides | `relationship_live` / `relationship_live` / `relationship_only` |
| Effective watch topology | `0` referent watches, `2` relationship maildir paths |
| Defect #1 (PEEK / UNSEEN) | **Verified live** |
| Defect #2 (echo under `referent_only`) | **Addressed by `relationship_only` for this pilot; no echo observed** |
| Open follow-up | Full 60-minute observation window — **completed in PROMPT-77.4** (see §11) |

**PROMPT-78** (spam / unknown-sender deletion) remains next on the roadmap after this closure.

---

## 11. PROMPT-77.4 — Full 60-minute observation closure (2026-09-21)

**Host:** `192.168.125.116` (`mail.testvps.loc`)  
**Branch:** `prompt-77-4-observation-closure` (from `origin/master` @ `8e8c8a4`)  
**Type:** Observe + document only (no application code changes)  
**Authoritative window token:** `PROMPT774-1789975645`  
**Log artifact:** `/var/log/mail-proxy/prompt774_observe_run2.log` on VPS

### 11.1 Pre-flight state confirmation

| Check | Observed | Result |
|-------|----------|--------|
| Referent #1 overrides | `relationship_live` / `relationship_live` / `relationship_only` | **PASS** — unchanged since PROMPT-77.3 live flip (`2026-09-18T12:04:28Z`) |
| Rollback since 77.3 | None | **PASS** — last `mail-proxy` restart `2026-09-18T12:04:28Z`; no override clear |
| VPS repo `HEAD` | `6718ce6` | **PASS** — code baseline |
| `origin/master` delta | `8e8c8a4` — docs only (`6718ce6..8e8c8a4`) | **PASS** — deployed binaries match repo; no code drift |
| Deployed fetch | `mail.fetch(num, '(BODY.PEEK[])')` @ L722 | **PASS** |
| Binary MD5 vs repo | `mail-proxy-daemon.py` / `message_rebuild.py` identical | **PASS** (`DRIFT_CHECK=PASS`) |
| Service | `active` since live flip | **PASS** |
| Effective topology | `0` referent watches, `2` relationship maildir paths | **PASS** (from daemon log at window start) |

### 11.2 Aborted run 1 (not counted toward acceptance)

| Item | Value |
|------|-------|
| Start | `2026-09-21T07:20:47Z` (`PROMPT774-1789975247`) |
| Stop | `2026-09-21T07:22:18Z` — observation script exited after `INIT_VERIFY` (`set -e` + `grep` pipefail) |
| Duration | ~91 s — **not** part of acceptance window |
| Action | Script fixed; **fresh** window started (run 2) per standing rule — no stitched partial windows |

### 11.3 Authoritative observation window (run 2)

| Item | Value |
|------|-------|
| **Window start** | `2026-09-21T07:27:25Z` |
| **Probe inject** | `2026-09-21T07:27:27Z` — fan-out (`PROMPT774-1789975645-IN-2x`, attachments `alpha774.bin` / `beta774.bin`) + zero-attach (`…-IN-ZERO`) |
| **Initial verify** | `2026-09-21T07:28:57Z` — fan-out children present; zero UNSEEN retained |
| **Poll samples** | 60 × ~60 s cadence (`i=1` @ `07:28:58Z` … `i=60` @ `08:29:34Z`) |
| **Window end** | `2026-09-21T08:29:35Z` |
| **Wall-clock duration** | **3730 s** (~62.2 min incl. 90 s initial wait + 60 poll intervals) |
| **Daemon** | `active` at every sample; no restarts during window |
| **IMAP poll cadence** | Steady ~60 s — `ImapPoller enqueued 2 IMAP tasks` each minute for both relationships |

#### Per-sample summary (all 60 samples)

| i | ts (UTC) | elapsed_s | zero UNSEEN | fanout children | outbound echo | rel2 leak | rel2 rebuild err |
|---|----------|-----------|-------------|-----------------|---------------|-----------|------------------|
| 1 | 07:28:58 | 93 | YES | 4 | 0 | 0 | 0 |
| 2 | 07:29:58 | 153 | YES | 4 | 0 | 0 | 0 |
| 3 | 07:30:59 | 214 | YES | 4 | 0 | 0 | 0 |
| 4 | 07:32:00 | 275 | YES | 4 | 0 | 0 | 0 |
| 5 | 07:33:01 | 336 | YES | 4 | 0 | 0 | 0 |
| 6 | 07:34:02 | 397 | YES | 4 | 0 | 0 | 0 |
| 7 | 07:35:03 | 458 | YES | 4 | 0 | 0 | 0 |
| 8 | 07:36:04 | 519 | YES | 4 | 0 | 0 | 0 |
| 9 | 07:37:05 | 580 | YES | 4 | 0 | 0 | 0 |
| 10 | 07:38:07 | 642 | YES | 4 | 0 | 0 | 0 |
| 11 | 07:39:08 | 703 | YES | 4 | 0 | 0 | 0 |
| 12 | 07:40:10 | 765 | YES | 4 | 0 | 0 | 0 |
| 13 | 07:41:12 | 827 | YES | 4 | 0 | 0 | 0 |
| 14 | 07:42:14 | 889 | YES | 4 | 0 | 0 | 0 |
| 15 | 07:43:15 | 950 | YES | 4 | 0 | 0 | 0 |
| 16 | 07:44:17 | 1012 | YES | 4 | 0 | 0 | 0 |
| 17 | 07:45:19 | 1074 | YES | 4 | 0 | 0 | 0 |
| 18 | 07:46:21 | 1136 | YES | 4 | 0 | 0 | 0 |
| 19 | 07:47:22 | 1197 | YES | 4 | 0 | 0 | 0 |
| 20 | 07:48:24 | 1259 | YES | 4 | 0 | 0 | 0 |
| 21 | 07:49:25 | 1320 | YES | 4 | 0 | 0 | 0 |
| 22 | 07:50:27 | 1382 | YES | 4 | 0 | 0 | 0 |
| 23 | 07:51:28 | 1443 | YES | 4 | 0 | 0 | 0 |
| 24 | 07:52:30 | 1505 | YES | 4 | 0 | 0 | 0 |
| 25 | 07:53:31 | 1566 | YES | 4 | 0 | 0 | 0 |
| 26 | 07:54:33 | 1628 | YES | 4 | 0 | 0 | 0 |
| 27 | 07:55:34 | 1689 | YES | 4 | 0 | 0 | 0 |
| 28 | 07:56:36 | 1751 | YES | 4 | 0 | 0 | 0 |
| 29 | 07:57:37 | 1812 | YES | 4 | 0 | 0 | 0 |
| 30 | 07:58:41 | 1876 | YES | 4 | 0 | 0 | 0 |
| 31 | 07:59:42 | 1937 | YES | 4 | 0 | 0 | 0 |
| 32 | 08:00:44 | 1999 | YES | 4 | 0 | 0 | 0 |
| 33 | 08:01:45 | 2060 | YES | 4 | 0 | 0 | 0 |
| 34 | 08:02:47 | 2122 | YES | 4 | 0 | 0 | 0 |
| 35 | 08:03:48 | 2183 | YES | 4 | 0 | 0 | 0 |
| 36 | 08:04:50 | 2245 | YES | 4 | 0 | 0 | 0 |
| 37 | 08:05:51 | 2306 | YES | 4 | 0 | 0 | 0 |
| 38 | 08:06:53 | 2368 | YES | 4 | 0 | 0 | 0 |
| 39 | 08:07:54 | 2429 | YES | 4 | 0 | 0 | 0 |
| 40 | 08:08:58 | 2493 | YES | 4 | 0 | 0 | 0 |
| 41 | 08:10:00 | 2555 | YES | 4 | 0 | 0 | 0 |
| 42 | 08:11:01 | 2616 | YES | 4 | 0 | 0 | 0 |
| 43 | 08:12:03 | 2678 | YES | 4 | 0 | 0 | 0 |
| 44 | 08:13:04 | 2739 | YES | 4 | 0 | 0 | 0 |
| 45 | 08:14:06 | 2801 | YES | 4 | 0 | 0 | 0 |
| 46 | 08:15:07 | 2862 | YES | 4 | 0 | 0 | 0 |
| 47 | 08:16:09 | 2924 | YES | 4 | 0 | 0 | 0 |
| 48 | 08:17:10 | 2985 | YES | 4 | 0 | 0 | 0 |
| 49 | 08:18:12 | 3047 | YES | 4 | 0 | 0 | 0 |
| 50 | 08:19:15 | 3110 | YES | 4 | 0 | 0 | 0 |
| 51 | 08:20:17 | 3172 | YES | 4 | 0 | 0 | 0 |
| 52 | 08:21:18 | 3233 | YES | 4 | 0 | 0 | 0 |
| 53 | 08:22:20 | 3295 | YES | 4 | 0 | 0 | 0 |
| 54 | 08:23:21 | 3356 | YES | 4 | 0 | 0 | 0 |
| 55 | 08:24:23 | 3418 | YES | 4 | 0 | 0 | 0 |
| 56 | 08:25:24 | 3479 | YES | 4 | 0 | 0 | 0 |
| 57 | 08:26:26 | 3541 | YES | 4 | 0 | 0 | 0 |
| 58 | 08:27:27 | 3602 | YES | 4 | 0 | 0 | 0 |
| 59 | 08:28:31 | 3666 | YES | 4 | 0 | 0 | 0 |
| 60 | 08:29:34 | 3729 | YES | 4 | 0 | 0 | 0 |

> Fan-out child count = 4 because two probe injections (aborted run 1 + run 2) each produced `alpha774.bin` / `beta774.bin` children; all four remained in `refloc1/…/Maildir/new` for the full window.

#### Deep fan-out re-checks (samples 5, 30, 55)

At each deep-check point, all four children present in `new/` with rebuilt headers:

```text
From: clientloc1@testvps.loc
To: refloc1@testvps.loc
Subject: alpha774.bin | beta774.bin
```

`OUTBOUND_ROUTING` lines matching `alpha774` / `beta774` / `PROMPT774` since window start: **0** at every sample.

#### Zero-attachment / BODY.PEEK[] sustained proof

- Subject `PROMPT774-1789975645-IN-ZERO`: **UNSEEN retained at all 60 samples** (`REL1_ZERO_UNSEEN=YES`; flags `(FLAGS ())` — no `\Seen`).
- Daemon emitted `[MESSAGE_REBUILD] zero_attachments relationship_id=1` on each poll cycle (expected fail-closed retry) — **186 lines** in daemon log during window (`07:27`–`08:29` UTC).
- No `\Seen` STORE on fail-closed path.

#### Cross-relationship isolation (relationship 2)

- `REL2_ZERO_COUNT=0` at every sample (zero probe not on rel2 mailbox).
- `REL2_TOKEN_LEAK=0` — no `PROMPT774` / `alpha774` / `beta774` tokens in rel2 Maildir.
- `REL2_REBUILD_ERRORS=0` — no `[MESSAGE_REBUILD]` for `relationship_id=2`.
- Rel2 IMAP polls steady (`Found 0 unread` typical); no anomalies attributable to rel1 activity.

#### Unexpected `[MESSAGE_REBUILD]` / `[ERROR]`

| Class | Count (window) | Notes |
|-------|----------------|-------|
| `zero_attachments relationship_id=1` | 186 | Deliberate probe — expected |
| Any other `[MESSAGE_REBUILD]` | **0** | |
| Any other `[ERROR]` | **0** | |

### 11.4 Final verdict

**ACCEPTED.**

| Field | Resulting state |
|-------|-----------------|
| 60-minute observation window | **Completed** (`07:27:25Z` → `08:29:35Z`) |
| Referent #1 overrides | `relationship_live` / `relationship_live` / `relationship_only` (**not rolled back**) |
| Effective topology | `0` referent watches, `2` relationship maildir paths |
| VPS code | `6718ce6` deployed (matches `origin/master` application code) |
| Next roadmap item | **PROMPT-78** (spam / unknown-sender deletion) |

