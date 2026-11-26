"""Negative and positive examples for scripts/spec/check-module-compatibility.py."""
from __future__ import annotations

import copy
import importlib.util
import json
import tempfile
import unittest
from pathlib import Path

SCRIPTS = Path(__file__).resolve().parents[1]


def load(name: str):
    spec = importlib.util.spec_from_file_location(name.replace("-", "_"), SCRIPTS / f"{name}.py")
    assert spec is not None and spec.loader is not None
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


CHECK = load("check-module-compatibility")
EXPORT = load("export-modules")


class RegistryTest(unittest.TestCase):
    def setUp(self) -> None:
        self.document = EXPORT.load_registry()
        self.types, self.envelopes = CHECK.frozen_identities()

    def errors(self, document: dict) -> list[str]:
        return CHECK.registry_errors(document, self.types, self.envelopes)

    def module(self, document: dict, module_id: str) -> dict:
        return next(m for m in document["modules"] if m["moduleId"] == module_id)

    def mutated(self) -> dict:
        return copy.deepcopy(self.document)

    def assertRejected(self, document: dict, fragment: str) -> None:
        errors = self.errors(document)
        self.assertTrue(any(fragment in error for error in errors), f"{fragment!r} not in {errors}")

    def test_registry_is_valid(self) -> None:
        self.assertEqual([], self.errors(self.document))

    def test_cycle_unknown_dependency_and_range(self) -> None:
        document = self.mutated()
        self.module(document, "platform")["requiresModules"] = [
            {"moduleId": "ledger", "apiRange": {"min": "1.0.0", "maxExclusive": "2.0.0"}}
        ]
        self.assertRejected(document, "dependency cycle")

        document = self.mutated()
        self.module(document, "ledger")["requiresModules"][0]["moduleId"] = "ghost"
        self.assertRejected(document, "unknown module ghost")

        document = self.mutated()
        self.module(document, "ledger")["requiresModules"][0]["apiRange"] = {"min": "2.0.0", "maxExclusive": "3.0.0"}
        self.assertRejected(document, "apiRange of platform excludes")

    def test_duplicates_and_ownership(self) -> None:
        document = self.mutated()
        document["modules"].append(copy.deepcopy(document["modules"][0]))
        self.assertRejected(document, "moduleId 'platform' is declared more than once")

        document = self.mutated()
        self.module(document, "usage")["ownedTables"] = ["billium_x"]
        self.module(document, "rating")["ownedTables"] = ["billium_x"]
        self.assertRejected(document, "ownedTable 'billium_x'")

        document = self.mutated()
        self.module(document, "usage")["configKeys"] = self.module(document, "ledger")["configKeys"]
        self.assertRejected(document, "configKey")

    def test_schema_ranges_and_migration_versions(self) -> None:
        document = self.mutated()
        self.module(document, "ledger")["currentSchemaVersion"] = 1
        self.assertRejected(document, "outside the write range")

        document = self.mutated()
        module = self.module(document, "ledger")
        module["supportedSchemaWriteRange"] = {"min": 0, "max": 3}
        self.assertRejected(document, "write range must lie inside the read range")

        document = self.mutated()
        module = self.module(document, "ledger")
        module["currentSchemaVersion"] = 2
        module["supportedSchemaReadRange"] = module["supportedSchemaWriteRange"] = {"min": 0, "max": 2}
        module["migrationIdentities"] = [
            {"id": "2026_10_30_000002_b", "version": 2, "checksum": "sha256:" + "b" * 64}
        ]
        self.assertRejected(document, "migration versions must be 1..n")

    def test_ports_and_composition_matrix(self) -> None:
        document = self.mutated()
        self.module(document, "usage")["requiresPorts"] = ["Nope"]
        self.assertRejected(document, "unknown port Nope")

        document = self.mutated()
        composition = next(c for c in document["compositions"] if c["id"] == "ledger-only")
        composition["modules"]["platform"] = "X"
        self.assertRejected(document, "mandatory")

        document = self.mutated()
        composition = next(c for c in document["compositions"] if c["id"] == "remote-billium")
        del composition["modules"]["usage"]
        self.assertRejected(document, "must classify exactly the modules")

    def test_events_are_strict_v1_and_bootstrap_declared(self) -> None:
        document = self.mutated()
        ledger = self.module(document, "ledger")
        ledger["publicEvents"][0]["eventType"] = "payment.effect.outcome.v1"
        self.assertRejected(document, "not in the strict billium.event.v1 enum")

        document = self.mutated()
        self.module(document, "ledger")["publicEvents"][0]["eventType"] = "unknown.event.v1"
        self.assertRejected(document, "not in the strict billium.event.v1 enum")

        document = self.mutated()
        ledger = self.module(document, "ledger")
        ledger["publicEvents"][0]["snapshotSourceKey"] = "usage.facts"
        self.assertRejected(document, "snapshotSourceKey must be owned by ledger")

        document = self.mutated()
        self.module(document, "usage")["publicEvents"] = [copy.deepcopy(self.module(document, "ledger")["publicEvents"][0])]
        self.assertRejected(document, "also declared by ledger")

        document = self.mutated()
        ledger = self.module(document, "ledger")
        ledger["internalCommands"] = [{
            "commandType": "obligation.created.v1",
            "commandSchema": "billium.command.v1",
            "payloadSchemaRef": ledger["publicEvents"][0]["payloadSchemaRef"],
            "payloadSchemaDigest": ledger["publicEvents"][0]["payloadSchemaDigest"],
        }]
        self.assertRejected(document, "reuses a public event type")

    def test_v2_event_types_do_not_expand_v1_enum(self) -> None:
        event_type = "payment.effect.outcome.v1"
        registered_types = json.loads((CHECK.SNAPSHOTS / "event-type.json").read_text(encoding="utf-8"))
        self.assertIn(event_type, registered_types)
        self.assertNotIn(event_type, self.types)

        document = self.mutated()
        event = next(e for e in self.module(document, "execution")["publicEvents"] if e["eventType"] == event_type)
        self.assertEqual("billium.event.v2", event["envelopeSchema"])
        self.assertEqual([], self.errors(document))
        event["envelopeSchema"] = "billium.event.v1"
        event["eventContractRevision"] = "events-2026-10-v1"
        self.assertRejected(document, "not in the strict billium.event.v1 enum")

    def test_schema_requires_a_bootstrap_declaration(self) -> None:
        document = self.mutated()
        del self.module(document, "ledger")["publicEvents"][0]["bootstrapMode"]
        with tempfile.TemporaryDirectory() as directory:
            source = Path(directory) / "modules.json"
            source.write_text(json.dumps(document), encoding="utf-8")
            with self.assertRaises(CHECK.ExportError):
                EXPORT.load_registry(source)

    def test_policies_cannot_be_weakened(self) -> None:
        document = self.mutated()
        self.module(document, "ledger")["retirementPolicy"]["blockers"].remove("unknown_effects")
        self.assertRejected(document, "retirementPolicy lacks base blockers")

        document = self.mutated()
        self.module(document, "ledger")["disablePolicy"]["keeps"].remove("historical_read")
        self.assertRejected(document, "must keep receipt_replay and historical_read")


