# PROMPT-53 — Target Data Model & Client Relationship Architecture Specification

**Date:** 2026-09-09  
**Branch:** `prompt-47-panel-authorization-audit`  
**Starting commit:** `b7674dd903ee271179a2e911bca560a4f7f7f5af`  
**Mode:** Architecture / specification only — no schema, code, DB, or VPS changes  
**Business baseline:** Approved customer bidirectional attachment-routing model (PROMPT-51/52); U1–U4 not reopened

---

## 1. Executive summary

The approved business model is **relationship-centric**. The authoritative routing entity is **ClientRelationship**: one row that binds exactly one Referent to four independent addresses and exactly one external Referent mailbox/account.

**Recommended target model: Option A (evolved `clients`).**

- Keep `clients` as the physical table for migration continuity.
- Treat each `clients` row as a **ClientRelationship** (document and optionally rename later to `client_relationships`).
- Store all four addresses explicitly on that row (no local-part derivation).
- Link exactly one `external_accounts` row via a **UNIQUE** FK (`external_account_id`).
- Keep `external_accounts.referent_id` for ownership consistency with the parent Referent.
- Preserve `oauth_tokens` / encryption unchanged.
- Deprecate `referents.local_inbox` / `local_outbox` as **routing** sources; add per-relationship `local_client_maildir` for outbound watch (daemon has no vmail DB today).

This model makes inbound and outbound lookups **deterministic** without `LIMIT 1` and without shared referent-wide mailbox assumptions.

| Decision | Choice |
|----------|--------|
| Dedicated relationship table separate from `clients`? | **No** (Option B rejected — no separate Client identity in approved model) |
| External accounts retained? | **Yes** |
| Account ↔ relationship | **Exactly 1:1** (`clients.external_account_id` UNIQUE NOT NULL for valid/active rows) |
| Inbound lookup keys | `(external_account_id, external_client_email)` |
| Outbound lookup key | `local_client_email` |
| Migration risk | **High for address semantics** (current `clients.email` role ambiguous); **Low for credentials/OAuth** |

---

## 2. Current schema summary

Sources: `schema.sql` (CODE-OBSERVED); VPS observations from PROMPT-51/52 (DB-OBSERVED, unchanged here).

### 2.1 Tables

| Table | Purpose today |
|-------|----------------|
| `referents` | Referent person/org; one `local_inbox` email + one `local_outbox` Maildir path; `active` |
| `clients` | Single `email` + `referent_id` + `active`; used by daemon as inbound **To/Cc** match key |
| `external_accounts` | IMAP/SMTP credentials owned by `referent_id`; `email` = external mailbox; OAuth/plain |
| `oauth_tokens` | Tokens keyed by `account_id` → `external_accounts.id` (UNIQUE) |
| `oauth_providers` | Provider endpoint templates |
| `panel_admins` | Panel operators (orthogonal) |

### 2.2 Constraints / indexes (logical)

| Table | PK | Unique | FK |
|-------|----|--------|----|
| `referents` | `id` | `local_inbox`, `local_outbox` | — |
| `clients` | `id` | `email` | `referent_id` → `referents` CASCADE |
| `external_accounts` | `id` | `email` | `referent_id` → `referents` CASCADE |
| `oauth_tokens` | `id` | `account_id` | `account_id` → `external_accounts` CASCADE |
| `oauth_providers` | `id` | `code` | — |
| `panel_admins` | `id` | `username` | — |

Non-unique indexes: `clients.referent_id`, `external_accounts.referent_id` (MUL).

### 2.3 Field disposition (current → target)

#### `referents`

| CURRENT FIELD | Target meaning? | Retain? | Action |
|---------------|-----------------|---------|--------|
| `id` | Referent PK | Yes | **Retain** |
| `username` | Display / operator label | Yes | **Retain** |
| `local_inbox` | Was shared local referent mailbox for all clients | No (as routing source) | **Deprecate for routing**; transitional seed/display only |
| `local_outbox` | Was Maildir path of that shared mailbox | No (as routing source) | **Deprecate for routing**; replaced by per-relationship `local_client_maildir` |
| `active` | Referent enabled for daemon | Yes | **Retain** + enforce zero-client invariant in app |
| `created_at` / `updated_at` | Audit | Yes | **Retain** |

