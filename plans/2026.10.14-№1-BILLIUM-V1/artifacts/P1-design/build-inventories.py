#!/usr/bin/env python3
"""Design-time capture of the P1 source inventories (PLAN1 D6).

Usage: python3 plans/2026.10.14-№1-BILLIUM-V1/artifacts/P1-design/build-inventories.py [--brain DIR]

Writes `research/P1-corpus.md` (every Brain document about Billium) and
`research/P1-audit-inventory.md` (every section and ID of the Perplexity audit
package and the DR assignments). The output is a snapshot. A rerun after Brain
changes shows drift through the sha256 column. Executors do not regenerate it;
changing the inventory is a design delta.
"""
from __future__ import annotations

import argparse
import hashlib
import re
from pathlib import Path

PLAN = Path(__file__).resolve().parents[2]
MENTION = re.compile(r"billium|billkit|billiot", re.IGNORECASE)
REPORT = "Infrastructure/Research/Reports/Billium-Perplexity-Volume-1-2026-10-12.md"
REVIEW = "Infrastructure/Research/Reports/Billium-Volume-1-Review-2026-10-12.md"
EVIDENCE = "Infrastructure/Research/Evidence/Billium-Audit-Evidence-2026-10-12.md"
TASK = "Infrastructure/Research/Prompts/Billium-Research.md"
DR = {
    "DR01": "Infrastructure/Research/Prompts/DR-01-Billing-Sources-and-Schemas.md",
    "DR02": "Infrastructure/Research/Prompts/DR-02-Russian-Payments-and-Fiscalization.md",
    "DR03": "Infrastructure/Research/Prompts/DR-03-Rights-Quotas-and-Credits.md",
    "DR04": "Infrastructure/Research/Prompts/DR-04-Financial-Contracts-and-Authority.md",
}
DR_TASK_HEADINGS = ("### Исследование", "### Проверяемые блоки", "### Требуемое исследование", "### Вопросы")


