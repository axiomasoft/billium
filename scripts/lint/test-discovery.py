#!/usr/bin/env python3
"""MECH-02: prove Pest selectors contain tests without running the selected suite."""

from __future__ import annotations

import subprocess
import sys
import tempfile
import xml.etree.ElementTree as ET
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
P3_SELECTORS = (
    "ConcurrencyStandTest",
    "RuntimeRoleGrantsTest",
    "ModuleBoundariesTest",
    "TableOwnershipTest",
)
MISSING_FILTER = "__billium_mech02_intentionally_nonexistent_test__"


def discover(selectors: list[str], runner=subprocess.run) -> int:
    """Forward selection arguments to Pest and reject a missing or empty inventory."""
    with tempfile.TemporaryDirectory(prefix="billium-discovery-") as directory:
        output = Path(directory) / "tests.xml"
        result = runner(
            ["vendor/bin/pest", *selectors, "--list-tests-xml", str(output)],
            cwd=ROOT,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            timeout=60,
            check=False,
        )
        if result.returncode != 0:
            raise RuntimeError(f"Pest discovery failed ({result.returncode}): {result.stdout}")
        try:
            tree = ET.parse(output)
        except (OSError, ET.ParseError) as exception:
            raise RuntimeError("Pest did not produce a valid test inventory") from exception
        count = sum(
            node.tag.rsplit("}", 1)[-1] in {"testMethod", "phpt"}
            for node in tree.iter()
        )
        if count == 0:
            raise RuntimeError(f"Empty Pest selection: {' '.join(selectors) or '<default>'}")
        return count


def prove_empty_filter_is_red(runner=subprocess.run) -> None:
    result = runner(
        ["vendor/bin/pest", f"--filter={MISSING_FILTER}"],
        cwd=ROOT,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        timeout=60,
        check=False,
    )
    if result.returncode == 0 or "No tests found" not in result.stdout:
        raise RuntimeError(
            "MECH-02: nonexistent filter must fail because no tests were found: "
            + result.stdout
        )


def main(selectors: list[str]) -> int:
    try:
        if selectors:
            print(f"test-discovery: {discover(selectors)} tests selected")
        else:
            print(f"test-discovery: {discover([])} tests registered")
            for selector in P3_SELECTORS:
                print(f"test-discovery: {selector}: {discover([f'--filter={selector}'])} tests")
            prove_empty_filter_is_red()
            print("test-discovery: nonexistent filter returned nonzero (expected)")
    except (RuntimeError, OSError, subprocess.TimeoutExpired) as exception:
        print(f"test-discovery: FAIL: {exception}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
