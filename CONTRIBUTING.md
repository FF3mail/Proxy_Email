# Contributing to DELTA-transit (Proxy_Email)

## Workflow

1. Sync base: `git fetch origin && git checkout -b <branch> origin/master`  
   Branch naming: `prompt-NN-<slug>`, `hygiene/<id>-<topic>`, or `feature/<topic>` (match the linked issue).
2. **One issue per branch**, **one PR per branch** to `master`.
3. Commit messages: `[PROMPT-NN] Imperative summary` when a PROMPT number applies; for hygiene use `docs:` / `chore:` with `(H<id>, closes #<issue>)`; otherwise a clear imperative summary (no placeholders).
4. Open a PR (draft by default for feature/PROMPT work); the operator merges only (merge commit preferred, same as PROMPT-77.4 / #24–#30).
5. After merge is confirmed on `master`, delete the remote branch when the operator approves (typical for merged `prompt-*` / hygiene branches).

## Documentation entry point

- **[docs/README.md](docs/README.md)** — index of operator guide, architecture anchor (SoT), decisions, and historical material.
- Architecture source of truth: **[docs/DELTA-transit_anchor.md](docs/DELTA-transit_anchor.md)** (current anchor **v4.4**; code wins on conflict).

## Do not

- Force-push `master` or rewrite published history on shared branches.
- Delete remote `prompt-*` branches without operator confirmation (except as part of an approved hygiene pass).
- Merge your own PR unless the operator explicitly instructs you to.

## Tests

Before requesting review:

- Python (from repo root; writable `/var/spool/mail-proxy` and `/var/log/mail-proxy`, and `mysql-connector-python` available):

  ```bash
  python3 -m unittest discover -s tests -p "test_*.py"
  ```

  Expected on current `master`: **171** tests run, **11** skipped (MySQL-dependent cases when no local test DB), **0** failures.

- PHP panel static tests (when panel or panel contract docs change; PHP CLI required): run each `tests/panel_*_test.php` (**10** files on current `master`).

## Repository hygiene rules

- The repository is public: never commit real lab domains, IP addresses, mailbox names, SSH user@host notes, credential-file paths, passwords, tokens, or keys. Use documentation placeholders: lab-a.example.test / lab-b.example.test, 203.0.113.0/24 and 192.0.2.0/24 (TEST-NET), user-a / user-b / referent-a / client-a, <ssh-user>@<lab-vps>, /etc/mail-proxy/<test-credentials-env>.
- Real lab values and SSH keys live outside the repository (a local .keys/ folder, ignored by .gitignore).
- Distribution/ is a locally generated deploy bundle and is not tracked (/Distribution/ in .gitignore); regenerate it locally, do not commit it.
- Do not replace ordinary words (status values, labels, enum names) when anonymizing; tests must pass identically before and after.
- One issue = one branch = one PR to master; no stacked PRs unless the operator explicitly approves it.
- Merging is done by the operator only.
- Line endings: .gitattributes enforces LF for text files. Build deploy archives from git with `git archive -o <file>` (never with a PowerShell `>` redirect) and verify checksums of binary assets before copying to a server.
