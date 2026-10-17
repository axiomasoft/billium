#!/usr/bin/env python3
"""Build docs/contracts/problem-codes.md from the problem-codes.json registry of a spec revision (M-46).

Writes by default; --check compares without writing and exits 1 on drift.
"""
from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
DEFAULT_CODES = ROOT / "spec/revisions/2026-10/problem-codes.json"
DEFAULT_OUT = ROOT / "docs/contracts/problem-codes.md"


def cell(value: object) -> str:
    if isinstance(value, bool):
        return "да" if value else "нет"
    if isinstance(value, list):
        return ", ".join(str(item) for item in value)
    return str(value).replace("|", "\\|")


def render(codes_path: Path) -> str:
    codes = json.loads(codes_path.read_text(encoding="utf-8"))
    revision = codes_path.resolve().parent.name
    lines = [
        "# Индекс problem-кодов Billium",
        "",
        f"Генерируется `scripts/spec/build-codes-index.py` из `spec/revisions/{revision}/problem-codes.json`;",
        "ручная правка запрещена (M-46). Нормы — `04-errors.md` ревизии; receipt policy — D31.",
        "",
        "| Код | Категория | Повторяемо | Тот же ключ | Receipt | Производитель | Когда | Действие клиента | Основание |",
        "|:--|:--|:--|:--|:--|:--|:--|:--|:--|",
    ]
    for entry in codes:
        row = [
            f"`{entry['code']}`",
            entry["category"],
            entry["retryable"],
            entry["sameKey"],
            entry["receipt"],
            entry["producer"],
            entry["when"],
            entry["clientAction"],
            entry["basis"],
        ]
        lines.append("| " + " | ".join(cell(value) for value in row) + " |")
    return "\n".join(lines) + "\n"


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--codes", type=Path, default=DEFAULT_CODES)
    parser.add_argument("--out", type=Path, default=DEFAULT_OUT)
    parser.add_argument("--check", action="store_true", help="compare only, never write")
    args = parser.parse_args()

    try:
        text = render(args.codes)
    except (OSError, KeyError, TypeError, ValueError) as error:
        print(f"build-codes-index: {error}", file=sys.stderr)
        return 1
    if args.check:
        if not args.out.is_file() or args.out.read_text(encoding="utf-8") != text:
            print(f"build-codes-index: {args.out} is stale; run scripts/spec/build-codes-index.py", file=sys.stderr)
            return 1
        return 0
    args.out.write_text(text, encoding="utf-8")
    return 0


if __name__ == "__main__":
    sys.exit(main())
