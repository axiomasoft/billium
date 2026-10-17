#!/usr/bin/env python3
"""Build revision.lock.json: sha256 of every file of a spec revision except the lock itself (M-03).

The status comes from the "Статус: **<status>**" line of the revision README.
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
LOCK = "revision.lock.json"
STATUS = re.compile(r"^Статус: \*\*(draft|frozen)\*\*", re.MULTILINE)


def render(rev: Path) -> str:
    status = STATUS.search((rev / "README.md").read_text(encoding="utf-8"))
    if not status:
        raise ValueError(f"{rev}/README.md: no 'Статус: **draft|frozen**' line")
    files = {}
    for path in sorted(p for p in rev.rglob("*") if p.is_file()):
        rel = path.relative_to(rev).as_posix()
        if rel == LOCK or "__pycache__" in path.parts:
            continue
        files[rel] = "sha256:" + hashlib.sha256(path.read_bytes()).hexdigest()
    document = {"revision": rev.name, "status": status.group(1), "files": files}
    return json.dumps(document, ensure_ascii=False, indent=2) + "\n"


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--rev", type=Path, default=DEFAULT_REV, help="revision directory")
    parser.add_argument("--check", action="store_true", help="compare only, never write")
    args = parser.parse_args()

    rev = args.rev.resolve()
    out = rev / LOCK
    try:
        text = render(rev)
    except (OSError, ValueError) as error:
        print(f"build-lock: {error}", file=sys.stderr)
        return 1
    if args.check:
        if not out.is_file() or out.read_text(encoding="utf-8") != text:
            print(f"build-lock: {out} is stale; run scripts/spec/build-lock.py", file=sys.stderr)
            return 1
        return 0
    out.write_text(text, encoding="utf-8")
    return 0


if __name__ == "__main__":
    sys.exit(main())
