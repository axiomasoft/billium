#!/usr/bin/env python3
"""Fail when a Dart source of billium_contracts declares the `double` type.

`is num` / `is double` checks stay allowed: they appear only in `is` expressions. Comments and
string literals are ignored; any other use of the identifier `double` is an error.
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
LIB = ROOT / "packages/contracts-dart/lib"

STRIPPED = re.compile(
    r"""//[^\n]*|/\*.*?\*/|'(?:\\.|[^'\\\n])*'|"(?:\\.|[^"\\\n])*\"""",
    re.DOTALL,
)
IS_CHECK = re.compile(r"\bis\s*!?\s*double\b")
DOUBLE = re.compile(r"\bdouble\b")


def scan(text: str) -> list[int]:
    """1-based lines of forbidden `double` uses."""
    blanked = STRIPPED.sub(lambda m: re.sub(r"[^\n]", " ", m.group(0)), text)
    blanked = IS_CHECK.sub(lambda m: re.sub(r"[^\n]", " ", m.group(0)), blanked)
    return [number for number, line in enumerate(blanked.splitlines(), 1) if DOUBLE.search(line)]


def main() -> int:
    if not LIB.is_dir():
        print(f"no-double: subject missing: {LIB.relative_to(ROOT)}", file=sys.stderr)
        return 1
    files = sorted(LIB.rglob("*.dart"))
    if not files:
        print("no-double: no Dart sources found", file=sys.stderr)
        return 1
    failed = False
    for path in files:
        for line in scan(path.read_text(encoding="utf-8")):
            print(f"no-double: {path.relative_to(ROOT)}:{line}: type `double` is forbidden", file=sys.stderr)
            failed = True
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
