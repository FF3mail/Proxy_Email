## PROMPT

- **PROMPT-NN[.n]:** <!-- e.g. PROMPT-79.2 or Hygiene H10 -->
- **Branch:** `prompt-NN-<slug>`, `hygiene/<id>-<topic>`, or `feature/<topic>` (one issue → one branch → one PR)

## Summary

<!-- What changed and why (1–3 sentences) -->

## Scope

- [ ] Docs only
- [ ] Application / panel / daemon
- [ ] Tests updated

## Verification

- Python: `python3 -m unittest discover -s tests -p "test_*.py"` (expected on `master`: 171 run, 11 skipped, 0 failures)
- PHP panel (if touched): run each `tests/panel_*_test.php` on a host with PHP CLI (10 files on current `master`)

## VPS / live verify

- [ ] Not required
- [ ] Required — link to report section in PR body

## Merge

- [ ] Draft until operator marks ready for review
- **Do not merge without explicit operator confirmation**
