# A3 — Close and delete `revert-78-fix/dashboard-whitelist-runner`

**Date:** 2026-10-08  
**Hygiene branch:** `hygiene/a3-close-revert-78`  
**Context:** PR #78 merged to `master` as `2e9201a` (2026-10-06). GitHub’s “Revert” created a one-commit branch that undoes that fix.

---

## Why this branch is dangerous

The branch `revert-78-fix/dashboard-whitelist-runner` (tip **`00f1a82`**) is a **single revert commit** on top of `2e9201a`:

- **Message:** `Revert "Dashboard: fix HTTP 500 under hardened PHP-FPM; fail-soft blocks"`
- **Effect:** **−658 lines** across 11 files — removes `panel_safe.php`, FPM emulation/static tests, whitelist runner tests, and **rewrites `web/includes/panel_whitelist_runner.php` back to the pre-#78 implementation**.

If merged into `master`, the panel would again call **`proc_terminate()`** from the whitelist runner. On hardened PHP-FPM (`disable_functions` includes `proc_terminate`), that path causes **dashboard HTTP 500** and breaks the fail-soft dashboard blocks introduced in PR #78.

**Accidental merge risk:** An open GitHub PR targets this branch directly; leaving the remote branch alive keeps “merge revert” one click away.

---

## Confirmation that `master` is clean

| Check | Result |
|-------|--------|
| **`master` HEAD** | `2e9201aebc93da6097214e224945bc3f76faa33b` (`2e9201a` — Merge PR #78) |
| **Revert branch parent** | `00f1a82^` = `2e9201a` (pure revert of the merge commit) |
| **`proc_terminate` in `web/includes/panel_whitelist_runner.php` on `master`** | **Absent** |
| **Same file on `origin/revert-78-fix/dashboard-whitelist-runner`** | **Present** (`proc_terminate($proc);`) |
| **Safe API on `master`** | `panelWhitelistRun` / `panelWhitelistRunEx` without forbidden terminate path |
| **Static guard** | `tests/panel_web_fpm_static_test.php` asserts runner has no `proc_terminate` |

`master` retains the PR #78 safe implementation; only the revert branch reintroduces the forbidden behavior.

---

## Remote branch deletion (executed as part of PROMPT-A3)

After this report is committed and `hygiene/a3-close-revert-78` is pushed, the **only** allowed destructive step:

```bash
git fetch origin
git push origin --delete revert-78-fix/dashboard-whitelist-runner
```

Verify removal:

```bash
git ls-remote --heads origin | grep revert-78
```

Expected: **no output**.

---

## Operator follow-up

1. **Close open PR #81** — *“Revert Dashboard: fix HTTP 500 under hardened PHP-FPM; fail-soft blocks”* — head `revert-78-fix/dashboard-whitelist-runner`. After branch deletion, GitHub may mark it closed or broken; **close it explicitly** so it cannot be reopened against a restored branch.
2. Do **not** recreate or merge any revert of PR #78 without a full security/FPM review.
3. Optional: add branch protection or a note in `CONTRIBUTING.md` that revert branches from merged security fixes must be deleted immediately.

---

## Audit metadata

- **Revert branch tip:** `00f1a8266ffbc77992d3484edd903ec9dff6e184`
- **Commits on revert branch not in `master`:** 1 (`00f1a82`)
- **Diff vs `master`:** 11 files, +34 / −658 (matches GitHub revert)
