"""Self-tests of the conformance runner on a fake adapter.

Run: python3 -m unittest discover -s conformance/tests -v
Each test builds a throwaway repository tree (spec revision copy, manifests, vectors, adapters.json); the
real repository is never written.
"""
from __future__ import annotations

import hashlib
import json
import os
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[1]
RUNNER = ROOT / "conformance/runner/run.py"
FAKE = HERE / "fake_adapter.py"
REV = ROOT / "spec/revisions/2026-10"

sys.path.insert(0, str(ROOT / "conformance/runner"))
from vectors import compare, same  # noqa: E402


def vector(vector_id: str, *, mode: str = "echo", steps: list | None = None, expect: list | None = None, **extra) -> dict:
    """A vector whose fake-adapter answer is `result` (ok) or `error_code` of each step."""
    steps = steps if steps is not None else [{"op": "canon.canonicalize", "args": {"json_text": "{}", "result": {"text": "{}"}}}]
    return {
        "id": vector_id,
        "spec_refs": ["BC-CNF-01"],
        "profiles": ["php"],
        "given": {"mode": mode},
        "script": steps,
        "expect": expect if expect is not None else [{"ok": {"text": "{}"}}],
        **extra,
    }


CONTROL = vector(
    "canon/digest/control",
    expect=[{"ok": {"text": "{\"wrong\":1}"}}],
    control="must-fail",
)


