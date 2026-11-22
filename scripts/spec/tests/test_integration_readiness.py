"""Unit tests for scripts/spec/check-integration-readiness.py (P9.8 / V981)."""
from __future__ import annotations

import copy
import importlib.util
import json
from pathlib import Path
import sys
import unittest

ROOT = Path(__file__).resolve().parents[3]
SPEC_PATH = ROOT / "scripts/spec/check-integration-readiness.py"
spec = importlib.util.spec_from_file_location("check_integration_readiness", SPEC_PATH)
assert spec is not None and spec.loader is not None
checker = importlib.util.module_from_spec(spec)
sys.modules["check_integration_readiness"] = checker
spec.loader.exec_module(checker)


def load_manifest() -> dict:
    return json.loads((ROOT / "artifacts/integration-readiness.json").read_text(encoding="utf-8"))


class IntegrationReadinessCheckerTest(unittest.TestCase):
    def test_valid_repository_manifest_passes(self) -> None:
        manifest = load_manifest()
        findings = checker.validate_manifest(manifest, repo_root=ROOT)
        self.assertEqual([], findings)

    def test_rejects_double_or_loopback_promoted_as_provider_ready(self) -> None:
        manifest = load_manifest()
        manifest["gates"][4]["evidence_class"] = "provider-live"
        manifest["activation_status"]["production_provider_qualified"] = True
        manifest["manifestDigest"] = checker.canonical_digest(manifest, "manifestDigest")
        findings = checker.validate_manifest(manifest, repo_root=ROOT)
        self.assertTrue(any(f.startswith("double_promotion_forbidden:") for f in findings), findings)

    def test_rejects_missing_or_tampered_artifact_hash(self) -> None:
        manifest = load_manifest()
        manifest["tracked_artifacts"][0]["sha256"] = "sha256:" + ("0" * 64)
        manifest["manifestDigest"] = checker.canonical_digest(manifest, "manifestDigest")
        findings = checker.validate_manifest(manifest, repo_root=ROOT)
        self.assertTrue(any(f.startswith("artifact_hash_mismatch:") for f in findings), findings)

        manifest2 = load_manifest()
        del manifest2["tracked_artifacts"][0]["sha256"]
        manifest2["manifestDigest"] = checker.canonical_digest(manifest2, "manifestDigest")
        findings2 = checker.validate_manifest(manifest2, repo_root=ROOT)
        self.assertTrue(any(f.startswith("missing_artifact_hash:") for f in findings2), findings2)

    def test_rejects_undefined_owner_or_missing_retention_policy(self) -> None:
        manifest = load_manifest()
        manifest["gates"][0]["owner"] = "TBD"
        del manifest["external_inputs"]["retention_floor_policy"]
        manifest["manifestDigest"] = checker.canonical_digest(manifest, "manifestDigest")
        findings = checker.validate_manifest(manifest, repo_root=ROOT)
        self.assertTrue(any(f.startswith("undefined_owner:") for f in findings), findings)
        self.assertTrue(any(f.startswith("undefined_owner_or_retention:") for f in findings), findings)

    def test_rejects_pending_gate_marked_active_or_remote_active(self) -> None:
        manifest = load_manifest()
        pending_gate = next(g for g in manifest["gates"] if g["state"] == "pending")
        pending_gate["active"] = True
        manifest["activation_status"]["remote_execution_active"] = True
        manifest["manifestDigest"] = checker.canonical_digest(manifest, "manifestDigest")
        findings = checker.validate_manifest(manifest, repo_root=ROOT)
        self.assertTrue(any(f.startswith("pending_gate_marked_active:") for f in findings), findings)

    def test_rejects_not_applicable_without_accepted_decision(self) -> None:
        manifest = load_manifest()
        na_gate = next(g for g in manifest["gates"] if g["state"] == "not-applicable")
        na_gate["decision_ref"] = ""
        manifest["manifestDigest"] = checker.canonical_digest(manifest, "manifestDigest")
        findings = checker.validate_manifest(manifest, repo_root=ROOT)
        self.assertTrue(any(f.startswith("not_applicable_without_decision:") for f in findings), findings)

    def test_rejects_d71_forbidden_terms(self) -> None:
        manifest = load_manifest()
        manifest["gates"][0]["reason"] = "external " + "mem" + "ster" + " reference"
        manifest["manifestDigest"] = checker.canonical_digest(manifest, "manifestDigest")
        findings = checker.validate_manifest(manifest, repo_root=ROOT)
        self.assertTrue(any(f.startswith("d71_forbidden_term:") for f in findings), findings)

    def test_rejects_automatic_fallback_and_unanchored_collection(self) -> None:
        manifest = copy.deepcopy(load_manifest())
        manifest["limitations"]["rollback_and_fallback"]["automatic_fallback_after_target_write_allowed"] = True
        manifest["limitations"]["unanchored_evidence"]["collection_allowed"] = True
        manifest["manifestDigest"] = checker.canonical_digest(manifest, "manifestDigest")
        findings = checker.validate_manifest(manifest, repo_root=ROOT)
        self.assertTrue(any(f.startswith("fallback_rule_violated:") for f in findings), findings)
        self.assertTrue(any(f.startswith("unanchored_limitation_violated:") for f in findings), findings)


if __name__ == "__main__":
    unittest.main()
