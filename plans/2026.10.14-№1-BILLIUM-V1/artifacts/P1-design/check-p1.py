#!/usr/bin/env python3
"""Structural acceptance checks for PLAN1 phase P1 (decisions D3, D6, D7).

Usage: python3 plans/2026.10.14-№1-BILLIUM-V1/artifacts/P1-design/check-p1.py P1.1|...|P1.9|pre-review|all

Stdlib only. Checks presence, required sections, full coverage of the source
inventories (D6), resolvable provenance links (D7), evidence labels, verdict
enums and traceability. Semantic quality is reviewed by P1.9.
"""
from __future__ import annotations

import json
import re
import sys
from functools import lru_cache
from pathlib import Path

PLAN = Path(__file__).resolve().parents[2]
REPO = PLAN.parents[1]
KNOW = PLAN / "knowledge"
RESEARCH = PLAN / "research"

LABELS = {"supplied", "verified-static", "verified-doc", "hypothesis",
          "recommendation", "unverified"}
LABEL_RE = re.compile(r"\b(supplied|verified-static|verified-doc|hypothesis|recommendation|unverified)\b|n/a",
                      re.IGNORECASE)
EMPTY = {"", "—", "-", "–"}
TOKEN = re.compile(
    r"\b(?:REQ-[A-Z]+-\d{2,}|INV-\d{2,}|CONFLICT-\d{2,}|SRC-\d{3}|AUD-\d{3}|V3-\d{2}|AC-\d{2}"
    r"|FS-\d{2}|CL-\d{2,}|DR0[1-4]-T\d{2}|DR0[1-4]-\d{2,}|M-\d{2,}|ACC-[A-Z]+-\d{2,}"
    r"|PB-\d{2}|PF-\d{2}|MG-\d{2}|FD-\d{2}|SB-\d{2}|EC-\d{2}|[CGREQL]-\d{2}|D\d+)\b")
REQ_KINDS = ("REQ-", "INV-")
EVIDENCE_KINDS = ("REQ-", "INV-", "CL-", "DR0", "M-", "SRC-", "AUD-", "FS-", "C-", "E-", "L-")
LEDGER_DISPOSITIONS = {"использован", "без новых требований", "дубль", "процесс/история", "вне границ"}
AUDIT_DISPOSITIONS = {"учтено", "вне границ", "нет утверждений", "дубль"}
DR_FILES = {"DR01": "DR-01.md", "DR02": "DR-02.md", "DR03": "DR-03-boundary.md", "DR04": "DR-04.md"}


class Report:
    def __init__(self, item: str) -> None:
        self.item = item
        self.errors: list[str] = []

    def err(self, message: str) -> None:
        self.errors.append(message)

    def check(self, condition: bool, message: str) -> bool:
        if not condition:
            self.err(message)
        return condition


# --- markdown helpers -------------------------------------------------------

def rel(path: Path) -> str:
    try:
        return path.relative_to(REPO).as_posix()
    except ValueError:
        return str(path)


def read(path: Path, r: Report) -> str | None:
    if not path.is_file():
        r.err(f"нет файла {rel(path)}")
        return None
    text = path.read_text(encoding="utf-8")
    if not text.strip():
        r.err(f"пустой файл {rel(path)}")
        return None
    return text


def sections(text: str) -> dict[str, str]:
    """Map each `## ` heading to its body (up to the next `## ` heading)."""
    result: dict[str, str] = {}
    current = None
    buffer: list[str] = []
    for line in text.splitlines():
        if line.startswith("## "):
            if current is not None:
                result[current] = "\n".join(buffer)
            current = line[3:].strip()
            buffer = []
        elif current is not None:
            buffer.append(line)
    if current is not None:
        result[current] = "\n".join(buffer)
    return result


def body_of(text: str, prefix: str) -> str | None:
    for heading, body in sections(text).items():
        if heading.lower().startswith(prefix.lower()):
            return body
    return None


def section(text: str, prefix: str, r: Report, where: str) -> str | None:
    body = body_of(text, prefix)
    if body is None:
        r.err(f"{where}: нет раздела `## {prefix}`")
    return body


def _cells(line: str) -> list[str]:
    body = line.strip()
    if body.startswith("|"):
        body = body[1:]
    if body.endswith("|"):
        body = body[:-1]
    return [cell.strip() for cell in re.split(r"(?<!\\)\|", body)]


def tables(text: str) -> list[tuple[list[str], list[list[str]]]]:
    """Parse GitHub pipe tables: (header, rows)."""
    lines = text.splitlines()
    found = []
    index = 0
    while index < len(lines) - 1:
        line, separator = lines[index], lines[index + 1]
        if line.strip().startswith("|") and re.fullmatch(r"\s*\|?[\s:|-]+\|?\s*", separator) and "-" in separator:
            header = _cells(line)
            rows = []
            index += 2
            while index < len(lines) and lines[index].strip().startswith("|"):
                rows.append(_cells(lines[index]))
                index += 1
            found.append((header, rows))
        else:
            index += 1
    return found


def column(header: list[str], name: str) -> int | None:
    for position, value in enumerate(header):
        if value.lower().startswith(name.lower()):
            return position
    return None


def find_table(text: str, required: list[str]):
    for header, rows in tables(text):
        positions = {name: column(header, name) for name in required}
        if all(value is not None for value in positions.values()):
            return header, rows, positions
    return None


def cell(row: list[str], position: int) -> str:
    return row[position].strip() if position < len(row) else ""


def strip_md(value: str) -> str:
    return value.replace("`", "").replace("**", "").strip()


def first_column_ids(text: str) -> list[str]:
    ids = []
    for header, rows in tables(text):
        if header and header[0].strip().lower() == "id":
            ids.extend(strip_md(row[0]) for row in rows if row)
    return ids


# --- owner sets for provenance resolution (D7) ------------------------------

@lru_cache(maxsize=None)
def file_text(path: Path) -> str:
    return path.read_text(encoding="utf-8") if path.is_file() else ""


@lru_cache(maxsize=None)
def inventory(name: str) -> dict[str, list[str]]:
    """ID → row of a design-time inventory (`research/P1-*.md`)."""
    rows: dict[str, list[str]] = {}
    for header, body in tables(file_text(RESEARCH / name)):
        if header and header[0].strip().lower() == "id":
            for row in body:
                rows[strip_md(row[0])] = row
    return rows


