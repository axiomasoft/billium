"""Tests for scripts/spec/check-import-contract.py (--check, --source-mappings, --handoff and D71 guards)."""
from __future__ import annotations

import copy
import importlib.util
import json
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
SCRIPT = ROOT / "scripts/spec/check-import-contract.py"


def load_checker():
    spec = importlib.util.spec_from_file_location("check_import_contract", SCRIPT)
    assert spec is not None and spec.loader is not None
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class CheckImportContractTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.checker = load_checker()

    def test_cli_check_mode_passes_on_repository(self) -> None:
        self.assertEqual(0, self.checker.main(["--check"]))

    def test_cli_source_mappings_mode_passes_on_repository(self) -> None:
        self.assertEqual(0, self.checker.main(["--source-mappings"]))

    def test_cli_source_mappings_manifest_passes_and_rejects_implicit_missing_evidence(self) -> None:
        self.assertEqual(0, self.checker.main(["--source-mappings", "docs/migrations/source-mapping.json"]))

        manifest = json.loads((ROOT / "docs/migrations/source-mapping.json").read_text(encoding="utf-8"))
        self.assertEqual([], self.checker.validate_source_mapping_manifest(manifest, ROOT))

        bad_decision = copy.deepcopy(manifest)
        for profile in bad_decision["profiles"]:
            for dec in profile["missingEvidenceDecisions"]:
                if dec["targetRecordKind"] == "unanchored_evidence":
                    dec["reasonCodes"] = []
                    dec["collectionAllowed"] = True
                    break
        findings = self.checker.validate_source_mapping_manifest(bad_decision, ROOT)
        self.assertTrue(any("reasonCodes" in msg for msg in findings))
        self.assertTrue(any("collectionAllowed" in msg for msg in findings))

    def test_cli_handoff_mode_passes_on_repository(self) -> None:
        self.assertEqual(0, self.checker.main(["--handoff"]))

    def test_missing_source_keys_and_bad_digest_are_rejected(self) -> None:
        registry, documents = self.checker.build_registry()
        valid = json.loads((ROOT / "fixtures/integration/import/schema/valid-proven-obligation.json").read_text(encoding="utf-8"))
        self.assertEqual([], self.checker.validate_envelope_document(valid, "import/external.v1.json", registry, documents))

        missing_keys = copy.deepcopy(valid)
        del missing_keys["sourceBook"]
        del missing_keys["sourceRecordId"]
        findings = self.checker.validate_envelope_document(missing_keys, "import/external.v1.json", registry, documents)
        self.assertTrue(findings)

        tampered = copy.deepcopy(valid)
        tampered["digest"] = "sha256:" + ("0" * 64)
        digest_findings = self.checker.validate_envelope_document(tampered, "import/external.v1.json", registry, documents)
        self.assertTrue(any("digest mismatch" in msg for msg in digest_findings))

    def test_conflicting_mapping_and_batch_as_identity_are_rejected(self) -> None:
        valid = json.loads((ROOT / "fixtures/integration/import/schema/mappings/valid-source-mappings.json").read_text(encoding="utf-8"))
        self.assertEqual([], self.checker.validate_source_mapping_document(valid))

        conflict = copy.deepcopy(valid)
        second = copy.deepcopy(conflict["mappings"][0])
        second["payloadDigest"] = "sha256:" + ("f" * 64)
        conflict["mappings"].append(second)
        findings = self.checker.validate_source_mapping_document(conflict)
        self.assertTrue(any("conflicting payloadDigest" in msg for msg in findings))

        batch_keyed = copy.deepcopy(valid)
        batch_keyed["batchIdInEconomicIdentity"] = True
        self.assertTrue(self.checker.validate_source_mapping_document(batch_keyed))

        renamed = copy.deepcopy(valid)
        renamed["renamesSourceBookOnMapperUpdate"] = True
        self.assertTrue(self.checker.validate_source_mapping_document(renamed))

    def test_activation_without_handoff_or_with_commit_no_proof_is_rejected(self) -> None:
        valid = json.loads((ROOT / "fixtures/integration/import/schema/handoff/valid-handoff-evidence.json").read_text(encoding="utf-8"))
        self.assertEqual([], self.checker.validate_handoff_document(valid))

        no_freeze = copy.deepcopy(valid)
        del no_freeze["sourceFreezeCertificate"]
        findings = self.checker.validate_handoff_document(no_freeze)
        self.assertTrue(any("SourceFreezeCertificate" in msg or "sourceFreezeCertificate" in msg for msg in findings))

        commit_no = copy.deepcopy(valid)
        commit_no["usesCommitNoAsFreshnessProof"] = True
        commit_findings = self.checker.validate_handoff_document(commit_no)
        self.assertTrue(any("commit_no" in msg for msg in commit_findings))

    def test_handoff_refuses_missing_and_rehashed_mismatched_evidence(self) -> None:
        valid = json.loads((ROOT / "fixtures/integration/import/schema/handoff/valid-handoff-evidence.json").read_text(encoding="utf-8"))
        validator = self.checker.handoff_validator(self.checker.REV)
        self.assertEqual([], self.checker.validate_handoff_document(valid, validator))

        for field in ("snapshotToken", "snapshotDigest", "safeFeedCursor", "retentionLeaseRef", "retentionLeaseExpiresAt",
                      "selectionDigest", "eventContractRevision", "perSellerCurrencyChecksums"):
            with self.subTest(missing=field):
                changed = copy.deepcopy(valid)
                del changed["migrationManifest"][field]
                findings = self.checker.validate_handoff_document(changed, validator)
                self.assertTrue(any(field in error for error in findings), findings)

        for path, value, message in (
            (("sourceFreezeCertificate", "sealReceiptRef"), "other-seal", "scope or seal mismatch"),
            (("migrationManifest", "snapshotToken"), "other-snapshot", "differs from the verified transfer"),
            (("migrationManifest", "safeFeedCursor", "token"), "another-subscription", "differs from the verified transfer"),
            (("migrationManifest", "retentionLeaseRef"), "other-lease", "differs from the verified transfer"),
            (("migrationManifest", "retentionLeaseExpiresAt"), "2020-01-01T00:00:00Z", "retention lease expired"),
            (("migrationManifest", "eventContractRevision"), "other-catalog", "selectionDigest scope or catalog mismatch"),
            (("migrationManifest", "plan", "source", "owner", "deploymentGeneration"), 9, "scope or seal mismatch"),
            (("sealedTail", "terminalCursor", "txId"), "1", "tail cursor regresses"),
            (("targetReceipt", "perSellerCurrencyChecksums", 0, "totals", "open"), "9", "counts, totals or checksum mismatch"),
            (("targetReceipt", "perSellerCurrencyChecksums", 0, "checksum"), "sha256:" + "a" * 64, "counts, totals or checksum mismatch"),
            (("activation", "activationCertificate", "backupLineageRef"), "other-lineage", "context/lineage mismatch"),
            (("activation", "retirementProof", "oldOwner", "ownerSessionRef"), "copied-session", "context/lineage mismatch"),
        ):
            with self.subTest(tampered=path):
                changed = copy.deepcopy(valid)
                node = changed
                for member in path[:-1]:
                    node = node[member]
                node[path[-1]] = value
                manifest = changed["migrationManifest"]
                manifest["manifestDigest"] = self.checker.canonical_digest(manifest, "manifestDigest")
                changed["digest"] = self.checker.canonical_digest(changed)
                findings = self.checker.validate_handoff_document(changed, validator)
                self.assertTrue(any(message in error for error in findings), findings)

    def test_d71_isolation_rejects_external_product_and_legacy_terms(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            bad = Path(tmp) / "leak.json"
            bad.write_text('{"schema": "' + "leg" + 'acy.import.v1", "product": "' + "Mem" + 'ster"}', encoding="utf-8")
            findings = self.checker.check_d71_isolation([bad], Path(tmp))
            self.assertGreaterEqual(len(findings), 2)


if __name__ == "__main__":
    unittest.main()
