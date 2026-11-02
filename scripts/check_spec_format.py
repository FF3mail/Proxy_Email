#!/usr/bin/env python3
"""Validate docs/SPEC.md structure only (not requirement wording or content)."""

from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SPEC_PATH = ROOT / 'docs' / 'SPEC.md'

REQUIRED_SECTIONS = tuple(f'## {n}.' for n in range(15))
REQUIRED_SUBSECTIONS = (
    '### 0.1',
    '### 0.4',
    '### 12.1',
    '### 13.1',
    '### 13.2',
)
ENGLISH_RFC2119 = re.compile(r'\b(MUST|SHALL|SHOULD)\b', re.IGNORECASE)


def main() -> int:
    errors: list[str] = []

    if not SPEC_PATH.is_file():
        errors.append(f'missing file: {SPEC_PATH.relative_to(ROOT)}')
        _report(errors)
        return 1

    raw = SPEC_PATH.read_bytes()
    if b'\r' in raw:
        errors.append('SPEC must use LF line endings (found CR)')

    try:
        text = raw.decode('utf-8')
    except UnicodeDecodeError as exc:
        errors.append(f'SPEC must be UTF-8: {exc}')
        _report(errors)
        return 1

    lines = text.splitlines()
    if not lines or not lines[0].startswith('# SPEC —'):
        errors.append('first line must start with "# SPEC —"')

    for marker in REQUIRED_SECTIONS:
        if marker not in text:
            errors.append(f'missing section heading: {marker}')

    for marker in REQUIRED_SUBSECTIONS:
        if marker not in text:
            errors.append(f'missing subsection heading: {marker}')

    if '### 13.2' in text and '| DEV-' not in text:
        errors.append('section 13.2 must contain deviation registry table rows (| DEV-)')

    for idx, line in enumerate(lines, start=1):
        if ENGLISH_RFC2119.search(line):
            errors.append(
                f'line {idx}: English RFC 2119 words MUST/SHALL/SHOULD are not used in SPEC'
            )

    _report(errors)
    return 1 if errors else 0


def _report(errors: list[str]) -> None:
    if not errors:
        print('SPEC structure OK')
        return
    for msg in errors:
        print(f'SPEC structure error: {msg}', file=sys.stderr)


if __name__ == '__main__':
    raise SystemExit(main())