@lru_cache(maxsize=None)
def known_ids() -> frozenset[str]:
    known = set(first_column_ids(file_text(REPO / "docs" / "requirements.md")))
    known |= set(inventory("P1-corpus.md")) | set(inventory("P1-audit-inventory.md"))
    claims = body_of(file_text(KNOW / "audit-matrix.md"), "Утверждения") or ""
    known |= {i for i in first_column_ids(claims) if i.startswith("CL-")}
    for name in DR_FILES.values():
        body = body_of(file_text(KNOW / "research" / name), "Утверждения") or ""
        known |= set(first_column_ids(body))
    try:
        methods = json.loads(file_text(KNOW / "methods.json") or "{}")
        known |= {str(p.get("id")) for p in methods.get("practices", []) if isinstance(p, dict)}
    except json.JSONDecodeError:
        pass
    known |= {path.stem for path in (PLAN / "decisions").glob("D*.json")}
    known |= set(first_column_ids(file_text(REPO / "docs" / "acceptance-catalog.md")))
    return frozenset(known)


def provenance(r: Report, where: str, value: str, kinds: tuple[str, ...], *, required: bool = True) -> list[str]:
    """Every ID in `value` must resolve; at least one must be of `kinds` when required."""
    tokens = TOKEN.findall(value)
    known = known_ids()
    for token in tokens:
        r.check(token in known, f"{where}: неразрешимая ссылка {token}")
    if required:
        r.check(any(t.startswith(kinds) for t in tokens), f"{where}: нет основания ({'/'.join(kinds)}…)")
    return tokens


def decision_files() -> dict[int, Path]:
    found = {}
    for path in (PLAN / "decisions").glob("D*.json"):
        match = re.fullmatch(r"D(\d+)", path.stem)
        if match:
            found[int(match.group(1))] = path
    return found


def source_map_paths(numbers: tuple[int, ...]) -> list[str]:
    text = file_text(RESEARCH / "P1-sources.md")
    found = []
    for heading, body in sections(text).items():
        match = re.match(r"(\d+)\.", heading)
        if match and int(match.group(1)) in numbers:
            found += re.findall(r"`(/[^`]+)`", body)
    return found


# --- P1.1 / P1.2: requirements and corpus ledger -----------------------------

def check_ledger(r: Report, tiers: set[str]) -> None:
    where = "knowledge/corpus-ledger.md"
    text = read(KNOW / "corpus-ledger.md", r)
    corpus = inventory("P1-corpus.md")
    if not r.check(bool(corpus), "research/P1-corpus.md: инвентарь пуст или отсутствует"):
        return
    if text is None:
        return
    body = section(text, "Леджер", r, where)
    if body is None:
        return
    found = find_table(body, ["SRC", "Диспозиция", "Требования", "Примечание"])
    if not r.check(found is not None, f"{where}: нет таблицы `SRC | Диспозиция | Требования | Примечание`"):
        return
    _, rows, pos = found
    by_id = {}
    for row in rows:
        ident = strip_md(cell(row, pos["SRC"]))
        r.check(ident in corpus, f"{where}: {ident} нет в инвентаре")
        r.check(ident not in by_id, f"{where}: повтор {ident}")
        by_id[ident] = row
    for ident, inv_row in corpus.items():
        tier = strip_md(cell(inv_row, 1))
        if tier not in tiers:
            continue
        row = by_id.get(ident)
        if not r.check(row is not None, f"{where}: нет диспозиции {ident} (уровень {tier})"):
            continue
        disposition = strip_md(cell(row, pos["Диспозиция"])).lower()
        if not r.check(disposition in LEDGER_DISPOSITIONS, f"{where}: {ident} — диспозиция `{disposition}`"):
            continue
        refs, note = cell(row, pos["Требования"]), strip_md(cell(row, pos["Примечание"]))
        if disposition == "использован":
            links = provenance(r, f"{where}: {ident}", refs, REQ_KINDS)
            requirements = {}
            for header, req_rows in tables(file_text(REPO / "docs/requirements.md")):
                source = column(header, "Источник")
                if header and header[0] == "ID" and source is not None:
                    requirements.update({strip_md(cell(row, 0)): cell(row, source) for row in req_rows})
            r.check(any(ident in TOKEN.findall(requirements.get(link, "")) for link in links),
                    f"{where}: {ident} «использован», но ни одно указанное требование не ссылается на SRC")
        elif disposition == "вне границ":
            r.check("REQ-OUT-" in refs, f"{where}: {ident} «вне границ» без REQ-OUT")
            provenance(r, f"{where}: {ident}", refs, REQ_KINDS)
        elif disposition == "дубль":
            provenance(r, f"{where}: {ident}", refs + " " + note, ("SRC-", "REQ-", "INV-"))
        else:
            r.check(len(note) >= 15, f"{where}: {ident} «{disposition}» без содержательного примечания")


def check_requirements(r: Report) -> None:
    text = read(REPO / "docs" / "requirements.md", r)
    if text is not None:
        where = "docs/requirements.md"
        for prefix in ("Владение", "Профили", "Контракты", "Инварианты", "Отказы",
                       "Приёмочные сценарии", "Требования к соседям", "Вне границ", "Конфликты"):
            section(text, prefix, r, where)
        pattern = re.compile(r"^(REQ-(OWN|PROF|CON|FAIL|ACC|NBR|OUT)-\d{2,}|INV-\d{2,}|CONFLICT-\d{2,})$")
        corpus = inventory("P1-corpus.md")
        seen: set[str] = set()
        areas: set[str] = set()
        id_tables = 0
        for header, rows in tables(text):
            if not header or header[0].strip().lower() != "id":
                continue
            id_tables += 1
            source = column(header, "Источник")
            r.check(source is not None, f"{where}: таблица `{' | '.join(header)}` без колонки «Источник»")
            for row in rows:
                ident = strip_md(cell(row, 0))
                if not r.check(bool(pattern.match(ident)), f"{where}: неверный ID `{ident}`"):
                    continue
                r.check(ident not in seen, f"{where}: повтор ID {ident}")
                seen.add(ident)
                r.check(strip_md(cell(row, 1)) not in EMPTY, f"{where}: {ident} без содержания")
                if ident.startswith("REQ-"):
                    areas.add(ident.split("-")[1])
                if source is not None:
                    srcs = re.findall(r"SRC-\d{3}", cell(row, source))
                    r.check(bool(srcs), f"{where}: {ident} без SRC в «Источник»")
                    for src in srcs:
                        r.check(src in corpus, f"{where}: {ident} — {src} нет в инвентаре")
                    provenance(r, f"{where}: {ident} «Источник»", cell(row, source), ("SRC-",))
        r.check(id_tables > 0, f"{where}: нет таблиц с колонкой ID")
        for number in range(1, 9):
            r.check(f"INV-{number:02d}" in seen, f"{where}: нет INV-{number:02d}")
        for area in ("OWN", "PROF", "CON", "FAIL", "ACC", "NBR", "OUT"):
            r.check(area in areas, f"{where}: нет ни одного REQ-{area}-NN")
    check_ledger(r, {"A"})


