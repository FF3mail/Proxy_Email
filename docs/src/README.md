# Admin guide PDF source

Authoritative markup for `docs/DELTA_transit_admin_guide.pdf`.

## Files

| File | Purpose |
|------|---------|
| `DELTA_transit_admin_guide.html` | Full guide text (v3.4); edit here for content changes |
| `admin_guide.css` | Print stylesheet for WeasyPrint full rebuild |

## Rebuild

```bash
pip install weasyprint   # Linux / host with Pango — full HTML→PDF
# or
pip install pypdf reportlab   # Windows fallback (splice path)

python3 docs/build_admin_guide.py
```

**WeasyPrint** (preferred on Linux) regenerates the entire PDF from HTML and matches the historical `/Producer: WeasyPrint` metadata.

**Splice fallback** (when WeasyPrint is unavailable): keeps v3.3 pages 1–6 and 8–9 from `master`, replaces section 3.2 + section 4 (including new §4.1) with reportlab-generated pages. Baseline PDF is extracted automatically via `git show master:docs/DELTA_transit_admin_guide.pdf` into `_baseline_v33.pdf` (gitignored).

Do not hand-edit the PDF binary.
