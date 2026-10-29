#!/usr/bin/env python3
"""REQ-ACC-15: every Billium acceptance ID in a phase has a PHP test Group."""
from __future__ import annotations

import argparse
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


def required_ids(catalog: str, phase: str) -> set[str]:
    ids: set[str] = set()
    for line in catalog.splitlines():
        cells = [cell.strip().strip('`') for cell in line.strip().strip('|').split('|')]
        if len(cells) >= 4 and re.fullmatch(r"(?:[A-Z]+-)+\d+", cells[0]):
            if re.search(r"\bbillium\b", cells[2], re.I) and phase in re.findall(r"\bP\d+\b", cells[3]):
                ids.add(cells[0])
    return ids


def test_groups(source: str) -> set[str]:
    # Consume literals/comments before looking for the next attribute; examples are not proof.
    tokens = re.compile(
        r"#\[\s*(?:\\?PHPUnit\\Framework\\Attributes\\)?Group\s*\(\s*['\"](?P<identity>[^'\"]+)['\"]\s*\)\s*\]"
        r"|<<<['\"]?(?P<label>\w+)['\"]?\r?\n.*?^(?P=label)\b[^\n]*"
        r"|'(?:\\.|''|[^'\\])*'|\"(?:\\.|[^\"\\])*\"|/\*.*?\*/|//[^\n]*|\#(?!\[)[^\n]*",
        re.S | re.M,
    )
    return {match['identity'] for match in tokens.finditer(source) if match['identity'] is not None}


def check(root: Path, phase: str) -> tuple[set[str], set[str]]:
    ids = required_ids((root / 'docs/acceptance-catalog.md').read_text(), phase)
    groups: set[str] = set()
    for path in (root / 'packages').rglob('*Test.php'):
        if 'tests' in path.relative_to(root / 'packages').parts:
            groups.update(test_groups(path.read_text()))
    return ids, ids - groups


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--phase', required=True, type=lambda v: v if re.fullmatch(r'P\d+', v) else parser.error('phase must be Pn'))
    parser.add_argument('--root', type=Path, default=ROOT)
    args = parser.parse_args()
    ids, missing = check(args.root, args.phase)
    for identity in sorted(missing):
        print(f"acceptance-groups: {identity} missing #[Group('{identity}')] in packages/**/tests")
    print(f'acceptance-groups: {args.phase}: {len(ids)} IDs, {len(missing)} missing')
    if not ids:
        print('acceptance-groups: empty phase is an error')
    return int(not ids or bool(missing))


if __name__ == '__main__':
    raise SystemExit(main())