def check_corpus(r: Report) -> None:
    check_ledger(r, {"A", "B", "C"})
    text = file_text(KNOW / "corpus-ledger.md")
    if text:
        body = section(text, "Итог прохода", r, "knowledge/corpus-ledger.md")
        if body is not None:
            r.check(len([l for l in body.splitlines() if l.strip()]) >= 3, "knowledge/corpus-ledger.md: «Итог прохода» почти пуст")


# --- P1.3: audit coverage and matrix ----------------------------------------

TOPICS = {
    "Lago": r"lago", "Kill Bill": r"kill\s*bill", "OpenMeter": r"openmeter",
    "PHP embedded path": r"embedded", "Invoice applications": r"invoice\s+app",
    "Минимальное payment storage": r"payment\s+storage", "Invoice-модуль": r"invoice[-\s]модул",
    "Server storage": r"server\s+storage", "Idempotency": r"idempoten", "Usage": r"\busage\b",
    "Credits": r"credit", "Outbox/inbox": r"outbox", "Конкуренция": r"конкуренц",
    "Расширяемость": r"расширяем", "Эволюция схемы": r"эволюц", "PHP и Dart": r"dart",
}
CRITERIA = {
    "Профили A–D": r"профил", "Embedded без сервера": r"embedded", "RU-платежи/54-ФЗ": r"54",
    "PHP+Dart": r"dart", "Single writer": r"writer", "Лицензия/edition": r"лиценз|edition",
    "Эволюция схемы": r"эволюц", "Операционная цена": r"операцион|цена",
}


def audit_ids() -> list[str]:
    return [i for i in inventory("P1-audit-inventory.md") if not re.match(r"DR0\d-T", i)]


def check_audit(r: Report) -> None:
    text = read(KNOW / "audit-matrix.md", r)
    if text is None:
        return
    where = "knowledge/audit-matrix.md"
    expected = audit_ids()
    r.check(len(expected) >= 244, f"research/P1-audit-inventory.md: {len(expected)} ID < 244")
    claims = section(text, "Утверждения", r, where)
    if claims is not None:
        found = find_table(claims, ["ID", "Статус", "Источник", "Нужна проверка"])
        if r.check(found is not None, f"{where}: нет таблицы утверждений `ID | … | Статус | Источник | Нужна проверка`"):
            _, rows, pos = found
            r.check(len(rows) > 0, f"{where}: таблица утверждений пуста")
            seen_claims = set()
            for row in rows:
                ident = strip_md(cell(row, pos["ID"]))
                r.check(ident not in seen_claims, f"{where}: повтор утверждения {ident}")
                seen_claims.add(ident)
                r.check(bool(re.fullmatch(r"CL-\d{2,}", ident)), f"{where}: неверный ID утверждения `{ident}`")
                r.check(strip_md(cell(row, pos["Статус"])).lower() in LABELS, f"{where}: {ident} — неверный статус")
                provenance(r, f"{where}: {ident} «Источник»", cell(row, pos["Источник"]),
                           ("AUD-", "C-", "V3-", "AC-", "G-", "R-", "FS-", "E-", "L-", "Q-"))
                need = strip_md(cell(row, pos["Нужна проверка"]))
                r.check(need in {"DR-01", "DR-02", "DR-03", "DR-04", "нет"}, f"{where}: {ident} — «Нужна проверка» = `{need}`")
    cover = section(text, "Покрытие аудита", r, where)
    if cover is not None:
        found = find_table(cover, ["ID", "Диспозиция", "Ссылки", "Примечание"])
        if r.check(found is not None, f"{where}: нет таблицы `ID | Диспозиция | Ссылки | Примечание`"):
            _, rows, pos = found
            by_id = {strip_md(cell(row, pos["ID"])): row for row in rows}
            for ident in expected:
                row = by_id.get(ident)
                if not r.check(row is not None, f"{where}: нет покрытия {ident}"):
                    continue
                disposition = strip_md(cell(row, pos["Диспозиция"])).lower()
                if not r.check(disposition in AUDIT_DISPOSITIONS, f"{where}: {ident} — диспозиция `{disposition}`"):
                    continue
                refs, note = cell(row, pos["Ссылки"]), strip_md(cell(row, pos["Примечание"]))
                if disposition == "учтено":
                    provenance(r, f"{where}: {ident}", refs, ("CL-",))
                elif disposition == "вне границ":
                    r.check("REQ-OUT-" in refs, f"{where}: {ident} «вне границ» без REQ-OUT")
                    provenance(r, f"{where}: {ident}", refs, REQ_KINDS)
                elif disposition == "дубль":
                    tokens = provenance(r, f"{where}: {ident}", refs, ("CL-", "AUD-", "C-", "R-", "FS-", "E-", "L-"))
                    r.check(ident not in tokens, f"{where}: {ident} «дубль» ссылается на себя")
                else:
                    r.check(len(note) >= 10, f"{where}: {ident} «нет утверждений» без объяснения")
    body = section(text, "Сводная матрица", r, where)
    if body is not None:
        matrix = None
        for header, rows in tables(body):
            joined = " ".join(header[1:]).lower()
            if all(re.search(rx, joined) for rx in CRITERIA.values()):
                matrix = (header, rows)
                break
        if r.check(matrix is not None, f"{where}: в сводной матрице нет таблицы со всеми 8 критериями"):
            header, rows = matrix
            firsts = [cell(row, 0).lower() for row in rows]
            for topic, rx in TOPICS.items():
                r.check(any(re.search(rx, value) for value in firsts), f"{where}: в матрице нет темы «{topic}»")
            for row in rows:
                for position in range(1, len(header)):
                    value = cell(row, position)
                    label = f"«{cell(row, 0)} × {header[position]}»"
                    r.check(bool(LABEL_RE.search(value)), f"{where}: ячейка {label} без метки доказанности")
                    provenance(r, f"{where}: ячейка {label}", value, ("CL-",))
    fixes = section(text, "Поправки и gaps", r, where)
    if fixes is not None:
        found = find_table(fixes, ["ID", "Суть", "Как учтено", "Требования"])
        if r.check(found is not None, f"{where}: нет таблицы `ID | Суть | Как учтено | Требования`"):
            _, rows, pos = found
            by_id = {strip_md(cell(row, pos["ID"])): row for row in rows}
            for ident in [f"AC-{n:02d}" for n in range(1, 11)] + [f"G-{n:02d}" for n in range(1, 7)]:
                row = by_id.get(ident)
                if not r.check(row is not None, f"{where}: нет строки {ident}"):
                    continue
                r.check(strip_md(cell(row, pos["Как учтено"])) not in EMPTY, f"{where}: {ident} — пусто «Как учтено»")
                provenance(r, f"{where}: {ident}", cell(row, pos["Требования"]), REQ_KINDS)
    stories = section(text, "Fault stories", r, where)
    if stories is not None:
        found = find_table(stories, ["ID", "Требования"])
        if r.check(found is not None, f"{where}: нет таблицы `ID | … | Требования` в «Fault stories → требования»"):
            _, rows, pos = found
            by_id = {strip_md(cell(row, pos["ID"])): row for row in rows}
            for number in range(1, 27):
                ident = f"FS-{number:02d}"
                row = by_id.get(ident)
                if not r.check(row is not None, f"{where}: нет {ident} в «Fault stories → требования»"):
                    continue
                value = cell(row, pos["Требования"])
                if "новое требование" not in value.lower():
                    provenance(r, f"{where}: {ident}", value, REQ_KINDS)
    candidates = section(text, "Кандидаты", r, where)
    if candidates is not None:
        r.check(len([line for line in candidates.splitlines() if line.strip()]) >= 3, f"{where}: раздел «Кандидаты» почти пуст")


