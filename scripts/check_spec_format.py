#!/usr/bin/env python3
"""Validate docs/SPEC.md structure only (not requirement wording or content)."""

from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SPEC_PATH = ROOT / 'docs' / 'SPEC.md'

SECTION_HEADING = re.compile(r'^## (\d+)\.')
SUBSECTION_HEADING = re.compile(r'^### (0\.1|0\.4|12\.1|13\.1|13\.2)\b')
ACC_HEADING = re.compile(
    r'^\*\*ACC-(\d+)\*\* `'
    r'\[(обязательный|желательный)\]'
    r'`'
)
DEV_ROW = re.compile(r'^\| (DEV-\d+) \|')
D_ID = re.compile(r'D-\d+')
P_ROW = re.compile(r'^\| (P-\d+) \|')
ENGLISH_RFC2119 = re.compile(r'\b(MUST|SHALL|SHOULD)\b', re.IGNORECASE)


def validate_spec_text(text: str, *, spec_label: str = 'SPEC') -> tuple[list[str], dict[str, int] | None]:
    """Return (errors, summary_counts). summary_counts is None when errors prevent summary."""
    errors: list[str] = []
    lines = text.splitlines()

    if not lines or not lines[0].startswith('# SPEC —'):
        errors.append(f'{spec_label}: first line must start with "# SPEC —"')

    for n in range(15):
        pattern = re.compile(rf'^## {n}\.')
        if not any(pattern.match(line) for line in lines):
            errors.append(f'missing section heading: ## {n}.')

    for sub in ('0.1', '0.4', '12.1', '13.1', '13.2'):
        pattern = re.compile(rf'^### {re.escape(sub)}\b')
        if not any(pattern.match(line) for line in lines):
            errors.append(f'missing subsection heading: ### {sub}')

    if any(line.startswith('### 13.2') for line in lines):
        in_132 = False
        has_dev = False
        for line in lines:
            if line.startswith('### 13.2'):
                in_132 = True
                continue
            if in_132 and line.startswith('### '):
                break
            if in_132 and '| DEV-' in line:
                has_dev = True
        if in_132 and not has_dev:
            errors.append('section 13.2 must contain deviation registry table rows (| DEV-)')

    acc_numbers: list[int] = []
    acc_mandatory = 0
    acc_optional = 0
    seen_acc: set[int] = set()

    for idx, line in enumerate(lines, start=1):
        if ENGLISH_RFC2119.search(line):
            errors.append(
                f'line {idx}: English RFC 2119 words MUST/SHALL/SHOULD are not used in SPEC'
            )

        m = ACC_HEADING.match(line)
        if m:
            num = int(m.group(1))
            label = m.group(2)
            if num in seen_acc:
                errors.append(f'line {idx}: duplicate ACC heading ACC-{num}')
            seen_acc.add(num)
            acc_numbers.append(num)
            if label == 'обязательный':
                acc_mandatory += 1
            else:
                acc_optional += 1
            continue

        if re.match(r'^\*\*ACC-\d+\*\*', line) and not ACC_HEADING.match(line):
            errors.append(
                f'line {idx}: ACC heading must include exactly one '
                '`[обязательный]` or `[желательный]` label immediately after **ACC-n**'
            )

    if acc_numbers:
        expected = list(range(1, max(acc_numbers) + 1))
        if sorted(acc_numbers) != expected:
            missing = [n for n in expected if n not in seen_acc]
            if missing:
                errors.append(
                    f'ACC ids must be consecutive from 1: missing ACC-{missing[0]}'
                )
            extras = sorted(seen_acc - set(expected))
            if extras:
                errors.append(f'ACC ids must be consecutive from 1: unexpected ACC-{extras[0]}')

    section_131 = _slice_section(lines, '### 13.1', ('### 13.2', '## 14.'))
    section_133 = _slice_section(lines, '### 13.3', ('## 14.',))
    section_132 = _slice_section(lines, '### 13.2', ('### 13.3', '## 14.'))

    d_ids: list[str] = []
    for line in section_131:
        if line.startswith('| D-') or (line.startswith('|') and 'D-' in line.split('|', 2)[1]):
            first_cell = line.split('|')[1].strip()
            for did in D_ID.findall(first_cell):
                d_ids.append(did)
    _check_unique_ids(d_ids, 'D-nn in section 13.1', errors)

    dev_ids: list[str] = []
    for line in section_132:
        m = DEV_ROW.match(line)
        if m:
            dev_ids.append(m.group(1))
    _check_unique_ids(dev_ids, 'DEV id in section 13.2 table', errors)

    p_ids: list[str] = []
    for line in section_133:
        m = P_ROW.match(line)
        if m:
            p_ids.append(m.group(1))
    _check_unique_ids(p_ids, 'P-n in section 13.3 table', errors)

    summary = {
        'acc_total': len(acc_numbers),
        'acc_mandatory': acc_mandatory,
        'acc_optional': acc_optional,
        'dev_rows': len(dev_ids),
    }
    return errors, summary


def _slice_section(
    lines: list[str], start_marker: str, end_markers: tuple[str, ...]
) -> list[str]:
    collecting = False
    out: list[str] = []
    for line in lines:
        if line.startswith(start_marker):
            collecting = True
            continue
        if collecting:
            if any(line.startswith(m) for m in end_markers):
                break
            out.append(line)
    return out


def _check_unique_ids(ids: list[str], label: str, errors: list[str]) -> None:
    seen: set[str] = set()
    for iid in ids:
        if iid in seen:
            errors.append(f'duplicate {label}: {iid}')
        seen.add(iid)


def main() -> int:
    errors: list[str] = []

    if not SPEC_PATH.is_file():
        errors.append(f'missing file: {SPEC_PATH.relative_to(ROOT)}')
        _report(errors, None)
        return 1

    raw = SPEC_PATH.read_bytes()
    if b'\r' in raw:
        errors.append('SPEC must use LF line endings (found CR)')

    try:
        text = raw.decode('utf-8')
    except UnicodeDecodeError as exc:
        errors.append(f'SPEC must be UTF-8: {exc}')
        _report(errors, None)
        return 1

    struct_errors, summary = validate_spec_text(text)
    errors.extend(struct_errors)
    _report(errors, summary)
    return 1 if errors else 0


def _report(errors: list[str], summary: dict[str, int] | None) -> None:
    if errors:
        for msg in errors:
            print(f'SPEC structure error: {msg}', file=sys.stderr)
        return
    if summary:
        print(
            'SPEC structure OK — '
            f"ACC {summary['acc_total']} "
            f"(mandatory {summary['acc_mandatory']}, optional {summary['acc_optional']}); "
            f"DEV rows {summary['dev_rows']}"
        )
    else:
        print('SPEC structure OK')


if __name__ == '__main__':
    raise SystemExit(main())