class Tree:
    def __init__(self, test: unittest.TestCase) -> None:
        self.root = Path(tempfile.mkdtemp(prefix="billium-conformance-"))
        test.addCleanup(shutil.rmtree, self.root)
        rev = self.root / "spec/revisions/2026-10"
        shutil.copytree(REV / "schema", rev / "schema")
        shutil.copy(REV / "revision.lock.json", rev / "revision.lock.json")
        self.write("packages/contracts/composer.json", {"extra": {"billium": {"spec_rev": "2026-10"}}})
        (self.root / "packages/contracts-dart").mkdir(parents=True)
        (self.root / "packages/contracts-dart/pubspec.yaml").write_text(
            'name: billium_contracts\nbillium:\n  spec_rev: "2026-10"\ndev_dependencies:\n  test: ^1.25.0\n',
            encoding="utf-8",
        )
        self.write("conformance/adapters.json", {"php": {"cmd": [sys.executable, str(FAKE)]}})
        self.env = {**os.environ}

    def write(self, rel: str, document) -> None:
        path = self.root / rel
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(json.dumps(document, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    def vector(self, document: dict) -> None:
        self.write(f"fixtures/vectors/{document['id']}.json", document)

    def golden(self, rel: str, data: bytes) -> None:
        path = self.root / "fixtures/golden" / rel
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(data)

    def baseline(self, *ids: str, phase: str = "P5") -> None:
        entries = [
            {"id": i, "reason": "later phase", "owner": "team", "since": "2026-10-17", "issue": "I-1", "phase": phase}
            for i in ids
        ]
        self.write("conformance/baseline/php.json", {"entries": entries})

    def run(self, *args: str) -> subprocess.CompletedProcess[str]:
        return subprocess.run(
            [sys.executable, str(RUNNER), "--root", str(self.root), *args],
            capture_output=True,
            text=True,
            env=self.env,
            check=False,
        )

    def report(self) -> dict:
        return json.loads((self.root / "conformance/reports/php.json").read_text(encoding="utf-8"))

    def status(self, vector_id: str) -> str:
        return next(entry["status"] for entry in self.report()["results"] if entry["id"] == vector_id)


class RunnerTest(unittest.TestCase):
    def setUp(self) -> None:
        self.tree = Tree(self)
        self.tree.vector(CONTROL)

    def run_php(self, *extra: str) -> subprocess.CompletedProcess[str]:
        return self.tree.run("--adapter", "php", "--timeout", "5", *extra)

    def assert_red(self, result: subprocess.CompletedProcess[str], needle: str) -> None:
        self.assertEqual(result.returncode, 1, result.stdout + result.stderr)
        self.assertIn(needle, result.stderr)

    def test_pass_and_failing_control_is_green(self) -> None:
        self.tree.vector(vector("money/scale/ok"))
        result = self.run_php()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("GREEN", result.stdout)
        report = self.tree.report()
        self.assertEqual(report["evidence_class"], "contracts-conformance")
        self.assertEqual(report["counts"], {"pass": 1, "fail": 1, "error": 0})
        self.assertEqual(self.tree.status("canon/digest/control"), "fail")

    def test_report_statuses_are_closed(self) -> None:
        for name, mode in (("a", "echo"), ("b", "crash"), ("c", "short")):
            self.tree.vector(vector(f"money/scale/{name}", mode=mode))
        self.run_php()
        statuses = {entry["status"] for entry in self.tree.report()["results"]}
        self.assertLessEqual(statuses, {"pass", "fail", "error"})

    def test_fail_on_mismatch_is_red(self) -> None:
        self.tree.vector(vector("money/scale/mismatch", expect=[{"ok": {"text": "[]"}}]))
        result = self.run_php()
        self.assert_red(result, "not pass and not in baseline: money/scale/mismatch")
        self.assertEqual(self.tree.status("money/scale/mismatch"), "fail")

    def test_error_expectation_matches_code(self) -> None:
        step = {"op": "money.parse", "args": {"wire_text": "{}", "error_code": "billium.money.scale_invalid"}}
        self.tree.vector(vector("money/scale/rejected", steps=[step], expect=[{"error": {"code": "billium.money.scale_invalid"}}]))
        self.tree.vector(
            vector("money/scale/wrong-code", steps=[step], expect=[{"error": {"code": "billium.validation.invalid"}}])
        )
        self.tree.baseline("money/scale/wrong-code")
        result = self.run_php()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.tree.status("money/scale/rejected"), "pass")
        self.assertEqual(self.tree.status("money/scale/wrong-code"), "fail")

    def test_crash_is_error_and_adapter_restarts(self) -> None:
        self.tree.vector(vector("money/scale/boom", mode="crash"))
        self.tree.vector(vector("money/scale/ok"))
        result = self.run_php()
        self.assert_red(result, "money/scale/boom")
        self.assertEqual(self.tree.status("money/scale/boom"), "error")
        self.assertEqual(self.tree.status("money/scale/ok"), "pass")

    def test_timeout_is_error(self) -> None:
        self.tree.vector(vector("money/scale/slow", mode="hang"))
        result = self.tree.run("--adapter", "php", "--timeout", "1")
        self.assert_red(result, "money/scale/slow")
        report = self.tree.report()
        entry = next(e for e in report["results"] if e["id"] == "money/scale/slow")
        self.assertEqual(entry["status"], "error")
        self.assertIn("timeout", entry["diff"])

    def test_garbage_and_invalid_messages_are_errors(self) -> None:
        self.tree.vector(vector("money/scale/garbage", mode="garbage"))
        self.tree.vector(vector("money/scale/invalid", mode="invalid"))
        self.tree.vector(vector("money/scale/wrong-id", mode="wrong_id"))
        self.run_php()
        for name in ("garbage", "invalid", "wrong-id"):
            self.assertEqual(self.tree.status(f"money/scale/{name}"), "error", name)

    def test_wrong_outputs_length_is_fail(self) -> None:
        self.tree.vector(vector("money/scale/short", mode="short"))
        self.run_php()
        self.assertEqual(self.tree.status("money/scale/short"), "fail")

    def test_unknown_op_is_fail_and_not_sent(self) -> None:
        self.tree.env["FAKE_OPS"] = "canon.canonicalize"
        step = {"op": "money.parse", "args": {"wire_text": "{}", "result": {}}}
        self.tree.vector(vector("money/scale/unknown-op", steps=[step], expect=[{"ok": {}}]))
        self.run_php()
        self.assertEqual(self.tree.status("money/scale/unknown-op"), "fail")
        entry = next(e for e in self.tree.report()["results"] if e["id"] == "money/scale/unknown-op")
        self.assertIn("unknown op: money.parse", entry["diff"])

    def test_expect_is_never_sent_to_the_adapter(self) -> None:
        # the fake adapter answers with an error as soon as a run message carries `expect`
        self.tree.vector(vector("money/scale/ok"))
        self.run_php()
        self.assertEqual(self.tree.status("money/scale/ok"), "pass")

    def test_vector_outside_profile_is_not_sent(self) -> None:
        self.tree.vector(vector("payment/execute/dart-only", profiles=["dart"]))
        self.tree.vector(vector("money/scale/ok"))
        self.run_php()
        ids = {entry["id"] for entry in self.tree.report()["results"]}
        self.assertNotIn("payment/execute/dart-only", ids)

    def test_golden_resolution(self) -> None:
        self.tree.golden("canon/digest/same.txt", b"{}\n")
        self.tree.vector(vector("canon/digest/uses-golden", expect=[{"ok": {"text": {"golden": "canon/digest/same.txt"}}}]))
        result = self.run_php()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.tree.status("canon/digest/uses-golden"), "pass")

    def test_json_golden_is_compared_as_json(self) -> None:
        self.tree.golden("errors/registry.json", b'[\n  {"code": "a", "retryable": false}\n]\n')
        step = {"op": "codes.registry", "args": {"result": {"codes": [{"retryable": False, "code": "a"}]}}}
        wrong = {"op": "codes.registry", "args": {"result": {"codes": [{"retryable": 0, "code": "a"}]}}}
        expect = [{"ok": {"codes": {"golden": "errors/registry.json"}}}]
        self.tree.vector(vector("errors/codes/registry", steps=[step], expect=expect))
        self.tree.vector(vector("errors/codes/registry-drift", steps=[wrong], expect=expect))
        self.tree.baseline("errors/codes/registry-drift")
        result = self.run_php()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.tree.status("errors/codes/registry"), "pass")
        self.assertEqual(self.tree.status("errors/codes/registry-drift"), "fail")

    def test_malformed_golden_is_error(self) -> None:
        for name, data in (("bom", b"\xef\xbb\xbf{}\n"), ("nolf", b"{}"), ("twolf", b"{}\n\n")):
            self.tree.golden(f"canon/digest/{name}.txt", data)
            self.tree.vector(vector(f"canon/digest/{name}", expect=[{"ok": {"text": {"golden": f"canon/digest/{name}.txt"}}}]))
        self.tree.vector(vector("canon/digest/missing", expect=[{"ok": {"text": {"golden": "canon/digest/none.txt"}}}]))
        self.run_php()
        for name in ("bom", "nolf", "twolf", "missing"):
            self.assertEqual(self.tree.status(f"canon/digest/{name}"), "error", name)

    def test_invalid_vector_is_error(self) -> None:
        self.tree.write("fixtures/vectors/money/scale/bad.json", {"id": "money/scale/bad", "profiles": ["php"]})
        self.tree.write("fixtures/vectors/money/scale/moved.json", vector("money/scale/elsewhere"))
        result = self.run_php()
        self.assert_red(result, "money/scale/bad")
        self.assertEqual(self.tree.status("money/scale/bad"), "error")
        self.assertEqual(self.tree.status("money/scale/moved"), "error")

    # -- baseline ---------------------------------------------------------------------------------------

    def test_baseline_covers_exactly_the_non_pass_set(self) -> None:
        self.tree.vector(vector("money/scale/known", mode="crash"))
        self.tree.baseline("money/scale/known")
        result = self.run_php()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("baseline 1", result.stdout)

    def test_stale_baseline_entry_is_red(self) -> None:
        self.tree.vector(vector("money/scale/ok"))
        self.tree.baseline("money/scale/ok")
        self.assert_red(self.run_php(), "stale baseline entry (vector passed or disappeared): money/scale/ok")

    def test_baseline_entry_of_vanished_vector_is_red(self) -> None:
        self.tree.vector(vector("money/scale/ok"))
        self.tree.baseline("money/scale/gone")
        self.assert_red(self.run_php(), "stale baseline entry (vector passed or disappeared): money/scale/gone")

    def test_baseline_entry_needs_all_fields(self) -> None:
        self.tree.vector(vector("money/scale/known", mode="crash"))
        self.tree.write("conformance/baseline/php.json", {"entries": [{"id": "money/scale/known", "reason": "x"}]})
        self.assert_red(self.run_php(), "field 'owner' is missing or empty")

    def test_baseline_phase_must_be_later_than_current(self) -> None:
        self.tree.vector(vector("money/scale/known", mode="crash"))
        self.tree.baseline("money/scale/known", phase="P2")
        self.assertEqual(self.run_php().returncode, 0)
        self.assert_red(self.run_php("--phase", "P2"), "is not after the current P2")

    # -- control vectors --------------------------------------------------------------------------------

    def test_passing_control_is_red(self) -> None:
        self.tree.vector(vector("canon/digest/control", control="must-fail"))
        self.tree.vector(vector("money/scale/ok"))
        self.assert_red(self.run_php(), "control vector passed (must fail): canon/digest/control")

    def test_crashing_control_is_red(self) -> None:
        self.tree.vector({**CONTROL, "given": {"mode": "crash"}})
        self.tree.vector(vector("money/scale/ok"))
        self.assert_red(self.run_php(), "not pass and not in baseline: canon/digest/control")

    def test_run_without_control_vector_is_red(self) -> None:
        (self.tree.root / "fixtures/vectors/canon/digest/control.json").unlink()
        self.tree.vector(vector("money/scale/ok"))
        self.assert_red(self.run_php(), "no control vector")

    # -- lockstep ---------------------------------------------------------------------------------------

    def test_lockstep_mismatch_with_php_manifest(self) -> None:
        self.tree.vector(vector("money/scale/ok"))
        self.tree.write("packages/contracts/composer.json", {"extra": {"billium": {"spec_rev": "2026-11"}}})
        self.assert_red(self.run_php(), "lockstep mismatch")

    def test_lockstep_mismatch_with_dart_manifest(self) -> None:
        self.tree.vector(vector("money/scale/ok"))
        pubspec = self.tree.root / "packages/contracts-dart/pubspec.yaml"
        pubspec.write_text(pubspec.read_text(encoding="utf-8").replace("2026-10", "2026-09"), encoding="utf-8")
        self.assert_red(self.run_php(), "packages/contracts-dart/pubspec.yaml=2026-09")

    def test_lockstep_mismatch_with_adapter_ready(self) -> None:
        self.tree.vector(vector("money/scale/ok"))
        self.tree.env["FAKE_SPEC_REV"] = "2026-11"
        self.assert_red(self.run_php(), "adapter ready.spec_rev=2026-11")

    def test_missing_manifest_is_red(self) -> None:
        self.tree.vector(vector("money/scale/ok"))
        (self.tree.root / "packages/contracts/composer.json").unlink()
        self.assert_red(self.run_php(), "cannot read spec_rev of packages/contracts/composer.json")

    # -- adapter life cycle -----------------------------------------------------------------------------

    def test_no_vectors_for_profile_is_red(self) -> None:
        (self.tree.root / "fixtures/vectors/canon/digest/control.json").unlink()
        self.assert_red(self.run_php(), "has no subject")

    def test_undeclared_adapter_is_red(self) -> None:
        result = self.tree.run("--adapter", "dart")
        self.assertEqual(result.returncode, 1)
        self.assertIn("not declared in adapters.json", result.stderr)

    def test_adapter_that_cannot_start_is_red(self) -> None:
        self.tree.write("conformance/adapters.json", {"php": {"cmd": ["/nonexistent/adapter"]}})
        self.tree.vector(vector("money/scale/ok"))
        result = self.run_php()
        self.assert_red(result, "adapter did not start")
        self.assertEqual({e["status"] for e in self.tree.report()["results"]}, {"error"})

    def test_adapter_crashing_before_ready_is_red(self) -> None:
        self.tree.env["FAKE_START"] = "crash"
        self.tree.vector(vector("money/scale/ok"))
        self.assert_red(self.run_php(), "money/scale/ok")

    def test_adapter_exit_code_after_stop_is_checked(self) -> None:
        self.tree.vector(vector("money/scale/ok"))
        self.tree.env["FAKE_EXIT_CODE"] = "4"
        self.assert_red(self.run_php(), "adapter exit code 4 after stop")


