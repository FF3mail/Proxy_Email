## PROMPT

- **PROMPT-NN[.n]:** <!-- e.g. PROMPT-79.2 -->
- **Branch:** `prompt-NN-<slug>` (one PROMPT → one branch → one PR)

## Summary

<!-- What changed and why (1–3 sentences) -->

## Scope

- [ ] Docs only
- [ ] Application / panel / daemon
- [ ] Tests updated

## Verification

- Python: `python -m unittest discover -s tests -p "test_*.py"`
- PHP panel (if touched): run `tests/panel_*_test.php` on a host with PHP CLI

## VPS / live verify

- [ ] Not required
- [ ] Required — link to report section in PR body

## Merge

- [ ] Draft until operator marks ready for review
- **Do not merge without explicit operator confirmation**
