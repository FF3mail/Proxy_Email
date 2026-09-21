# PROMPT-36 — PDF rendering fix (admin guide)

**Date:** 2026-09-08  
**Branch:** `prompt-35-doc-gaps` (PR #4 fixup)  
**Scope:** `docs/src/`, `docs/build_admin_guide.py`, `docs/DELTA_transit_admin_guide.pdf` only

---

## Defects and fixes

### Defect 1 — Cyrillic tofu boxes (`Домен:`, `<домен>`)

| | |
|---|---|
| **Symptom** | Section 4.1 bullet 2 wrapped `Домен:` and `домен` in `<font name='Courier'>`, which has no Cyrillic glyphs → solid black rectangles on page 7. |
| **Fix** | Removed Courier/mono overrides from Cyrillic runs. They inherit the registered serif TTF (`AdminGuideSerif` / DejaVu / Arial Unicode). ASCII-only SQL fragments remain in `<font name='Courier'>`. |
| **Files** | `docs/build_admin_guide.py` (`_build_section4_pdf`) |

### Defect 2 — reportlab page style mismatch vs WeasyPrint splice pages

| | |
|---|---|
| **Symptom** | Spliced page 7 used plain black headings and white code areas; adjacent baseline pages (WeasyPrint) use blue h2 + underline, green h3/h4 left bars, grey shaded code blocks with dark left accent. |
| **Fix** | Styled reportlab flowables to match colors sampled from `_baseline_v33.pdf`: h2 `#2980B9` + bottom rule; h3/h4 green `#8DC63F` left bar; code blocks `#F4F4F4` background + `#5D6D7E` left bar; warning box red left rule. Updated `docs/src/admin_guide.css` with the same heading/code colors for the WeasyPrint path. |
| **Files** | `docs/build_admin_guide.py`, `docs/src/admin_guide.css` |

### Defect 3 — version / patch line not on shipped PDF title page

| | |
|---|---|
| **Symptom** | Splice path reused baseline page 1 (v3.3, no PROMPT 35/36) despite HTML source already at v3.4. |
| **Fix** | HTML title block: **v3.4**, patches include **PROMPT 35, PROMPT 36**. Splice path now replaces baseline page 1 with a reportlab-built title page carrying the same metadata (uses baseline pages 2–6 only). |
| **Files** | `docs/src/DELTA_transit_admin_guide.html`, `docs/build_admin_guide.py` (`_build_title_page_pdf`, `build_with_splice`) |

---

## Build path used for committed artifact

| Path | Available here? | Used for commit? |
|------|-----------------|------------------|
| **WeasyPrint** (full HTML → PDF) | **No** — `libgobject-2.0-0` / Pango missing on Windows dev host | No |
| **reportlab splice** (reportlab title + baseline pp. 2–6 + reportlab §3.2/§4 + baseline tail) | Yes | **Yes** — `/Producer: pypdf` on output |

WeasyPrint path was **not** verified in this environment. HTML/CSS source is updated so a Linux CI host with Pango can produce a fully consistent v3.4 PDF without splice.

**Known splice limitation:** footer on baseline pages 2–6 and 8–10 still shows “v3.3” in the running footer; only the title page and HTML footer note reflect v3.4. Full version alignment requires a WeasyPrint full rebuild.

---

## Visual verification (rasterized — not text extraction only)

Tool: `pypdfium2` render at 2×–3× → PNG, then visual inspection of raster output.

| Page | What was checked | Result |
|------|------------------|--------|
| **1 (title)** | `pdftoppm`-equivalent raster `_verify_fixed_p1.png` | **v3.4** visible; patch line lists **PROMPT 35, PROMPT 36**; blue title styling; no glyph boxes |
| **6** | `_verify_fixed_p6.png` (adjacent WeasyPrint baseline page) | Reference WeasyPrint style (blue h2, green h3 bar, shaded code) — unchanged splice page |
| **7** | `_verify_fixed_p7_v2.png` + crop `_verify_bullet2_final.png` (bottom of §4.1) | Blue h2 rule + green h4 bars on reportlab page; shaded `MySQL root password:` / mailbox prompt blocks; **«Домен:»** and **«&lt;домен&gt;»** render as readable Cyrillic (not black rectangles) |
| **§4.1 strings** | Crop focused on `--all-referents` bullet 2 | Confirmed **Домен:** and **&lt;домен&gt;** are legible Cyrillic characters |

Verification artifacts (`docs/reports/_verify_*.png`) were generated locally for this audit and are **not** committed.

---

## Files changed

- `docs/src/DELTA_transit_admin_guide.html` — patch line PROMPT 36
- `docs/src/admin_guide.css` — heading/code colors aligned with baseline WeasyPrint
- `docs/build_admin_guide.py` — Cyrillic font fix, styled reportlab section, reportlab title page, splice uses page 1 replacement
- `docs/DELTA_transit_admin_guide.pdf` — regenerated
