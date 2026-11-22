#!/usr/bin/env python3
"""Unit tests for conformance/consumers/bridge/check-report.py."""

from __future__ import annotations

import importlib.util
from pathlib import Path
import sys
import unittest

HERE = Path(__file__).resolve().parent
BRIDGE = HERE.parent
ROOT = BRIDGE.parents[2]
if str(BRIDGE) not in sys.path:
    sys.path.insert(0, str(BRIDGE))

_spec = importlib.util.spec_from_file_location("check_report", BRIDGE / "check-report.py")
assert _spec is not None and _spec.loader is not None
check_report = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(check_report)


class CheckReportFixtureTest(unittest.TestCase):
    def test_report_fixtures_pass_positive_and_reject_negatives(self) -> None:
        findings = check_report.check_fixture_directory(HERE / "reports", repo_root=ROOT)
        self.assertEqual([], findings)


if __name__ == "__main__":
    unittest.main()