#### `clients` (becomes ClientRelationship)

| CURRENT FIELD | Target meaning? | Retain? | Action |
|---------------|-----------------|---------|--------|
| `id` | Relationship PK | Yes | **Retain** |
| `email` | Ambiguous single address | No as-is | **Replace** with explicit four address columns (see §6) |
| `referent_id` | Parent Referent | Yes | **Retain** |
| `active` | Relationship enable flag | Yes* | **Retain** (*architectural choice — see §9) |
| timestamps | Audit | Yes | **Retain** |

#### `external_accounts`

| CURRENT FIELD | Target meaning? | Retain? | Action |
|---------------|-----------------|---------|--------|
| `id` | Account PK | Yes | **Retain** |
| `referent_id` | Owning Referent | Yes | **Retain** (must match relationship’s referent) |
| `email` | `external_referent` mailbox address | Yes | **Retain** (= external Referent address) |
| `username` | Plain-auth login override | Yes | **Retain** |
| `auth_type` | `plain` \| `oauth2` | Yes | **Retain** |
| `provider` | OAuth provider code | Yes | **Retain** |
| `password_enc` | Encrypted password | Yes | **Retain** |
| `imap_*` / `smtp_*` | Transport endpoints | Yes | **Retain** |
| `client_id` / `client_secret_enc` | OAuth app credentials (**not** Client FK) | Yes | **Retain** (name is confusing; do not overload as relationship FK) |
| `active` | Account enable | Yes | **Retain** |
| *(missing)* | Link to relationship | — | **Add** inverse: relationship → account FK (preferred) |

#### `oauth_tokens` / `oauth_providers` / `panel_admins`

| Artifact | Action |
|----------|--------|
| All columns | **Retain unchanged** |
| Token ownership | Remains `oauth_tokens.account_id` → `external_accounts.id` |

---

## 3. Target business model

```text
Referent (0..N ClientRelationships)
    |
    +-- may exist with zero relationships → MUST be inactive
    +-- may be active only if ≥1 valid ClientRelationship
    |
    +---- ClientRelationship
              |
              +-- external_client      (Internet From identity)
              +-- local_client         (local mailbox address + Maildir path)
              +-- external_referent    (via external_accounts.email)
              +-- local_referent       (local delivery address)
              +-- external_account     (exactly one IMAP/SMTP credential set)
```

Local-parts need not match. **Stored values are authoritative.**

---

## 4. Target entity-relationship model

```text
┌─────────────┐       1:N        ┌──────────────────────┐
│  referents  │─────────────────▶│ clients              │
│             │                  │ (= ClientRelationship)│
└─────────────┘                  └──────────┬───────────┘
       │ 1                                  │ 1
       │                                    │
       │ N                                  │ 1
       ▼                                    ▼
┌──────────────────┐              ┌──────────────────┐
│ external_accounts│◀─────────────│ external_account_id│
│                  │   1:1        │ (UNIQUE on clients)│
└────────┬─────────┘              └──────────────────┘
         │ 1
         │
         │ 0..1
         ▼
┌──────────────────┐
│  oauth_tokens    │
└──────────────────┘
```

**Ownership rule:** `external_accounts.referent_id` MUST equal `clients.referent_id` for the linked relationship (enforced in application; optional DB trigger later).

---

## 5. Four-address mapping

| Required value | Meaning | Entity / column |
|----------------|---------|-----------------|
| `external_client` | External Client Internet address (inbound **From**) | `clients.external_client_email` |
| `local_client` | Local Client mailbox address (outbound **To** key; watch Maildir) | `clients.local_client_email` |
| `external_referent` | External Referent mailbox address | `external_accounts.email` (via `clients.external_account_id`) |
| `local_referent` | Local Referent delivery address (inbound **To**) | `clients.local_referent_email` |

**Also stored (operational, not one of the four logical addresses):**