class RepositoryTest(unittest.TestCase):
    def test_repository_is_clean(self) -> None:
        document = EXPORT.load_registry()
        self.assertEqual([], CHECK.repository_errors(document))

    def test_core_config_key_missing_from_snapshot_is_rejected(self) -> None:
        document = copy.deepcopy(EXPORT.load_registry())
        document["modules"][0]["configKeys"].append("billium.unregistered_fixture_key")

        self.assertIn(
            "identity snapshot config-key differs from the module registry; run scripts/spec/identities.py",
            CHECK.repository_errors(document),
        )

    def test_payload_digest_mismatch_is_rejected(self) -> None:
        document = copy.deepcopy(EXPORT.load_registry())
        ledger = next(m for m in document["modules"] if m["moduleId"] == "ledger")
        ledger["publicEvents"][0]["payloadSchemaDigest"] = "sha256:" + "0" * 64
        errors = CHECK.payload_schema_errors(document["modules"], CHECK.ROOT)
        self.assertTrue(any("does not match the schema" in error for error in errors), errors)

    def test_packaged_resource_drift_is_rejected(self) -> None:
        document = EXPORT.load_registry()
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            resource = root / "packages/core/resources/modules.json"
            resource.parent.mkdir(parents=True)
            drifted = copy.deepcopy(document)
            drifted["coreApiVersion"] = "9.9.9"
            resource.write_text(json.dumps(drifted), encoding="utf-8")
            errors = CHECK.repository_errors(document, root)
        self.assertTrue(any("differs from spec/modules.json" in error for error in errors), errors)

    def test_migration_tables_ignore_comments_and_foreign_keys(self) -> None:
        source = "<?php // Schema::create('billium_comment', fn() => 1);\nSchema::create('billium_real', fn() => 1);"
        self.assertEqual(["billium_real"], CHECK.migration_tables(source))

    def test_undeclared_migration_table_is_rejected(self) -> None:
        document = EXPORT.load_registry()
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            stream = root / "packages/core/database/migrations/platform"
            stream.mkdir(parents=True)
            (stream / "2026_10_30_000001_x.php").write_text("<?php Schema::create('billium_ghost', fn() => 1);", encoding="utf-8")
            errors = CHECK.repository_errors(document, root)
        self.assertTrue(any("differ from tables created by its migrations" in error for error in errors), errors)
        self.assertTrue(any("migration files" in error for error in errors), errors)


if __name__ == "__main__":
    unittest.main()
