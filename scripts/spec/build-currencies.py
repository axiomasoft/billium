#!/usr/bin/env python3
"""Build currencies.json of a spec revision from the SIX ISO 4217 List One XML (D19, D29).

Writes by default; --check compares without writing and exits 1 on drift.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import sys
import xml.etree.ElementTree as ET
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
DEFAULT_XML = ROOT / "spec/revisions/2026-10/sources/list-one.xml"
LIST_ONE_URL = (
    "https://www.six-group.com/dam/download/financial-information/data-center/"
    "iso-currrency/lists/list-one.xml"
)


def build(xml_path: Path, url: str) -> str:
    raw = xml_path.read_bytes()
    root = ET.fromstring(raw)
    published = root.get("Pblshd")
    if not published:
        raise ValueError(f"{xml_path}: ISO_4217 has no Pblshd attribute")

    table: dict[str, dict[str, object]] = {}
    for entry in root.iter("CcyNtry"):
        code = (entry.findtext("Ccy") or "").strip()
        units = (entry.findtext("CcyMnrUnts") or "").strip()
        numeric = (entry.findtext("CcyNbr") or "").strip()
        if not code or units == "N.A.":
            continue
        if not units.isdigit():
            raise ValueError(f"{code}: CcyMnrUnts {units!r} is not a number")
        row = {"code": code, "numeric": numeric, "scale": int(units)}
        if table.setdefault(code, row) != row:
            raise ValueError(f"{code}: conflicting entries {table[code]} and {row}")

    document = {
        "source": {
            "url": url,
            "published": published,
            "sha256": hashlib.sha256(raw).hexdigest(),
        },
        "currencies": [table[code] for code in sorted(table)],
    }
    return json.dumps(document, ensure_ascii=False, indent=2) + "\n"


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--xml", type=Path, default=DEFAULT_XML)
    parser.add_argument("--out", type=Path, help="default: <revision>/currencies.json next to sources/")
    parser.add_argument("--url", default=LIST_ONE_URL)
    parser.add_argument("--check", action="store_true", help="compare only, never write")
    args = parser.parse_args()

    out = args.out or args.xml.resolve().parent.parent / "currencies.json"
    text = build(args.xml, args.url)
    if args.check:
        if not out.is_file() or out.read_text(encoding="utf-8") != text:
            print(f"build-currencies: {out} is stale; run scripts/spec/build-currencies.py", file=sys.stderr)
            return 1
        return 0
    out.write_text(text, encoding="utf-8")
    return 0


if __name__ == "__main__":
    sys.exit(main())
