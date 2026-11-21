#!/usr/bin/env python3
"""Build rules.json of a spec revision from the [BC-<AREA>-<NN>] markers of its rule documents (D7).

Writes by default; --check compares without writing and exits 1 on drift.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
DEFAULT_REV = ROOT / "spec/revisions/2026-10"

MARKER = re.compile(r"^\[(BC-[A-Z]+-\d{2})\] ")
REFS_GROUP = re.compile(r"\(([^()]*)\)\s*$")
REF = re.compile(r"\b(?:REQ-[A-Z]+-\d{2}|INV-\d{2}|D\d+|M-\d{2}|SRC-\d{3})\b")


def collect(rev: Path) -> list[dict[str, object]]:
    rules: dict[str, dict[str, object]] = {}
    for doc in sorted(rev.glob("*.md")):
        for line in doc.read_text(encoding="utf-8").splitlines():
            marker = MARKER.match(line)
            if not marker:
                continue
            rule_id = marker.group(1)
            if rule_id in rules:
                raise ValueError(f"{doc.name}: duplicate rule {rule_id} (first in {rules[rule_id]['doc']})")
            group = REFS_GROUP.search(line)
            refs = REF.findall(group.group(1)) if group else []
            if not refs:
                raise ValueError(f"{doc.name}: {rule_id} has no source reference (D7)")
            rules[rule_id] = {
                "id": rule_id,
                "doc": doc.name,
                "line_digest": "sha256:" + hashlib.sha256(line.encode("utf-8")).hexdigest(),
                "refs": refs,
            }
    if not rules:
        raise ValueError(f"{rev}: no [BC-…] rule found")
    return [rules[rule_id] for rule_id in sorted(rules)]


def render(rev: Path) -> str:
    return json.dumps(collect(rev), ensure_ascii=False, indent=2) + "\n"


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--rev", type=Path, default=DEFAULT_REV, help="revision directory")
    parser.add_argument("--check", action="store_true", help="compare only, never write")
    args = parser.parse_args()

    out = args.rev / "rules.json"
    try:
        text = render(args.rev)
    except ValueError as error:
        print(f"build-rules: {error}", file=sys.stderr)
        return 1
    if args.check:
        if not out.is_file() or out.read_text(encoding="utf-8") != text:
            print(f"build-rules: {out} is stale; run scripts/spec/build-rules.py", file=sys.stderr)
            return 1
        return 0
    out.write_text(text, encoding="utf-8")
    return 0


if __name__ == "__main__":
    sys.exit(main())