| Value | Column | Purpose |
|-------|--------|---------|
| Local Client Maildir root | `clients.local_client_maildir` | Outbound watchdog path (`…/new`) — analogous to today’s `referents.local_outbox` |

**Forbidden:** deriving any of the four from local-part + domain conventions.

---

## 6. External account relationship

### Decisions

| Question | Answer |
|----------|--------|
| Retain `external_accounts`? | **Yes** |
| Add `relationship_id` on account? | **Not required** if relationship holds `external_account_id` (preferred single FK direction) |
| Keep `referent_id` on account? | **Yes** — panel listing, CASCADE ownership, consistency check |
| Account may belong to exactly one relationship? | **Yes** — `clients.external_account_id` **UNIQUE** |
| Uniqueness of mailbox address? | Keep `external_accounts.email` **UNIQUE** (one mailbox → one account row → one relationship) |
| `auth_type` / `username` / `password_enc` / IMAP/SMTP | Unchanged semantics |
| OAuth2 | Unchanged: `oauth_tokens.account_id` → account |

### Why FK on relationship → account (not account → relationship)

- Relationship is the routing entity; it must **require** an account when valid.
- UNIQUE on `clients.external_account_id` enforces 1:1 without nullable gymnastics on the account side during drafts.
- Draft/incomplete UI rows may temporarily leave `external_account_id` NULL until account is created (see validity rules §9).

### Anti-collision with OAuth `client_id` column

Do **not** add a column named `client_id` meaning ClientRelationship. Use `external_account_id` on `clients`, and keep OAuth `external_accounts.client_id` as the OAuth application client id string.

---

## 7. Cardinality rules

| Rule | Enforcement |
|------|-------------|
| Referent : ClientRelationship = 1 : 0..N | FK `clients.referent_id` |
| ClientRelationship : Referent = N : 1 | Same FK NOT NULL |
| ClientRelationship : external_client = 1 : 1 | NOT NULL column + UNIQUE |
| ClientRelationship : local_client = 1 : 1 | NOT NULL + UNIQUE |
| ClientRelationship : local_referent = 1 : 1 | NOT NULL + UNIQUE |
| ClientRelationship : external_account = 1 : 1 | UNIQUE FK when set; required for “valid” |
| External mailbox must not serve multiple relationships | UNIQUE `external_account_id` + UNIQUE `external_accounts.email` |

---

## 8. Uniqueness rules

Derived from approved model: each lookup must resolve **exactly one** relationship; shared external mailbox across relationships is forbidden.

| Address | Same value on multiple relationships? | DB rule |
|---------|----------------------------------------|---------|
| `external_client` | **No** (would make From ambiguous even with mailbox scoping edge cases) | `UNIQUE(external_client_email)` |
| `local_client` | **No** (outbound To must be unique) | `UNIQUE(local_client_email)` |
| `external_referent` / account | **No** | Existing `UNIQUE(external_accounts.email)` + UNIQUE relationship FK |
| `local_referent` | **No** (rejects shared single inbox for many clients) | `UNIQUE(local_referent_email)` |

### Canonicalization

Current panel normalizes referent emails with `strtolower(trim(...))` (`normalizeReferentEmail`). Target model **must** store all four emails lowercased + trimmed at write time (application layer). MariaDB default collation is case-insensitive for many `utf8mb4` collations, but **do not rely on collation alone** — normalize in PHP/Python before INSERT/UPDATE and before lookup.

---

## 9. Activation / inactivity invariants

### Valid ClientRelationship (definition)

A relationship is **valid** when all are true:

1. `external_client_email`, `local_client_email`, `local_referent_email` non-empty and normalized;
2. `external_account_id` NOT NULL and points to an existing account;
3. that account’s `referent_id` equals the relationship’s `referent_id`;
4. `local_client_maildir` non-empty (resolved path);
5. `clients.active = 1` (if relationship-level active is used);
6. linked `external_accounts.active = 1` for routing use.

### Referent.active rules