# --- P1.4: primary research --------------------------------------------------

def check_research(r: Report) -> None:
    pending: dict[str, list[str]] = {key: [] for key in DR_FILES}
    claims = body_of(file_text(KNOW / "audit-matrix.md"), "Утверждения")
    if r.check(claims is not None, "нет knowledge/audit-matrix.md § Утверждения (вход P1.4 от P1.3)"):
        found = find_table(claims or "", ["ID", "Нужна проверка"])
        if found:
            _, rows, pos = found
            for row in rows:
                need = strip_md(cell(row, pos["Нужна проверка"])).replace("-", "")
                if need in pending:
                    pending[need].append(strip_md(cell(row, pos["ID"])))
    audit = inventory("P1-audit-inventory.md")
    for ident, row in audit.items():
        if re.fullmatch(r"C-\d{2}", ident):
            for dr in re.findall(r"DR-0(\d)", cell(row, 2)):
                pending[f"DR0{dr}"].append(ident)
    for key, name in DR_FILES.items():
        where = f"knowledge/research/{name}"
        text = read(KNOW / "research" / name, r)
        if text is None:
            continue
        for prefix in ("Выводы для Billium",):
            section(text, prefix, r, where)
        opened = section(text, "Открыто", r, where) or ""
        body = section(text, "Утверждения", r, where) or ""
        claim_ids: set[str] = set()
        found = find_table(body, ["ID", "Утверждение", "Источник", "Дата", "Версия", "Статус"])
        if r.check(found is not None, f"{where}: нет таблицы `ID | Утверждение | Источник | Дата | Версия | Статус`"):
            _, rows, pos = found
            r.check(len(rows) >= 3, f"{where}: меньше 3 утверждений")
            for row in rows:
                ident = strip_md(cell(row, pos["ID"]))
                r.check(bool(re.fullmatch(rf"{key}-\d{{2,}}", ident)) and ident not in claim_ids,
                        f"{where}: ID `{ident}` не `{key}-NN` или повтор")
                claim_ids.add(ident)
                status = strip_md(cell(row, pos["Статус"]))
                lowered = status.lower().strip("[]")
                if not r.check(lowered in LABELS, f"{where}: {ident} — неверный статус `{status}`"):
                    continue
                if lowered.startswith("verified-"):
                    r.check(bool(re.search(r"https?://", cell(row, pos["Источник"]))), f"{where}: {ident} verified без URL")
                    r.check(bool(re.fullmatch(r"\d{4}-\d{2}-\d{2}", strip_md(cell(row, pos["Дата"])))), f"{where}: {ident} без даты YYYY-MM-DD")
                    r.check(strip_md(cell(row, pos["Версия"])) not in EMPTY, f"{where}: {ident} без версии")
        cover = section(text, "Покрытие задания", r, where)
        if cover is not None:
            found = find_table(cover, ["ID", "Диспозиция", "Ссылки"])
            if r.check(found is not None, f"{where}: нет таблицы `ID | Диспозиция | Ссылки`"):
                _, rows, pos = found
                by_id = {strip_md(cell(row, pos["ID"])): row for row in rows}
                tasks = [i for i in audit if i.startswith(f"{key}-T")]
                r.check(bool(tasks), f"research/P1-audit-inventory.md: нет пунктов {key}-T*")
                for task in tasks:
                    row = by_id.get(task)
                    if not r.check(row is not None, f"{where}: нет покрытия {task}"):
                        continue
                    disposition = strip_md(cell(row, pos["Диспозиция"])).lower()
                    refs = cell(row, pos["Ссылки"])
                    if disposition == "ответ":
                        tokens = [t for t in TOKEN.findall(refs) if t.startswith(f"{key}-") and "-T" not in t]
                        r.check(bool(tokens) and all(t in claim_ids for t in tokens), f"{where}: {task} «ответ» без утверждений {key}-NN этого файла")
                    elif disposition == "открыто":
                        r.check(task in opened, f"{where}: {task} «открыто», но нет в `## Открыто`")
                    elif disposition == "вне границ":
                        r.check("REQ-OUT-" in refs, f"{where}: {task} «вне границ» без REQ-OUT")
                        provenance(r, f"{where}: {task}", refs, REQ_KINDS)
                    else:
                        r.err(f"{where}: {task} — диспозиция `{disposition}` вне ответ|открыто|вне границ")
        resolutions = section(text, "Проверка утверждений аудита", r, where) or ""
        found = find_table(resolutions, ["ID", "Диспозиция", "Ссылки"])
        if r.check(found is not None, f"{where}: нет таблицы проверки CL/C"):
            _, rows, pos = found
            by_id = {strip_md(cell(row, pos["ID"])): row for row in rows}
            r.check(len(by_id) == len(rows), f"{where}: повтор в таблице проверки CL/C")
            for claim in pending[key]:
                row = by_id.get(claim)
                if not r.check(row is not None, f"{where}: нет диспозиции {claim}"):
                    continue
                disposition = strip_md(cell(row, pos["Диспозиция"]))
                refs = cell(row, pos["Ссылки"])
                if disposition in {"подтверждено", "опровергнуто", "уточнено"}:
                    tokens = provenance(r, f"{where}: {claim}", refs, (key + "-",))
                    r.check(any(token in claim_ids for token in tokens), f"{where}: {claim} без факта этого DR")
                elif disposition == "открыто":
                    r.check(claim in TOKEN.findall(opened), f"{where}: {claim} нет в «Открыто»")
                else:
                    r.err(f"{where}: {claim} — неверная диспозиция {disposition}")


