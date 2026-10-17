"""Self-tests of lint-vectors.py: each refusal on a throwaway copy of the corpus.

Run: python3 -m unittest discover -s scripts/spec/tests -p test_lint_vectors.py -v
The repository corpus is never written; the revision and the repository references are read-only.
"""
from __future__ import annotations

import json
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
SCRIPT = ROOT / "scripts/spec/lint-vectors.py"
REV = ROOT / "spec/revisions/2026-10"
FIXTURES = ROOT / "fixtures"


class CorpusCopy(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = Path(tempfile.mkdtemp(prefix="billium-vectors-"))
        self.addCleanup(shutil.rmtree, self.tmp)
        self.vectors = self.tmp / "vectors"
        self.golden = self.tmp / "golden"
        self.coverage = self.tmp / "coverage.json"
        shutil.copytree(FIXTURES / "vectors", self.vectors)
        shutil.copytree(FIXTURES / "golden", self.golden)
        shutil.copy(FIXTURES / "coverage.json", self.coverage)

    def lint(self) -> subprocess.CompletedProcess[str]:
        return subprocess.run(
            [sys.executable, str(SCRIPT), "--rev", str(REV), "--vectors", str(self.vectors), "--golden", str(self.golden),
             "--coverage", str(self.coverage), "--repo-root", str(ROOT)],
            capture_output=True,
            text=True,
            check=False,
        )

    def vector_path(self, vector_id: str) -> Path:
        return self.vectors / (vector_id + ".json")

    def edit_vector(self, vector_id: str, change) -> None:
        path = self.vector_path(vector_id)
        document = json.loads(path.read_text(encoding="utf-8"))
        change(document)
        path.write_text(json.dumps(document, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    def edit_coverage(self, change) -> None:
        document = json.loads(self.coverage.read_text(encoding="utf-8"))
        change(document)
        self.coverage.write_text(json.dumps(document, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    def assert_red(self, needle: str) -> None:
        result = self.lint()
        self.assertEqual(result.returncode, 1, result.stdout + result.stderr)
        self.assertIn(needle, result.stderr)


class LintVectorsTest(CorpusCopy):
    def test_repository_corpus_is_clean(self) -> None:
        result = self.lint()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("vectors OK", result.stdout)

    def test_invalid_vector(self) -> None:
        self.edit_vector("money/scale/parse-rub-1490-00", lambda d: d.pop("profiles"))
        self.assert_red("money/scale/parse-rub-1490-00: vector violates vector.json")

    def test_id_differs_from_path(self) -> None:
        self.edit_vector("money/scale/parse-rub-1490-00", lambda d: d.update(id="money/scale/other"))
        self.assert_red("differs from path")

    def test_expect_length_differs_from_script(self) -> None:
        self.edit_vector("money/scale/parse-rub-1490-00", lambda d: d["expect"].append(d["expect"][0]))
        self.assert_red("expect length differs from script length")

    def test_unresolvable_spec_ref(self) -> None:
        for ref in ("BC-ZZZ-99", "REQ-ZZZ-99", "D9999"):
            with self.subTest(ref=ref):
                self.edit_vector("money/scale/parse-rub-1490-00", lambda d, ref=ref: d["spec_refs"].append(ref))
                self.assert_red(f"spec_ref {ref} does not resolve")
                self.edit_vector("money/scale/parse-rub-1490-00", lambda d, ref=ref: d["spec_refs"].remove(ref))

    def test_uncovered_rule(self) -> None:
        self.edit_coverage(lambda d: d["not_testable"].pop(0))
        self.assert_red("is in no vector spec_refs and not in coverage.json")

    def test_not_testable_rule_that_is_covered(self) -> None:
        self.edit_coverage(lambda d: d["not_testable"].append({"rule": "BC-MW-01", "reason": "x"}))
        self.assert_red("not_testable BC-MW-01: covered by a vector")

    def test_not_testable_without_reason_or_with_unknown_rule(self) -> None:
        self.edit_coverage(lambda d: d["not_testable"][0].update(reason=" "))
        self.assert_red("reason is missing")
        self.edit_coverage(lambda d: d["not_testable"].append({"rule": "BC-ZZZ-99", "reason": "x"}))
        self.assert_red("unknown rule")

    def test_missing_set(self) -> None:
        shutil.rmtree(self.vectors / "usage/fact")
        self.assert_red("usage/fact: set has no vectors")

    def test_closed_schema_without_unknown_member_vector(self) -> None:
        # order-fields-before-amounts also carries an unknown member and counts as a rejecting vector
        self.vector_path("payment/execute/execute-unknown-field").unlink()
        self.vector_path("payment/execute/order-fields-before-amounts").unlink()
        self.assert_red("payment.execute.v1: no vector rejects an unknown member")

    def test_unknown_member_vector_that_fails_for_another_reason(self) -> None:
        def break_vector(document: dict) -> None:
            text = json.loads(document["script"][0]["args"]["json_text"])
            text.pop("operationRef")
            document["script"][0]["args"]["json_text"] = json.dumps(text)

        self.edit_vector("payment/execute/execute-unknown-field", break_vector)
        self.edit_vector("payment/execute/order-fields-before-amounts", break_vector)
        self.assert_red("payment.execute.v1: no vector rejects an unknown member")

    def test_literal_digest_differs_from_oracle(self) -> None:
        self.edit_vector("canon/digest/execute-basic-literal", lambda d: d["expect"][1]["ok"].update(digest="sha256:" + "0" * 64))
        self.assert_red("canon/digest/execute-basic-literal: literal digest differs from the oracle output")

    def test_decode_digest_differs_from_oracle(self) -> None:
        self.edit_vector("payment/execute/execute-valid", lambda d: d["expect"][0]["ok"].update(digest="sha256:" + "0" * 64))
        self.assert_red("payment/execute/execute-valid: literal digest differs from the oracle output")

    def test_control_golden_equals_oracle(self) -> None:
        shutil.copy(self.golden / "canon/digest/execute-basic-digest.txt", self.golden / "canon/digest/control-wrong-golden.txt")
        self.assert_red("control golden canon/digest/control-wrong-golden.txt equals the oracle output")

    def test_no_control_vector(self) -> None:
        self.edit_vector("canon/digest/control-wrong-golden", lambda d: d.pop("control"))
        self.assert_red("no control vector")

    def test_missing_golden(self) -> None:
        (self.golden / "canon/digest/nested-digest.txt").unlink()
        self.assert_red("canon/digest/nested-digest.txt does not exist")

    def test_unreferenced_golden(self) -> None:
        (self.golden / "canon/digest/orphan.txt").write_text("x\n", encoding="utf-8")
        self.assert_red("canon/digest/orphan.txt is not referenced")


if __name__ == "__main__":
    unittest.main()
