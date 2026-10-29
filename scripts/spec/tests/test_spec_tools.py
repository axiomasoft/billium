"""Self-tests of the spec generators and lint: determinism, --check on drift, lint refusals.

Run: python3 -m unittest discover -s scripts/spec/tests -p test_spec_tools.py -v
Each test works on a temporary copy of the revision; the repository is never written.
"""
from __future__ import annotations

import json
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import lint as spec_lint

ROOT = Path(__file__).resolve().parents[3]
SPEC = ROOT / "scripts/spec"
REV = ROOT / "spec/revisions/2026-10"


def run(script: str, *args: str | Path) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        [sys.executable, str(SPEC / script), *map(str, args)],
        capture_output=True,
        text=True,
        check=False,
    )


class RevisionCopy(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = Path(tempfile.mkdtemp(prefix="billium-spec-"))
        self.addCleanup(shutil.rmtree, self.tmp)
        self.rev = self.tmp / REV.name
        shutil.copytree(REV, self.rev)

    def edit_json(self, rel: str, change) -> None:
        path = self.rev / rel
        document = json.loads(path.read_text(encoding="utf-8"))
        change(document)
        path.write_text(json.dumps(document, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    def lint(self) -> subprocess.CompletedProcess[str]:
        return run("lint.py", self.rev, "--repo-root", ROOT)


class GeneratorTest(RevisionCopy):
    def assert_deterministic(self, script: str, out: Path, *args: str | Path) -> None:
        self.assertEqual(run(script, *args).returncode, 0)
        first = out.read_bytes()
        self.assertEqual(run(script, *args).returncode, 0)
        self.assertEqual(out.read_bytes(), first, f"{script}: two writes differ")
        self.assertEqual(run(script, *args, "--check").returncode, 0)

    def assert_check_detects_drift(self, script: str, out: Path, *args: str | Path) -> None:
        before = out.read_bytes()
        result = run(script, *args, "--check")
        self.assertEqual(result.returncode, 1, result.stderr)
        self.assertIn("stale", result.stderr)
        self.assertEqual(out.read_bytes(), before, f"{script} --check wrote a file")

    def test_committed_derived_files_are_fresh(self) -> None:
        self.assertEqual(run("build-rules.py", "--rev", REV, "--check").returncode, 0)
        self.assertEqual(run("build-lock.py", "--rev", REV, "--check").returncode, 0)
        self.assertEqual(run("build-codes-index.py", "--check").returncode, 0)
        self.assertEqual(run("build-currencies.py", "--check").returncode, 0)

    def test_rules(self) -> None:
        out = self.rev / "rules.json"
        self.assert_deterministic("build-rules.py", out, "--rev", self.rev)
        doc = self.rev / "01-money-wire.md"
        doc.write_text(doc.read_text(encoding="utf-8").replace("ровно scale знаков", "scale знаков"), encoding="utf-8")
        self.assert_check_detects_drift("build-rules.py", out, "--rev", self.rev)

    def test_rules_refuse_rule_without_source(self) -> None:
        doc = self.rev / "04-errors.md"
        doc.write_text(doc.read_text(encoding="utf-8") + "\n[BC-ERR-99] Правило без источника.\n", encoding="utf-8")
        result = run("build-rules.py", "--rev", self.rev, "--check")
        self.assertEqual(result.returncode, 1)
        self.assertIn("no source reference", result.stderr)

    def test_lock(self) -> None:
        out = self.rev / "revision.lock.json"
        self.assert_deterministic("build-lock.py", out, "--rev", self.rev)
        lock = json.loads(out.read_text(encoding="utf-8"))
        self.assertEqual((lock["revision"], lock["status"]), ("2026-10", "draft"))
        self.assertNotIn("revision.lock.json", lock["files"])
        self.edit_json("schema/payment/execute.v1.json", lambda d: d.update(title="ExecutePayment*"))
        self.assert_check_detects_drift("build-lock.py", out, "--rev", self.rev)

    def test_codes_index(self) -> None:
        codes = self.rev / "problem-codes.json"
        out = self.tmp / "problem-codes.md"
        self.assert_deterministic("build-codes-index.py", out, "--codes", codes, "--out", out)
        self.edit_json("problem-codes.json", lambda d: d[0].update(clientAction="иное действие"))
        self.assert_check_detects_drift("build-codes-index.py", out, "--codes", codes, "--out", out)

    def test_currencies(self) -> None:
        xml = self.rev / "sources/list-one.xml"
        out = self.rev / "currencies.json"
        self.assert_deterministic("build-currencies.py", out, "--xml", xml)
        scales = {row["code"]: row["scale"] for row in json.loads(out.read_text(encoding="utf-8"))["currencies"]}
        self.assertEqual((scales["RUB"], scales["JPY"], scales["KWD"], scales["USD"]), (2, 0, 3, 2))
        self.assertNotIn("XAU", scales, "N.A. entries are excluded")
        xml.write_bytes(xml.read_bytes().replace(b"<Ccy>JPY</Ccy>", b"<Ccy>JPX</Ccy>", 1))
        self.assert_check_detects_drift("build-currencies.py", out, "--xml", xml)


class LintTest(RevisionCopy):
    def assert_refused(self, needle: str) -> None:
        result = self.lint()
        self.assertEqual(result.returncode, 1, result.stdout)
        self.assertIn(needle, result.stderr)

    def test_revision_passes(self) -> None:
        result = self.lint()
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_normative_word_in_description(self) -> None:
        self.edit_json(
            "schema/common/defs.json",
            lambda d: d["$defs"]["ref"].update(description="Идентификатор MUST быть непустым."),
        )
        self.assert_refused("normative word 'MUST'")

    def test_normative_russian_word_in_description(self) -> None:
        self.edit_json(
            "schema/common/money-wire.json",
            lambda d: d.update(description="Сумма должна быть строкой."),
        )
        self.assert_refused("normative word 'должна'")

    def test_unresolvable_ref(self) -> None:
        self.edit_json(
            "schema/payment/execute.v1.json",
            lambda d: d["properties"]["operationRef"].update({"$ref": "urn:billium:spec:2026-10:common/defs.json#/$defs/missing"}),
        )
        self.assert_refused("unresolvable $ref")

    def test_number_amount(self) -> None:
        self.edit_json(
            "schema/common/money-wire.json",
            lambda d: d["properties"].update(amount={"type": "number"}),
        )
        self.assert_refused("convention 6")

    def test_open_incoming_command(self) -> None:
        self.edit_json("schema/payment/refund.v1.json", lambda d: d.pop("additionalProperties"))
        self.assert_refused("payment.refund.v1 needs additionalProperties: false")

    def test_repeated_enumeration(self) -> None:
        self.edit_json(
            "schema/payment/outcome.v1.json",
            lambda d: d["properties"].update(status={"enum": ["accepted-unknown", "pending", "succeeded", "failed-terminal", "cancelled"]}),
        )
        self.assert_refused("convention 2")

    def test_invalid_example(self) -> None:
        doc = self.rev / "03-envelopes.md"
        doc.write_text(doc.read_text(encoding="utf-8").replace('"result": "accepted"', '"result": "maybe"'), encoding="utf-8")
        self.assert_refused("does not validate")

    def test_rule_without_resolvable_source(self) -> None:
        doc = self.rev / "05-events-receipts.md"
        doc.write_text(doc.read_text(encoding="utf-8") + "\n[BC-EVT-99] Правило с выдуманным источником. (REQ-CON-99)\n", encoding="utf-8")
        self.assert_refused("reference REQ-CON-99 does not resolve")

    def test_receipt_policy_of_validation(self) -> None:
        self.edit_json("problem-codes.json", lambda d: d[0].update(receipt="stored"))
        self.assert_refused("keeps no receipt")


class SchemaEnvelopeConventionsTest(unittest.TestCase):
    """Convention 7 against the six acceptance findings, without unrelated rule/plan checks."""

    def check(self, documents: dict, envelopes: dict) -> list[str]:
        checker = spec_lint.Lint(REV, ROOT)
        checker.schemas = documents
        with patch.object(spec_lint, "CLOSED", set()), patch.object(spec_lint, "OPEN", set()):
            checker.check_envelopes(envelopes)
        return checker.errors

    def test_adopted_namespaces_and_feed_definitions_pass(self) -> None:
        envelopes = {
            "billium.event.catalog.v1": "event/catalog.v1.json",
            "billium.feed.bootstrap.v1": "feed/bootstrap.v1.json",
            "billium.feed.page.v1": "feed/page.v1.json",
            "billium.feed.selection.v1": "feed/selection.v1.json",
            "payment.effect.outcome.v1": "payment/effect-outcome.v1.json",
        }
        paths = [*envelopes.values(), "feed/defs.json"]
        documents = {path: json.loads((REV / "schema" / path).read_text()) for path in paths}
        self.assertEqual([], self.check(documents, envelopes))

    def test_malformed_namespaced_names_are_still_rejected(self) -> None:
        for name in ["billium..feed.page.v1", "Billium.feed.page.v1", "billium.feed.page.v0", "page.v1"]:
            with self.subTest(name=name):
                document = {"properties": {"schema": {"const": name}}}
                errors = self.check({"feed/page.v1.json": document}, {name: "feed/page.v1.json"})
                self.assertTrue(any("schema const" in error for error in errors), errors)

    def test_feed_envelope_without_schema_is_still_rejected(self) -> None:
        errors = self.check({"feed/page.v1.json": {"type": "object"}}, {})
        self.assertTrue(any("no properties.schema.const" in error for error in errors), errors)


if __name__ == "__main__":
    unittest.main()