# --- P1.5: methods ------------------------------------------------------------

METHOD_TOPICS = [
    "spec-revisions", "json-schema", "vectors-golden", "conformance-runner", "jcs-oracle",
    "php-sdk", "dart-sdk", "minimal-core-modules", "api-spi-attributes", "type-map", "identities",
    "upgrade-map", "release-gate", "target-structure", "lock-model", "outbox-inbox",
    "problem-codes", "money-invariants", "idempotency", "test-affected-only", "mutation-testing",
    "load-testing",
]
METHOD_REPOS = {"agentools", "chatom", "azguard", "commerz", "vaulter", "erp-sdk"}


def check_methods(r: Report) -> None:
    where = "knowledge/methods.json"
    raw = read(KNOW / "methods.json", r)
    if raw is None:
        return
    try:
        data = json.loads(raw)
    except json.JSONDecodeError as exc:
        r.err(f"{where}: невалидный JSON: {exc}")
        return
    if not r.check(isinstance(data, dict), f"{where}: корень JSON не объект"):
        return
    r.check(data.get("schema") == "billium-methods/v1", f"{where}: schema != billium-methods/v1")
    r.check(bool(re.fullmatch(r"\d{4}-\d{2}-\d{2}", str(data.get("generated", "")))), f"{where}: generated не YYYY-MM-DD")
    practices = data.get("practices")
    if not r.check(isinstance(practices, list) and practices, f"{where}: нет practices"):
        return
    seen: set[str] = set()
    topics: set[str] = set()
    for index, item in enumerate(practices):
        tag = f"{where}: practices[{index}]"
        if not r.check(isinstance(item, dict), f"{tag} не объект"):
            continue
        ident = str(item.get("id", ""))
        r.check(bool(re.fullmatch(r"M-\d{2,}", ident)) and ident not in seen, f"{tag}: неверный/повторный id `{ident}`")
        seen.add(ident)
        topic = item.get("topic")
        r.check(topic in METHOD_TOPICS, f"{tag} ({ident}): topic `{topic}` вне enum")
        topics.add(str(topic))
        verdict = item.get("verdict")
        r.check(verdict in {"accept", "adapt", "reject"}, f"{tag} ({ident}): verdict `{verdict}`")
        for field in ("practice", "reason", "billium"):
            r.check(str(item.get(field, "")).strip() != "", f"{tag} ({ident}): пустое поле {field}")
        phase = str(item.get("phase", ""))
        r.check(bool(re.fullmatch(r"P([2-9]|10)|—", phase)), f"{tag} ({ident}): phase `{phase}`")
        if verdict == "reject":
            r.check(phase == "—", f"{tag} ({ident}): reject должен иметь phase «—»")
        source = item.get("source") or {}
        repo = source.get("repo") if isinstance(source, dict) else None
        spath = str(source.get("path", "")).strip() if isinstance(source, dict) else ""
        if r.check(repo in METHOD_REPOS and spath != "", f"{tag} ({ident}): source.repo/path неверны"):
            sibling = REPO.parent / str(repo)
            if sibling.is_dir():
                r.check((sibling / spath.rstrip("/")).exists(), f"{tag} ({ident}): нет пути {repo}/{spath}")
    for topic in METHOD_TOPICS:
        r.check(topic in topics, f"{where}: topic `{topic}` не покрыт")
    reviewed = data.get("sources_reviewed")
    if not r.check(isinstance(reviewed, list) and reviewed, f"{where}: нет sources_reviewed"):
        return
    covered = set()
    for index, entry in enumerate(reviewed):
        tag = f"{where}: sources_reviewed[{index}]"
        if not r.check(isinstance(entry, dict), f"{tag} не объект"):
            continue
        path = str(entry.get("path", "")).strip().rstrip("/")
        covered.add(path)
        refs = entry.get("practices") or []
        r.check(all(ref in seen for ref in refs), f"{tag}: ссылка на несуществующую практику")
        if not refs:
            r.check(len(str(entry.get("note", "")).strip()) >= 15, f"{tag}: пустой practices без note")
    for absolute in source_map_paths((6, 7)):
        logical = absolute.rstrip("/")
        try:
            logical = Path(logical).relative_to(REPO.parent).as_posix()
        except ValueError:
            pass
        r.check(logical in covered, f"{where}: источник `{logical}` (P1-sources § 6/7) не в sources_reviewed")


# --- P1.6: target architecture -------------------------------------------------

AREAS = {
    "contracts": r"contract|контракт", "execution journal": r"execution|журнал|исполнен",
    "obligations/invoicing": r"obligation|invoic", "rating/schedule": r"rating|schedule",
    "usage-приём": r"usage", "документы": r"документ", "collection": r"collection|dunning",
}