class GoldenCheckTest(unittest.TestCase):
    """`--check-golden`: the real oracle must reproduce every golden under canon/ byte for byte."""

    def setUp(self) -> None:
        self.tree = Tree(self)
        shutil.copytree(ROOT / "conformance/oracles/jcs", self.tree.root / "conformance/oracles/jcs")
        self.source = '{"b":[1,2],"a":"é","\U0001f600":1,"￠":2}'

    def canonical(self) -> bytes:
        # UTF-16 code unit order: U+1F600 (surrogates D83D DE00) sorts before U+FFE0
        return '{"a":"\u00e9","b":[1,2],"\U0001f600":1,"\uffe0":2}'.encode()

    def digest(self) -> bytes:
        return ("sha256:" + hashlib.sha256(self.canonical()).hexdigest()).encode()

    def add(self, name: str, op: str, field: str, golden: bytes, **extra) -> None:
        rel = f"canon/digest/{name}.txt"
        self.tree.golden(rel, golden)
        step = {"op": op, "args": {"json_text": self.source}}
        self.tree.vector(vector(f"canon/digest/{name}", steps=[step], expect=[{"ok": {field: {"golden": rel}}}], **extra))

    def check(self) -> subprocess.CompletedProcess[str]:
        return self.tree.run("--check-golden")

    def test_oracle_reproduces_golden(self) -> None:
        self.add("text", "canon.canonicalize", "text", self.canonical() + b"\n")
        self.add("digest", "canon.digest", "digest", self.digest() + b"\n")
        result = self.check()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("2 checked by the oracle", result.stdout)

    def test_digest_excludes_the_digest_field(self) -> None:
        self.source = '{"a":1,"digest":"sha256:00"}'
        golden = ("sha256:" + hashlib.sha256(b'{"a":1}').hexdigest()).encode() + b"\n"
        self.add("excluded", "canon.digest", "digest", golden)
        result = self.check()
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_wrong_golden_is_red(self) -> None:
        self.add("text", "canon.canonicalize", "text", b'{"a":"x"}\n')
        result = self.check()
        self.assertEqual(result.returncode, 1)
        self.assertIn("differs from the oracle output", result.stderr)

    def test_control_vector_golden_is_not_checked(self) -> None:
        self.add("text", "canon.canonicalize", "text", self.canonical() + b"\n")
        self.add("control-wrong", "canon.canonicalize", "text", b"wrong\n", control="must-fail")
        result = self.check()
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_orphan_golden_is_red(self) -> None:
        self.add("text", "canon.canonicalize", "text", self.canonical() + b"\n")
        self.tree.golden("canon/digest/orphan.txt", b"x\n")
        result = self.check()
        self.assertEqual(result.returncode, 1)
        self.assertIn("canon/digest/orphan.txt is not referenced", result.stderr)

    def test_no_golden_is_red(self) -> None:
        result = self.check()
        self.assertEqual(result.returncode, 1)
        self.assertIn("has no subject", result.stderr)