| Question | Decision |
|----------|----------|
| Zero Clients → inactive? | **Yes** — required |
| Manual disable while Clients exist? | **Yes** — operator may set `referents.active = 0` |
| Remove final Client → auto-deactivate Referent? | **Yes** — application must set `referents.active = 0` |
| Add first Client → auto-activate Referent? | **No** — only **permits** activation; operator enables explicitly |
| Where enforced? | **Application logic** (panel + daemon refuse to load inactive). DB CHECK/triggers optional later; not required for v1 |

### Relationship-level `active`

Approved customer text does not define per-relationship activation. Current schema already has `clients.active`.

**Architectural decision (retained, not invented as new business lore):** keep `clients.active` as an operational kill-switch for one relationship without deleting it.  
**Routing rule:** inactive relationship ⇒ treat as **not found** for inbound/outbound.  
If product owners later forbid this flag, drop it; until then it is compatible and useful.

**Daemon load:** poll/watch only relationships that are valid AND `referents.active = 1` AND `clients.active = 1` AND account active.

---

## 10. `RelationshipLookup` conceptual API

Do not implement in this prompt. Contract:

```text
class RelationshipLookup:

  resolveInbound(externalAccountId, externalSenderEmail)
      -> ClientRelationshipDTO | None

  resolveOutbound(localRecipientEmail)
      -> ClientRelationshipDTO | None

  # discovery helpers for transport layer
  listPollTargets()
      -> [ { account, relationship, referent } ]  # active+valid only

  listWatchTargets()
      -> [ { local_client_maildir, relationship } ]
```

### Why `externalAccountId` for inbound (not sender alone)

1. Message was fetched from a specific IMAP account; scoping prevents cross-mailbox resolution if data were ever inconsistent.
2. Matches approved algorithm: identify Client by **From** *within* that external Referent mailbox context.
3. Sender-only lookup is insufficient if uniqueness were ever relaxed; account+sender remains correct.

### DTO minimum fields

```text
relationship_id
referent_id
external_client_email
local_client_email
local_referent_email
external_account_id
external_referent_email          # denormalized from account.email
local_client_maildir
account: { imap/smtp/auth fields needed by transport }
```

Normalization: both resolve methods lowercase/trim inputs before query.

---

## 11. Inbound lookup requirements

**Inputs:** `external_account_id` (from polled account) + `From` address (`external_sender`).

**Query shape (conceptual):**

```sql
SELECT c.*, ea.email AS external_referent_email, ea.*
FROM clients c
JOIN external_accounts ea ON ea.id = c.external_account_id
JOIN referents r ON r.id = c.referent_id
WHERE c.external_account_id = ?
  AND c.external_client_email = ?
  AND c.active = 1
  AND ea.active = 1
  AND r.active = 1;
```

**Index:** `UNIQUE(external_client_email)` plus FK/UNIQUE on `external_account_id` supports point lookup; composite `(external_account_id, external_client_email)` is the natural inbound key (UNIQUE composite optional if global unique on external_client already implies one row — still filter by account_id in SQL).

**Must not:** match on To/Cc; fallback to `referents.local_inbox`; use `LIMIT 1` without equality predicates.

---

## 12. Outbound lookup requirements

**Input:** local message `To` address (`local_recipient`).

**Query shape (conceptual):**

```sql
SELECT c.*, ea.email AS external_referent_email, ea.*
FROM clients c
JOIN external_accounts ea ON ea.id = c.external_account_id
JOIN referents r ON r.id = c.referent_id
WHERE c.local_client_email = ?
  AND c.active = 1
  AND ea.active = 1
  AND r.active = 1;
```

**Index:** `UNIQUE(local_client_email)`.

**Must not:** pass through raw To/Cc to Internet; select account by `referent_id LIMIT 1`; infer from Referent alone.

---

## 13. Unknown / invalid relationship behavior

| Case | Data-model result | Daemon obligation (future) |
|------|-------------------|----------------------------|
| Unknown external sender | `resolveInbound` → None | Delete as spam (no local deliver) |
| Unknown local To | `resolveOutbound` → None | Do **not** send externally; leave/quarantine policy later — **must not** SMTP out |
| Inactive Referent | Relationships not returned by lookup | Not routable |
| Inactive Client relationship | Not returned | Not routable |
| Incomplete (missing account/addresses) | Not valid; exclude from discovery | Not routable |