def p16_decisions() -> dict[int, dict]:
    result = {}
    for number, path in decision_files().items():
        record = json.loads(path.read_text(encoding="utf-8"))
        if number >= 9 and "P1.6" in record.get("applies_to", []):
            result[number] = record
    return result


def check_architecture(r: Report) -> None:
    where = "docs/architecture/target-architecture.md"
    text = read(REPO / "docs" / "architecture" / "target-architecture.md", r)
    decisions = p16_decisions()
    if text is not None:
        body = section(text, "Решения по областям", r, where)
        if body is not None:
            found = find_table(body, ["Область", "Решение", "Основа", "D#"])
            if r.check(found is not None, f"{where}: нет таблицы `Область | Решение | Основа | D#`"):
                _, rows, pos = found
                for area, rx in AREAS.items():
                    row = next((row for row in rows if re.search(rx, cell(row, pos["Область"]).lower())), None)
                    if not r.check(row is not None, f"{where}: нет области «{area}»"):
                        continue
                    verdict = strip_md(cell(row, pos["Решение"])).lower()
                    r.check(bool(verdict) and verdict.split()[0] in {"build", "adopt", "hybrid"},
                            f"{where}: «{area}» — решение `{verdict}` вне build|adopt|hybrid")
                    refs = [int(n) for n in re.findall(r"D(\d+)", cell(row, pos["D#"]))]
                    r.check(any(n in decisions for n in refs), f"{where}: «{area}» без D# (≥ D9, items: P1.6)")
                    provenance(r, f"{where}: «{area}» «Основа»", cell(row, pos["Основа"]), EVIDENCE_KINDS)
        for prefix in ("Профили embedded и server", "Границы с соседями"):
            section(text, prefix, r, where)
        stack = section(text, "Стек", r, where) or ""
        r.check("PHP" in stack and "Dart" in stack, f"{where}: раздел «Стек» не называет PHP и Dart")
        packages = section(text, "Пакеты", r, where)
        if packages is not None:
            found = find_table(packages, ["Пакет", "Профиль", "Потребитель", "Инвариант"])
            if r.check(found is not None, f"{where}: нет таблицы `Пакет | … | Профиль | Потребитель | Инвариант`"):
                _, rows, pos = found
                r.check(len(rows) > 0, f"{where}: таблица пакетов пуста")
                for row in rows:
                    name = strip_md(cell(row, pos["Пакет"]))
                    r.check("billium-" in name, f"{where}: пакет `{name}` не `axiomasoft/billium-*`")
                    r.check(strip_md(cell(row, pos["Потребитель"])) not in EMPTY, f"{where}: `{name}` без «Потребитель» (extraction gate)")
                    provenance(r, f"{where}: `{name}` «Инвариант»", cell(row, pos["Инвариант"]), REQ_KINDS)
    where = "docs/architecture/data-model.md"
    text = read(REPO / "docs" / "architecture" / "data-model.md", r)
    if text is not None:
        for prefix in ("ERD", "FSM денежной операции", "FSM инвойса", "Коррекции", "Retention и restore",
                       "Операции и блокировки", "Словарь данных", "Диспозиции новых требований"):
            section(text, prefix, r, where)
        r.check("erDiagram" in text, f"{where}: нет mermaid erDiagram")
        r.check(text.count("stateDiagram-v2") >= 2, f"{where}: меньше двух mermaid stateDiagram-v2")
        body = section(text, "Инварианты", r, where)
        if body is not None:
            found = find_table(body, ["ID", "Инвариант", "Constraint", "Lock", "Тест-идея", "Источник"])
            if r.check(found is not None, f"{where}: нет таблицы `ID | Инвариант | Constraint | Lock | Тест-идея | Источник`"):
                _, rows, pos = found
                ids = {strip_md(cell(row, pos["ID"])): row for row in rows}
                required = {i for i in known_ids() if i.startswith("INV-")} | {f"INV-{n:02d}" for n in range(1, 9)}
                for ident in sorted(required):
                    row = ids.get(ident)
                    if r.check(row is not None, f"{where}: нет {ident}"):
                        for key in ("Constraint", "Lock"):
                            r.check(strip_md(cell(row, pos[key])) not in EMPTY, f"{where}: {ident} без «{key}»")
                        provenance(r, f"{where}: {ident} «Источник»", cell(row, pos["Источник"]), ("REQ-", "INV-", "D"))
        stories = section(text, "Fault stories", r, where)
        if stories is not None:
            found = find_table(stories, ["ID", "Механизм", "Основание"])
            if r.check(found is not None, f"{where}: нет таблицы `ID | Механизм | Где в модели | Основание`"):
                _, rows, pos = found
                by_id = {strip_md(cell(row, pos["ID"])): row for row in rows}
                for number in range(1, 27):
                    ident = f"FS-{number:02d}"
                    row = by_id.get(ident)
                    if r.check(row is not None, f"{where}: нет {ident}"):
                        r.check(strip_md(cell(row, pos["Механизм"])) not in EMPTY, f"{where}: {ident} без механизма")
                        provenance(r, f"{where}: {ident}", cell(row, pos["Основание"]), ("REQ-", "INV-", "D"))
    structure = read(KNOW / "target-structure.md", r)
    if structure is not None:
        r.check(len([l for l in structure.splitlines() if l.strip()]) >= 20, "knowledge/target-structure.md: меньше 20 строк")
    r.check(len(decisions) >= 10, f"decisions/: D-записей P1.6 (≥ D9) {len(decisions)} < 10 (7 областей + стек + раскладка + профили)")
    for number, record in decisions.items():
        r.check(len(record.get("options", [])) >= 2, f"decisions/D{number}: меньше двух options")
        r.check(record.get("status") == "accepted", f"decisions/D{number}: status не accepted")
        evidence = " ".join(f"{e.get('ref', '')} {e.get('note', '')}" for e in record.get("evidence", []))
        if r.check(bool(evidence.strip()), f"decisions/D{number}: нет evidence"):
            provenance(r, f"decisions/D{number} evidence", evidence, EVIDENCE_KINDS)


# --- P1.7: contracts and acceptance ---------------------------------------------