def sha(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()[:12]


def clip(text: str, limit: int = 110) -> str:
    text = re.sub(r"\s+", " ", text.replace("|", "/")).strip()
    return text if len(text) <= limit else text[: limit - 1] + "…"


def cells(line: str) -> list[str]:
    return [c.strip() for c in line.strip().strip("|").split("|")]


def corpus(brain: Path) -> str:
    sources = (PLAN / "research" / "P1-sources.md").read_text(encoding="utf-8")
    tier_a = {Path(p).resolve() for p in re.findall(r"`(" + re.escape(str(brain)) + r"/[^`]+\.md)`", sources)}
    found: dict[Path, int] = {}
    for path in sorted(brain.rglob("*.md")):
        rel = path.relative_to(brain)
        if rel.parts[0] == "Development" or any(part.startswith(".") for part in rel.parts):
            continue
        count = len(MENTION.findall(path.read_text(encoding="utf-8", errors="ignore")))
        if count or path.resolve() in tier_a:
            found[path] = count
    rows = []
    for path, count in found.items():
        tier = "A" if path.resolve() in tier_a else ("B" if count >= 3 else "C")
        rows.append((tier, -count, str(path.relative_to(brain)), count, sha(path)))
    rows.sort()
    out = [
        "# P1 — корпус Brain о Billium (snapshot)",
        "",
        "Сгенерировано `artifacts/P1-design/build-inventories.py` (D6). `BRAIN` = `" + str(brain) + "`.",
        "Корпус — все документы Brain вне `Development/`, где упоминается Billium/BillKit/Billiot, плюс",
        "обязательные документы постановки (`research/P1-sources.md`). Уровни:",
        "",
        "- **A** — обязательный список владельца: читать разделы о Billium полностью (P1.1);",
        "- **B** — ≥ 3 упоминаний: прочитать разделы с упоминаниями и их контекст (P1.2);",
        "- **C** — 1–2 упоминания: прочитать строки-упоминания с окружающим абзацем (P1.2); при",
        "  содержательном факте дочитать раздел.",
        "",
        "Каждая строка обязана получить диспозицию в `knowledge/corpus-ledger.md`.",
        "",
        "## 1. Документы",
        "",
        "| ID | Уровень | Упоминаний | Документ | sha256[:12] |",
        "|:--|:--|--:|:--|:--|",
    ]
    for number, (tier, _neg, rel, count, digest) in enumerate(rows, 1):
        out.append(f"| SRC-{number:03d} | {tier} | {count} | `{rel}` | `{digest}` |")
    counts = {t: sum(1 for r in rows if r[0] == t) for t in "ABC"}
    out += ["", f"Итого: {len(rows)} (A {counts['A']}, B {counts['B']}, C {counts['C']})."]
    return "\n".join(out) + "\n"


def section_lines(text: str, start_prefix: str) -> list[str]:
    lines = text.splitlines()
    begin = next(i for i, line in enumerate(lines) if line.startswith(start_prefix))
    level = len(start_prefix) - len(start_prefix.lstrip("#"))
    result = []
    for line in lines[begin + 1:]:
        if re.match(r"^#{1,%d} " % level, line):
            break
        result.append(line)
    return result


def audit(brain: Path) -> str:
    report_text = (brain / REPORT).read_text(encoding="utf-8")
    review_text = (brain / REVIEW).read_text(encoding="utf-8")
    evidence_text = (brain / EVIDENCE).read_text(encoding="utf-8")
    out = [
        "# P1 — инвентарь аудита Perplexity и заданий DR (snapshot)",
        "",
        "Сгенерировано `artifacts/P1-design/build-inventories.py` (D6). Каждый ID обязателен:",
        "- секции §1–§9 закрывает P1.3 в `knowledge/audit-matrix.md` (`## Покрытие аудита`);",
        "- задания §10 закрывает P1.4 в `knowledge/research/DR-0N*.md` (`## Покрытие задания`).",
        "",
        "## 0. Источники",
        "",
        "| Файл | Байт | sha256[:12] |",
        "|:--|--:|:--|",
    ]
    for rel in (REPORT, REVIEW, EVIDENCE, TASK, *DR.values()):
        path = brain / rel
        out.append(f"| `{rel}` | {path.stat().st_size} | `{sha(path)}` |")

    out += ["", "## 1. Разделы отчёта тома 1", "", "| ID | Строка | Раздел |", "|:--|--:|:--|"]
    number = 0
    for lineno, line in enumerate(report_text.splitlines(), 1):
        match = re.match(r"^(#{2,4}) (.+)$", line)
        if match:
            number += 1
            indent = "· " * (len(match.group(1)) - 2)
            out.append(f"| AUD-{number:03d} | {lineno} | {indent}{clip(match.group(2))} |")

    out += ["", "## 2. Утверждения разбора (C)", "", "| ID | Утверждение | DR |", "|:--|:--|:--|"]
    for line in review_text.splitlines():
        if re.match(r"^\| C-\d{2} \|", line):
            row = cells(line)
            drs = ", ".join(sorted(set(re.findall(r"DR-0\d", row[-1])))) or "—"
            out.append(f"| {row[0]} | {clip(row[1])} | {drs} |")

    out += ["", "## 3. Блоки coverage audit задания v3", "", "| ID | Блок | Вердикт разбора |", "|:--|:--|:--|"]
    for line in section_lines(review_text, "## Независимый coverage audit"):
        match = re.match(r"^\| (\d+)\. ([^|]+)\|[^|]*\| ([^|]+)\|", line)
        if match:
            out.append(f"| V3-{int(match.group(1)):02d} | {clip(match.group(2))} | {clip(match.group(3), 40)} |")

    out += ["", "## 4. Поправки разбора (AC)", "", "| ID | Поправка |", "|:--|:--|"]
    for line in section_lines(review_text, "## Поправки к ownership"):
        match = re.match(r"^(\d+)\. \*\*(.+?)\*\*", line)
        if match:
            out.append(f"| AC-{int(match.group(1)):02d} | {clip(match.group(2))} |")

    out += ["", "## 5. Gaps разбора (G)", "", "| ID | Решение, зависящее от evidence |", "|:--|:--|"]
    for line in review_text.splitlines():
        if re.match(r"^\| G-\d{2} \|", line):
            row = cells(line)
            out.append(f"| {row[0]} | {clip(row[1])} |")

    out += ["", "## 6. Backlog вопросов отчёта (R)", "", "| ID | Приоритет | Вопрос |", "|:--|:--|:--|"]
    for line in report_text.splitlines():
        if re.match(r"^\| R-\d{2} \| P\d", line):
            row = cells(line)
            out.append(f"| {row[0]} | {row[1]} | {clip(row[2])} |")

    out += ["", "## 7. Fault stories отчёта (FS)", "", "| ID | История | Механизм |", "|:--|:--|:--|"]
    rows = [line for line in section_lines(report_text, "## Fault stories") if line.startswith("| ")]
    for number, line in enumerate(rows[1:], 1):
        row = cells(line)
        out.append(f"| FS-{number:02d} | {clip(row[0])} | {clip(row[1])} |")

    out += ["", "## 8. Evidence разбора (E, L)", "", "| ID | Что проверено |", "|:--|:--|"]
    for line in evidence_text.splitlines():
        match = re.match(r"^## (E-\d{2}(?:/E-\d{2})*) — (.+)$", line)
        if match:
            for ident in match.group(1).split("/"):
                out.append(f"| {ident} | {clip(match.group(2))} |")
        if re.match(r"^\| L-\d{2} \|", line):
            row = cells(line)
            out.append(f"| {row[0]} | {clip(row[1] + ' — ' + row[2])} |")

    out += ["", "## 9. Retrieval journal разбора (Q)", "", "| ID | Запрос |", "|:--|:--|"]
    for line in evidence_text.splitlines():
        if re.match(r"^\| Q-\d{2} \|", line):
            row = cells(line)
            out.append(f"| {row[0]} | {clip(row[1])} |")

    out += ["", "## 10. Задания DR", "", "| ID | Пункт задания |", "|:--|:--|"]
    for key, rel in DR.items():
        lines = (brain / rel).read_text(encoding="utf-8").splitlines()
        begin = next(i for i, line in enumerate(lines) if line.startswith(DR_TASK_HEADINGS))
        for line in lines[begin + 1:]:
            if line.startswith("### "):
                break
            match = re.match(r"^(\d+)\. (.+)$", line)
            if match:
                out.append(f"| {key}-T{int(match.group(1)):02d} | {clip(match.group(2))} |")
    return "\n".join(out) + "\n"


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--brain", type=Path, default=Path("/home/vostrikov/Vaults/Brain"))
    brain = parser.parse_args().brain.resolve()
    (PLAN / "research" / "P1-corpus.md").write_text(corpus(brain), encoding="utf-8")
    (PLAN / "research" / "P1-audit-inventory.md").write_text(audit(brain), encoding="utf-8")


if __name__ == "__main__":
    main()
