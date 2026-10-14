#!/usr/bin/env python3
"""Capture the six acceptance sets named by the owner; design-only (D8)."""
import argparse
import hashlib
import re
from pathlib import Path

PLAN = Path(__file__).resolve().parents[2]
SOURCES = {
    "PB": ("Infrastructure/Workspace/Pilot-Commerz-Billium-Payment-Boundary.md", 24),
    "PF": ("Infrastructure/Workspace/Domains/Billing/Payment-Implementation-Prerequisites.md", 14),
    "MG": ("Infrastructure/Workspace/Projects/Memster/Memster-Implementation-Prerequisites.md", 12),
    "FD": ("Infrastructure/Workspace/Fiscal-Offline-Documents-Implementation-Prerequisites.md", 10),
    "SB": ("Infrastructure/Workspace/Settlements-Bundles-Implementation-Prerequisites.md", 10),
    "EC": ("Infrastructure/Workspace/Projects/Edufy/Edufy-Implementation-Prerequisites.md", 12),
}


def render(brain: Path) -> str:
    corpus = (PLAN / "research/P1-corpus.md").read_text()
    source_rows, scenarios = [], []
    for prefix, (relative, count) in SOURCES.items():
        path = brain / relative
        raw = path.read_bytes()
        text = raw.decode("utf-8")
        owner = re.search(r"^\| (SRC-\d+) \|[^\n]*`" + re.escape(relative) + r"`", corpus, re.M)
        if not owner:
            raise ValueError(f"No corpus owner for {relative}")
        src = owner[1]
        source_rows.append(f"| {prefix} | {src} | `{relative}` | `{hashlib.sha256(raw).hexdigest()}` |")
        found = {}
        for line_no, line in enumerate(text.splitlines(), 1):
            row = re.match(r"^\| (" + prefix + r"-\d{2}) \| (.+)", line)
            if row:
                if row[1] in found:
                    raise ValueError(f"Duplicate {row[1]}")
                found[row[1]] = (line_no, row[2].split("|")[0].strip())
            # SB is a numbered paragraph in its owning source, not a pipe table.
            if prefix == "SB" and line.startswith("SB-01…10 "):
                for number, title in re.findall(r"\((\d{2})\) (.*?)(?=; \(\d{2}\)|$)", line):
                    found[f"SB-{number}"] = (line_no, title.rstrip("."))
        expected = {f"{prefix}-{n:02d}" for n in range(1, count + 1)}
        if set(found) != expected:
            raise ValueError(f"{prefix}: missing={expected - set(found)}, extra={set(found) - expected}")
        for ident, (line_no, title) in sorted(found.items()):
            scenarios.append(f"| {ident} | {src} | {line_no} | {title} |")
    return "\n".join([
        "# P1 — инвентарь приёмочных сценариев (D8)", "",
        "Snapshot исходных PB/PF/MG/FD/SB/EC. Это перечень для диспозиции в P1.7,",
        "не результаты тестов и не передача Billium чужих обязанностей. Для каждого ID P1.7",
        "выделяет сторону Billium или даёт обоснованную диспозицию `сосед:<имя>` / `n/a`.",
        "При изменении источника исходный ID сохраняется; расхождение документируется,",
        "новые ID добавляются только через design-delta. SB развёрнут из нумерованного абзаца.", "",
        "## Источники", "", "| Набор | SRC | Путь относительно BRAIN | sha256 |",
        "|:--|:--|:--|:--|", *source_rows, "",
        "## Сценарии", "", "| ID | Источник | Строка | Название в источнике |",
        "|:--|:--|--:|:--|", *scenarios, "",
        "Итого: 82 (PB 24, PF 14, MG 12, FD 10, SB 10, EC 12).", "",
    ])


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--brain", type=Path, default=Path("/home/vostrikov/Vaults/Brain"))
    parser.add_argument("--check", action="store_true")
    args = parser.parse_args()
    target = PLAN / "research/P1-acceptance-inventory.md"
    result = render(args.brain)
    if args.check:
        if not target.is_file() or target.read_text() != result:
            raise SystemExit("RED: acceptance snapshot differs from sources")
        print("GREEN: 82 acceptance scenarios match six primary local sources")
    else:
        target.write_text(result, encoding="utf-8")
        print(f"Wrote {target}")