def check_contracts(r: Report) -> None:
    where = "docs/contracts/billium-contracts-v1.md"
    text = read(REPO / "docs" / "contracts" / "billium-contracts-v1.md", r)
    if text is not None:
        for prefix in ("Принципы", "Usage и overage", "Obligations и invoices", "События и receipts"):
            section(text, prefix, r, where)
        r.check("payment.execute.v1" in text and "payment.outcome.v1" in text, f"{where}: нет payment.execute.v1/payment.outcome.v1")
        pay = section(text, "Оплаты и возвраты", r, where)
        if pay is not None:
            found = find_table(pay, ["Поле пилота", "Поле Billium", "Совместимость"])
            if r.check(found is not None, f"{where}: нет таблицы совместимости `Поле пилота | Поле Billium | Совместимость`"):
                _, rows, pos = found
                r.check(len(rows) > 0, f"{where}: таблица совместимости пуста")
                for row in rows:
                    if "breaking" in cell(row, pos["Совместимость"]).lower():
                        r.check(bool(re.search(r"\bD\d+\b", " ".join(row))), f"{where}: breaking без D#: {cell(row, 0)}")
        errors = section(text, "Ошибки", r, where)
        if errors is not None:
            found = find_table(errors, ["Код", "Когда", "Повторяемо", "Действие клиента", "Основание"])
            if r.check(found is not None and len(found[1]) > 0, f"{where}: нет таблицы `Код | Когда | Повторяемо | Действие клиента | Основание`"):
                _, rows, pos = found
                for row in rows:
                    provenance(r, f"{where}: ошибка {cell(row, 0)}", cell(row, pos["Основание"]), EVIDENCE_KINDS + ("D",))
        fixtures = section(text, "План fixtures", r, where)
        if fixtures is not None:
            found = find_table(fixtures, ["Набор", "Что проверяет", "PHP", "Dart", "Оракул"])
            r.check(found is not None and len(found[1]) > 0, f"{where}: нет таблицы `Набор | Что проверяет | PHP | Dart | Оракул`")
        trace = section(text, "Трассировка", r, where)
        if trace is not None:
            found = find_table(trace, ["Элемент", "Основание"])
            if r.check(found is not None and len(found[1]) >= 5, f"{where}: нет таблицы `Элемент | Основание` (≥ 5 строк)"):
                _, rows, pos = found
                for row in rows:
                    provenance(r, f"{where}: «{cell(row, pos['Элемент'])}»", cell(row, pos["Основание"]), EVIDENCE_KINDS + ("D",))
    where = "docs/acceptance-catalog.md"
    text = read(REPO / "docs" / "acceptance-catalog.md", r)
    if text is None:
        return
    for prefix in ("Пилот PB", "Prerequisites PF", "MG, FD, SB, EC", "Concurrency", "Restore, clone, lost response"):
        section(text, prefix, r, where)
    rows_by_id: dict[str, list[str]] = {}
    for header, rows in tables(text):
        pos = {name: column(header, name) for name in ("ID", "Сценарий", "Сторона", "Фаза", "Требования", "Контракт", "Примечание")}
        if any(value is None for value in pos.values()):
            continue
        for row in rows:
            ident = strip_md(cell(row, pos["ID"]))
            r.check(ident not in rows_by_id, f"{where}: повтор {ident}")
            rows_by_id[ident] = row
            side = strip_md(cell(row, pos["Сторона"]))
            r.check(bool(re.fullmatch(r"billium|сосед:\S+|n/a", side)), f"{where}: {ident} — «Сторона» `{side}`")
            r.check(strip_md(cell(row, pos["Сценарий"])) not in EMPTY, f"{where}: {ident} без сценария")
            if side == "billium":
                r.check(bool(re.search(r"\bP(?:[2-9]|10)\b", cell(row, pos["Фаза"]))), f"{where}: {ident} без фазы реализации")
                r.check(strip_md(cell(row, pos["Контракт"])) not in EMPTY, f"{where}: {ident} без контракта/модели")
            else:
                r.check(len(strip_md(cell(row, pos["Примечание"]))) >= 15, f"{where}: {ident} вне Billium без причины")
                provenance(r, f"{where}: {ident} диспозиция", cell(row, pos["Требования"]), ("REQ-OUT-", "REQ-NBR-"))
            provenance(r, f"{where}: {ident}", cell(row, pos["Требования"]), REQ_KINDS, required=side.startswith("billium"))
    r.check(bool(rows_by_id), f"{where}: нет таблиц `ID | Сценарий | Сторона | Фаза | Требования | Контракт`")
    expected = inventory("P1-acceptance-inventory.md")
    r.check(len(expected) == 82, f"{where}: snapshot приёмки должен содержать 82 ID")
    for ident in expected:
        r.check(ident in rows_by_id, f"{where}: нет {ident}")
    for ident in rows_by_id:
        r.check(ident in expected or bool(re.fullmatch(r"ACC-(CON|RST)-\d{2,}", ident)), f"{where}: неизвестный сценарий {ident}")
    for prefix in ("MG-", "FD-", "SB-", "EC-", "ACC-CON-", "ACC-RST-"):
        r.check(any(key.startswith(prefix) for key in rows_by_id), f"{where}: нет строк {prefix}*")
    coverage = section(text, "Покрытие требований", r, where) or ""
    found = find_table(coverage, ["ID", "Диспозиция", "Сценарии", "Основание"])
    if r.check(found is not None, f"{where}: нет таблицы покрытия REQ/INV"):
        _, rows, pos = found
        by_id = {strip_md(cell(row, pos["ID"])): row for row in rows}
        requirements = {i for i in first_column_ids(file_text(REPO / "docs/requirements.md")) if i.startswith(REQ_KINDS)}
        r.check(set(by_id) == requirements, f"{where}: покрытие REQ/INV неполно или содержит лишние ID")
        r.check(len(by_id) == len(rows), f"{where}: дубликаты в покрытии REQ/INV")
        for ident, row in by_id.items():
            disposition = strip_md(cell(row, pos["Диспозиция"]))
            refs = cell(row, pos["Основание"])
            provenance(r, f"{where}: покрытие {ident}", refs, ("REQ-", "INV-", "D"))
            if disposition == "учтено":
                scenarios = TOKEN.findall(cell(row, pos["Сценарии"]))
                r.check(bool(scenarios) and all(s in rows_by_id for s in scenarios), f"{where}: {ident} без разрешимых сценариев")
            else:
                r.check(disposition in {"вопрос владельцу", "вне границ"}, f"{where}: {ident} неверная диспозиция")


# --- P1.8: roadmap, owner questions, Brain, entrypoint ---------------------------

