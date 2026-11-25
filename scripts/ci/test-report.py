#!/usr/bin/env python3
"""Summarise JUnit reports from CI test jobs as Markdown.

Usage: python3 scripts/ci/test-report.py [--slowest N] <report.xml | dir>...
Prints per-report totals, the overall total and the slowest test cases. GitHub Actions
appends the output to the job summary. Exits non-zero when no report is found.
"""

from __future__ import annotations

import argparse
import sys
import xml.etree.ElementTree as ET
from dataclasses import dataclass
from pathlib import Path


@dataclass
class Totals:
    tests: int = 0
    failures: int = 0
    errors: int = 0
    skipped: int = 0
    seconds: float = 0.0

    def add(self, other: Totals) -> None:
        self.tests += other.tests
        self.failures += other.failures
        self.errors += other.errors
        self.skipped += other.skipped
        self.seconds += other.seconds


@dataclass(frozen=True)
class Case:
    name: str
    seconds: float


def collect(paths: list[str]) -> list[Path]:
    reports: list[Path] = []
    for raw in paths:
        path = Path(raw)
        if path.is_dir():
            reports.extend(sorted(path.rglob("*.xml")))
        elif path.is_file():
            reports.append(path)
    return reports


def read(report: Path) -> tuple[Totals, list[Case]]:
    root = ET.parse(report).getroot()
    totals = Totals()
    cases: list[Case] = []
    for case in root.iter("testcase"):
        totals.tests += 1
        seconds = float(case.get("time", "0") or 0)
        totals.seconds += seconds
        if case.find("failure") is not None:
            totals.failures += 1
        elif case.find("error") is not None:
            totals.errors += 1
        elif case.find("skipped") is not None:
            totals.skipped += 1
        owner = case.get("class") or case.get("classname") or case.get("file") or ""
        cases.append(Case(f"{owner}::{case.get('name', '?')}".lstrip(":"), seconds))
    return totals, cases


def row(label: str, totals: Totals) -> str:
    passed = totals.tests - totals.failures - totals.errors - totals.skipped
    return (
        f"| {label} | {totals.tests} | {passed} | {totals.failures} | {totals.errors} "
        f"| {totals.skipped} | {totals.seconds:.1f} |"
    )


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--slowest", type=int, default=10)
    parser.add_argument("paths", nargs="+")
    args = parser.parse_args()

    reports = collect(args.paths)
    if not reports:
        print("test-report: no JUnit report found", file=sys.stderr)
        return 1

    overall = Totals()
    cases: list[Case] = []
    lines = [
        "| Report | Tests | Passed | Failed | Errors | Skipped | Test time (s) |",
        "|---|---:|---:|---:|---:|---:|---:|",
    ]
    for report in reports:
        totals, report_cases = read(report)
        overall.add(totals)
        cases.extend(report_cases)
        lines.append(row(report.stem, totals))
    if len(reports) > 1:
        lines.append(row("**total**", overall))

    print("\n".join(lines))
    slowest = sorted(cases, key=lambda case: case.seconds, reverse=True)[: args.slowest]
    if slowest:
        print(f"\n<details><summary>Slowest {len(slowest)} tests</summary>\n")
        print("| Test | Seconds |\n|---|---:|")
        for case in slowest:
            name = case.name.replace("|", "\\|")
            print(f"| `{name}` | {case.seconds:.2f} |")
        print("\n</details>")
    return 0


if __name__ == "__main__":
    sys.exit(main())
