#!/usr/bin/env python3
"""Unit tests for conformance/consumers/bridge/check-report.py."""

from __future__ import annotations

import copy
import importlib.util
import json
from pathlib import Path
import sys
import tempfile
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

_run_spec = importlib.util.spec_from_file_location("bridge_run", BRIDGE / "run.py")
assert _run_spec is not None and _run_spec.loader is not None
bridge_run = importlib.util.module_from_spec(_run_spec)
_run_spec.loader.exec_module(bridge_run)


class CheckReportFixtureTest(unittest.TestCase):
    def test_report_fixtures_pass_positive_and_reject_negatives(self) -> None:
        findings = check_report.check_fixture_directory(HERE / "reports", repo_root=ROOT)
        self.assertEqual([], findings)

    def test_bundled_reference_adapter_cannot_promote_external_ready_or_rollout_gate(self) -> None:
        valid_ext = json.loads((HERE / "reports/valid-external-runtime-passed.json").read_text(encoding="utf-8"))

        # 1. Explicit isBundledReference=True is rejected when externalReady/rolloutGatePassed=True.
        flagged = copy.deepcopy(valid_ext)
        flagged["consumer"]["isBundledReference"] = True
        flagged["reportDigest"] = check_report.canonical_digest(flagged, "reportDigest")
        findings_flagged = check_report.validate_report(flagged, repo_root=ROOT)
        self.assertTrue(
            any(f.startswith("external_ready_bundled_reference_forbidden:") for f in findings_flagged),
            findings_flagged,
        )

        # 2. Bundled reference adapter name or packageHash is also rejected even without isBundledReference.
        by_hash = copy.deepcopy(valid_ext)
        bundled_hash = check_report.bundled_reference_package_hash(ROOT)
        assert bundled_hash is not None
        by_hash["consumer"]["packageHash"] = bundled_hash
        by_hash["reportDigest"] = check_report.canonical_digest(by_hash, "reportDigest")
        findings_hash = check_report.validate_report(by_hash, repo_root=ROOT)
        self.assertTrue(
            any(f.startswith("external_ready_bundled_reference_forbidden:") for f in findings_hash),
            findings_hash,
        )

        # 3. Passing the bundled reference adapter command to run.py with --boundary external-runtime is refused.
        with tempfile.TemporaryDirectory() as tmp:
            out_report = Path(tmp) / "bundled-external.json"
            rc = bridge_run.main([
                "--boundary",
                "external-runtime",
                "--suite",
                "CT-01",
                "--consumer-cmd",
                "php conformance/consumers/bridge/adapter.php",
                "--report",
                str(out_report),
            ])
            self.assertNotEqual(0, rc)
            self.assertFalse(out_report.exists())

    def test_external_ready_rejects_loopback_boundaries_in_transcript_and_evidence(self) -> None:
        valid_ext = json.loads((HERE / "reports/valid-external-runtime-passed.json").read_text(encoding="utf-8"))
        spoofed = copy.deepcopy(valid_ext)
        for result in spoofed.get("results", []):
            for step in result.get("transcript", []):
                step["boundary"] = "loopback"
        for ev in spoofed.get("evidence", []):
            ev["boundary"] = "loopback"
        spoofed["reportDigest"] = check_report.canonical_digest(spoofed, "reportDigest")

        findings = check_report.validate_report(spoofed, repo_root=ROOT)
        self.assertTrue(
            any(f.startswith("external_ready_non_external_evidence:") for f in findings),
            findings,
        )
        self.assertTrue(
            any(f.startswith("external_ready_missing_external_evidence:") for f in findings),
            findings,
        )
        self.assertTrue(
            any(f.startswith("external_ready_non_external_transcript:") for f in findings),
            findings,
        )


if __name__ == "__main__":
    unittest.main()