def check_roadmap(r: Report) -> None:
    where = "knowledge/roadmap-proposal.md"
    text = read(KNOW / "roadmap-proposal.md", r)
    if text is not None:
        parts = sections(text)
        phases = {k.split()[0]: v for k, v in parts.items() if re.match(r"P\d+\b", k)}
        deviates = any(k.lower().startswith("отклонения от скелета") for k in parts)
        if not deviates:
            for phase in [f"P{n}" for n in range(2, 11)]:
                r.check(phase in phases, f"{where}: нет раздела `## {phase}`")
        else:
            r.check("P2" in phases, f"{where}: нет раздела `## P2`")
        for phase, body in phases.items():
            for label in ("**Цель:**", "**Выходы:**", "**Зависит от:**", "**Решения:**",
                          "**Приёмка:**", "**Вопросы/риски:**"):
                r.check(label in body, f"{where}: {phase} без {label}")
            decided = re.search(r"\*\*Решения:\*\*(.+)", body)
            if decided:
                provenance(r, f"{where}: {phase} «Решения»", decided.group(1), ("D", "REQ-", "INV-", "ACC-", "PB-", "PF-"))
    where = "knowledge/owner-questions.md"
    text = read(KNOW / "owner-questions.md", r)
    if text is not None:
        found = find_table(text, ["ID", "Вопрос", "Варианты", "Рекомендация", "Блокирует"])
        if r.check(found is not None, f"{where}: нет таблицы `ID | Вопрос | Варианты | Рекомендация | Блокирует`"):
            _, rows, pos = found
            ids = {strip_md(cell(row, pos["ID"])) for row in rows}
            conflicts = {i for i in first_column_ids(file_text(REPO / "docs" / "requirements.md")) if i.startswith("CONFLICT-")}
            for ident in ("I-01", "I-02", "I-16", "I-28", "BRAIN-REPO", *sorted(conflicts)):
                r.check(ident in ids, f"{where}: нет вопроса {ident}")
            for row in rows:
                provenance(r, f"{where}: {cell(row, pos['ID'])} «Рекомендация»", cell(row, pos["Рекомендация"]),
                           ("D", "REQ-", "INV-", "CL-", "DR0", "SRC-"))
        lowered = text.lower()
        for word, label in (("psp", "PSP первого запуска"), ("валют", "валюты"), ("memster", "первый платный потребитель")):
            r.check(word in lowered, f"{where}: нет вопроса про {label}")
    where = "knowledge/brain-updates.md"
    text = read(KNOW / "brain-updates.md", r)
    if text is not None:
        for prefix in ("Package-Status", "Bridge-Catalog", "CD-предложения"):
            body = section(text, prefix, r, where)
            if body is not None:
                no_change = r"|не требуется\s*—\s*\S+" if prefix == "CD-предложения" else ""
                r.check(bool(re.search(r"Brain commit:\s*(`?[0-9a-f]{7,40}`?|не применено\s*—\s*\S+" + no_change + r")", body)),
                        f"{where}: в «{prefix}» нет SHA или причины неприменения (для CD допустимо «не требуется»)")
    for path in (REPO / ".ai" / "guidelines" / "agent-entrypoint.md", REPO / "README.md"):
        content = read(path, r)
        if content is not None:
            r.check(bool(re.search(r"Dart[^\n]{0,80}(клиент|client)|(клиент|client)[^\n]{0,80}Dart", content)),
                    f"{rel(path)}: роль Dart как клиента контрактов не указана")


# --- P1.9: review verdict -----------------------------------------------------------

def check_review(r: Report) -> None:
    where = "reviews/P1-review.md"
    text = read(PLAN / "reviews" / "P1-review.md", r)
    if text is None:
        return
    verdict = re.search(r"^\*\*Verdict:\*\*\s*(GREEN|RED)\s*$", text, re.MULTILINE)
    r.check(verdict is not None, f"{where}: нет строки `**Verdict:** GREEN|RED`")
    section(text, "Проверено", r, where)
    found = find_table(text, ["ID", "Severity", "Где", "Суть", "Owning item"])
    if not r.check(found is not None, f"{where}: нет таблицы `ID | Severity | Где | Суть | Owning item`"):
        return
    _, rows, pos = found
    severe = False
    for row in rows:
        ident = strip_md(cell(row, pos["ID"]))
        if ident in EMPTY:
            continue
        severity = strip_md(cell(row, pos["Severity"])).lower()
        r.check(severity in {"blocker", "major", "minor"}, f"{where}: {ident} — severity `{severity}`")
        severe = severe or severity in {"blocker", "major"}
        r.check(bool(re.fullmatch(r"P1\.[1-8]", strip_md(cell(row, pos["Owning item"])))), f"{where}: {ident} — owning item не P1.1–P1.8")
        r.check(bool(re.search(rf"^### {re.escape(ident)} — ", text, re.MULTILINE)), f"{where}: нет заголовка `### {ident} — …`")
    if verdict and verdict.group(1) == "GREEN":
        r.check(not severe, f"{where}: GREEN при наличии blocker/major")
    if verdict:
        r.check(verdict.group(1) == "GREEN", f"{where}: verdict RED — P1.9 не закрыта")


CHECKS = {"P1.1": check_requirements, "P1.2": check_corpus, "P1.3": check_audit,
          "P1.4": check_research, "P1.5": check_methods, "P1.6": check_architecture,
          "P1.7": check_contracts, "P1.8": check_roadmap, "P1.9": check_review}


def main(argv: list[str]) -> int:
    if len(argv) != 2 or argv[1] not in {*CHECKS, "pre-review", "all"}:
        print(f"usage: {Path(argv[0]).name} {'|'.join(CHECKS)}|pre-review|all", file=sys.stderr)
        return 2
    selected = list(CHECKS) if argv[1] == "all" else list(CHECKS)[:-1] if argv[1] == "pre-review" else [argv[1]]
    failed = False
    for item in selected:
        report = Report(item)
        CHECKS[item](report)
        if report.errors:
            failed = True
            print(f"{item}: RED ({len(report.errors)})")
            for message in report.errors[:60]:
                print(f"  - {message}")
            if len(report.errors) > 60:
                print(f"  … ещё {len(report.errors) - 60}")
        else:
            print(f"{item}: GREEN")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