---

## 14. Existing-data migration analysis

**Do not migrate in this prompt.** Analysis only.

### Current lab-shaped data pattern (from PROMPT-51/52)

```text
referent.local_inbox     ≈ local-looking address (@testvps.loc)
referent.local_outbox    = Maildir path for that inbox
clients.email            ≈ local-looking address (@testvps.loc)
external_accounts.email  ≈ external mailbox (e.g. @frona.ru)
```

### Deterministic mappings

| Source | Target | Deterministic? |
|--------|--------|----------------|
| `referents.*` except inbox/outbox routing | `referents` retained | **Yes** |
| `external_accounts` credentials/OAuth | Same rows | **Yes** |
| `oauth_tokens` | Unchanged | **Yes** |
| 1:1 referent with exactly one client and exactly one external account | Can link `clients.external_account_id` | **Yes** (structural link only) |
| `referents.local_outbox` → `local_client_maildir` | Only if operator confirms `clients.email` is the **local client** and outbox is that client’s Maildir | **No** — today’s outbox is the **referent** mailbox path |

### Ambiguous / cannot auto-fill four addresses

| Target field | Why ambiguous |
|--------------|---------------|
| `external_client_email` | Current `clients.email` is used as To/Cc match, often looks **local**; may not be Internet client |
| `local_client_email` | Not stored distinctly |
| `local_referent_email` | Might equal `referents.local_inbox` in 1:1 lab setups — **plausible seed**, not proven for all rows |
| `external_referent` | = `external_accounts.email` when 1:1 — **OK** |
| Multi-account referents | Which account binds to which client — **not representable** today |

```text
CURRENT DATA                    TARGET
referent + optional client  →   relationship with 4 addresses
        + optional accounts →   cannot invent missing external_client / local_client
```

**Conclusion:** Structural FK linking is partially automatable for strict 1:1 rows; **address role population requires operator confirmation**. Incomplete relationships must remain inactive/invalid until filled.

---

## 15. Backward compatibility analysis

### Recommended least-risk approach

1. **Additive migration only** — ADD columns to `clients`; do not DROP `clients.email` or `referents.local_*` in the first step.
2. **No dual-write of routing semantics in daemon** for long — prefer **feature flag / version cutover** after panel can edit four addresses.
3. Transitional: keep writing legacy `clients.email` as a copy of `external_client_email` **or** `local_client_email` only if a temporary compatibility shim is unavoidable; document which; prefer **short dual-write then stop**.
4. Compatibility views optional (`v_client_relationships`) — nice for reporting, not required for daemon.
5. Cutover: daemon build that **only** uses new columns; then deprecate legacy columns in a later prompt.

**Avoid:** long dual-read of old To/Cc relay and new From routing in one process — high misdelivery risk.

---

## 16. Panel implications

| Area | Implication |
|------|-------------|
| Referent CRUD | Remains (username, active); `local_inbox`/`local_outbox` demoted or removed from create flow |
| Client CRUD | Becomes **Client relationship CRUD** under a Referent (list many) |
| External account CRUD | Created/edited **in context of a relationship** (1:1); account list may still show referent ownership |
| Referent page | Must list N relationship cards with four addresses + linked mailbox status |
| Activation display | Show Referent.active; warn if zero relationships; disable activate when invalid |
| Editable | Four addresses, account settings, relationship.active, referent.active |
| Read-only | Resolved `local_client_maildir` after successful resolve (or editable only via re-resolve) |
| Maildir resolver | Extend to resolve **local_client** (and validate **local_referent** exists in vmail) |

---

## 17. Security / integrity analysis

| Risk | Mitigation in model |
|------|---------------------|
| Orphan relationship | FK CASCADE from referent; account FK RESTRICT or SET NULL+invalidate |
| Orphan account | Prefer RESTRICT delete while linked; or CASCADE from referent already |
| Duplicate addresses | UNIQUE on all four logical keys |
| Cross-Referent routing | Inbound scoped by `external_account_id`; account.referent_id must match |
| Cross-Client mailbox mix-up | 1:1 UNIQUE `external_account_id` |
| Encrypted credentials | Unchanged Cryptor / key file groups |
| OAuth token theft across accounts | Still per `account_id` |
| Inactive bypass | Lookups require active flags |
| Panel auth | Unchanged master/admin; no Maildir access for www-data |

