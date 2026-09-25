# Contributing to DELTA-transit (Proxy_Email)

## Workflow

1. Sync base: `git fetch origin && git checkout -b prompt-NN-slug origin/master`
2. **One PROMPT per branch**, **one PR per branch** to `master`.
3. Commit messages: `[PROMPT-NN] Imperative summary` when a PROMPT number applies; otherwise a clear imperative summary (no placeholders).
4. Open a **draft** PR; a human merges only (merge commit preferred, same as PROMPT-77.4 / #24–#30).
5. After merge is confirmed on `master`, delete the remote `prompt-*` branch.

## Documentation entry point

- **[docs/README.md](docs/README.md)** — index of operator guide, architecture anchor (SoT), decisions, and historical material.
- Architecture source of truth: **[docs/DELTA-transit_anchor.md](docs/DELTA-transit_anchor.md)** (code wins on conflict).

## Do not

- Force-push `master` or rewrite published history on shared branches.
- Delete remote `prompt-*` branches without operator confirmation (except as part of an approved hygiene pass).
- Merge your own PR unless the operator explicitly instructs you to.

## Tests

Before requesting review, run the Python suite locally. Run PHP panel static tests when panel or docs that affect panel contracts change, on any host with PHP CLI.