class CompareTest(unittest.TestCase):
    def test_ok_is_partial_at_top_level_only(self) -> None:
        self.assertIsNone(compare([{"ok": {"a": 1}}], [{"ok": {"a": 1, "b": 2}}]))
        self.assertIn("ok.a", compare([{"ok": {"a": {"x": 1}}}], [{"ok": {"a": {"x": 1, "y": 2}}}]) or "")

    def test_arrays_compare_whole(self) -> None:
        self.assertIsNone(compare([{"ok": {"a": [1, 2]}}], [{"ok": {"a": [1, 2]}}]))
        self.assertIsNotNone(compare([{"ok": {"a": [1]}}], [{"ok": {"a": [1, 2]}}]))

    def test_types_are_strict(self) -> None:
        self.assertFalse(same(1, True))
        self.assertFalse(same(1, 1.0))
        self.assertFalse(same("1", 1))
        self.assertTrue(same({"a": [1, "x"]}, {"a": [1, "x"]}))

    def test_error_compares_code_only(self) -> None:
        want = [{"error": {"code": "billium.validation.invalid", "detail": "x"}}]
        self.assertIsNone(compare(want, [{"error": {"code": "billium.validation.invalid", "detail": "y"}}]))
        self.assertIsNotNone(compare(want, [{"ok": {}}]))

    def test_length_mismatch(self) -> None:
        self.assertIn("length", compare([{"ok": {}}], []) or "")


if __name__ == "__main__":
    unittest.main()