---

## 18. Indexing requirements

| Index | Table | Purpose |
|-------|-------|---------|
| PK `id` | `clients` | Identity |
| UNIQUE `external_client_email` | `clients` | Inbound From uniqueness |
| UNIQUE `local_client_email` | `clients` | Outbound To lookup |
| UNIQUE `local_referent_email` | `clients` | Prevent shared local referent |
| UNIQUE `external_account_id` | `clients` | 1:1 mailbox binding |
| INDEX `referent_id` | `clients` | List relationships per referent |
| UNIQUE `email` | `external_accounts` | External referent mailbox identity |
| INDEX `referent_id` | `external_accounts` | Panel / ownership |
| UNIQUE `account_id` | `oauth_tokens` | Existing |

**Inbound:** equality on `external_account_id` + `external_client_email` (account PK + unique email → index-friendly).  
**Outbound:** equality on `local_client_email` (UNIQUE).

---

## 19. Concrete target schema proposal (documentation only — DO NOT EXECUTE)

```sql
-- referents: retain; local_inbox/local_outbox deprecated for routing
-- (columns kept initially for compatibility)

-- clients evolves into ClientRelationship
ALTER TABLE clients
  ADD COLUMN external_client_email VARCHAR(255) NULL,
  ADD COLUMN local_client_email    VARCHAR(255) NULL,
  ADD COLUMN local_referent_email  VARCHAR(255) NULL,
  ADD COLUMN external_account_id   INT UNSIGNED NULL,
  ADD COLUMN local_client_maildir  VARCHAR(512) NULL,
  ADD UNIQUE KEY uq_clients_external_client (external_client_email),
  ADD UNIQUE KEY uq_clients_local_client (local_client_email),
  ADD UNIQUE KEY uq_clients_local_referent (local_referent_email),
  ADD UNIQUE KEY uq_clients_external_account (external_account_id),
  ADD CONSTRAINT fk_clients_external_account
      FOREIGN KEY (external_account_id)
      REFERENCES external_accounts(id)
      ON DELETE RESTRICT;

-- After backfill + cutover (later migration):
-- ALTER TABLE clients MODIFY external_client_email VARCHAR(255) NOT NULL;
-- ... same for local_client_email, local_referent_email, external_account_id, local_client_maildir;
-- ALTER TABLE clients DROP COLUMN email;  -- only after daemon/panel cutover
-- Optional rename: RENAME TABLE clients TO client_relationships;
```

### Target end-state column list for `clients` (ClientRelationship)

| Column | Null (end-state) | Notes |
|--------|------------------|-------|
| `id` | NO | PK |
| `referent_id` | NO | FK CASCADE |
| `external_client_email` | NO | UNIQUE |
| `local_client_email` | NO | UNIQUE |
| `local_referent_email` | NO | UNIQUE |
| `external_account_id` | NO | UNIQUE FK RESTRICT |
| `local_client_maildir` | NO | Absolute Maildir root |
| `active` | NO | Default 1 |
| timestamps | | Retain |

`external_accounts`, `oauth_*`, `panel_admins`: unchanged structure aside from usage rules.

---

## 20. Alternative-model comparison

| Model | Advantages | Disadvantages | Migration risk | Routing clarity | Recommendation |
|-------|------------|---------------|----------------|-----------------|---------------|
| **A. Evolve `clients` into ClientRelationship** | Matches approved entity; fewest tables; clear 1:1 account FK; fits existing panel “client under referent” | Table name `clients` slightly misleading until rename | Medium (additive) | High | **Recommended** |
| **B. New `client_relationships` + keep `clients` identity** | Pure naming | Invents Client identity not in approved model; double maintenance | Higher | Medium (join noise) | Reject |
| **C. Addresses on `external_accounts` only** | Fewer rows | Cannot model multiple clients; mixes credentials with routing | High semantic mess | Low | Reject |
| **C2. New relationship table, drop meaning of `clients`** | Clean names | Extra migration churn vs A+optional rename | Medium-High | High | Acceptable alternate if rename-in-place undesired — still same columns |

