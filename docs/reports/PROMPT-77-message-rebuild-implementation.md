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