---

## 21. Explicit anti-patterns (rejected)

| # | Anti-pattern | Why it violates approved spec |
|---|--------------|-------------------------------|
| 1 | One `local_inbox` per Referent for all Clients | Spec requires per-relationship `local_referent`; forbids shared single inbox model |
| 2 | One external account per Referent for all Clients | Spec requires one external Referent mailbox **per Client relationship** |
| 3 | Inbound ID by To/Cc | Spec: identify by **From** |
| 4 | Outbound raw recipient passthrough | Spec: map via DB to external_client / external_referent |
| 5 | `LIMIT 1` account/client selection | Non-deterministic; breaks multi-client Referent |
| 6 | Derive addresses from local-part conventions | Spec: local-parts need not match; DB authoritative |
| 7 | Store only 1–2 of four addresses | Cannot build new From/To messages correctly |

---

## 22. Future test matrix (do not implement yet)

### Relationship cardinality
- Referent with 0 / 1 / N relationships
- Activation blocked at 0; allowed at ≥1 valid

### Address independence
- Distinct local-parts for all four fields
- Two relationships under one Referent with different external mailboxes and local referents

### Inbound lookup
- Correct From + account → correct relationship
- Same Referent, two Clients, independent resolution
- Unknown From → None
- Correct From but **wrong** account id → None

### Outbound lookup
- local To → correct relationship
- Multi-client independence
- Unknown To → None

### Integrity
- Duplicate external_client / local_client / local_referent / external_account rejected
- Account cannot link two relationships
- Delete account while linked → RESTRICT
- Cross-referent account assignment rejected by app check

---

## 23. Recommended implementation sequence (data-model work only)

1. Freeze this specification as the schema contract.
2. Additive DDL: new columns + uniques + FK (nullable during backfill).
3. Panel: relationship editor for four addresses + account bind + maildir resolve for `local_client`.
4. Operator-assisted backfill tool (report gaps; no silent guessing).
5. Implement `RelationshipLookup` against new columns (unit tests).
6. Daemon cutover to lookup API (later prompts: inbound/outbound transform).
7. Enforce activation invariants in panel save/toggle.
8. Drop legacy `clients.email` routing use; later DROP column / optional RENAME table.
9. Remove reliance on `referents.local_inbox` / `local_outbox` for routing.

---

## 24. Open questions

| ID | Topic | Status |
|----|-------|--------|
| OQ-1 | Relationship-level `active` retained vs removed | **Decision recorded:** retain as operational flag (§9); product may reverse later |
| OQ-2 | Auto-activate Referent when first valid relationship added | **Decision recorded:** do **not** auto-activate |
| OQ-3 | Exact Postfix envelope sender for inbound inject (`local_client` vs listed mailbox) | **Ops/MTA** — outside pure data model; does not change stored four addresses |
| OQ-4 | Whether to RENAME `clients` → `client_relationships` in same migration wave | Optional; semantics already Option A |
| OQ-5 | Cc/multiple To on outbound local messages | Approved key is To; multi-recipient local messages unspecified — treat as future daemon policy, not schema |

No open question blocks adopting Option A as the target schema.

---

## 25. Report integrity

| Check | Result |
|-------|--------|
| Production code changed | **NO** |
| Schema / DB records changed | **NO** |
| VPS config / services / mail changed | **NO** |
| Only deliverable | `docs/reports/PROMPT-53-target-data-model.md` |

---

## 26. Sources

1. `schema.sql`, `mail-proxy-daemon.py` (lookup behavior context)  
2. `docs/DELTA-transit_anchor.md`, `docs/Ckeck-list_00.md`  
3. `docs/reports/PROMPT-51-…`, `PROMPT-52-…`  
4. `web/index.php`, `web/includes/maildir_resolver.php`  
5. Approved customer model in PROMPT-53 §§2–3 (this task)
