"""Self-tests of scripts/spec/export-php.py: determinism, --check on drift, the shape of the generated types.

Run: python3 -m unittest discover -s scripts/spec/tests -p test_export_php.py -v
Each test exports into a temporary package directory from a temporary copy of the revision.
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
SCRIPT = ROOT / "scripts/spec/export-php.py"
REV = ROOT / "spec/revisions/2026-10"


class ExportPhpTest(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = Path(tempfile.mkdtemp(prefix="billium-export-php-"))
        self.addCleanup(shutil.rmtree, self.tmp)
        self.rev = self.tmp / REV.name
        shutil.copytree(REV, self.rev)
        self.out = self.tmp / "contracts"

    def export(self, *args: str | Path) -> subprocess.CompletedProcess[str]:
        return subprocess.run(
            [sys.executable, str(SCRIPT), "--revision", str(self.rev), "--out", str(self.out), *map(str, args)],
            capture_output=True,
            text=True,
            check=False,
        )

    def snapshot(self) -> dict[str, bytes]:
        return {path.relative_to(self.out).as_posix(): path.read_bytes() for path in sorted(self.out.rglob("*")) if path.is_file()}

    def test_committed_package_is_fresh(self) -> None:
        result = subprocess.run([sys.executable, str(SCRIPT), "--check"], capture_output=True, text=True, check=False)
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_two_exports_are_identical_and_check_passes(self) -> None:
        self.assertEqual(self.export().returncode, 0)
        first = self.snapshot()
        self.assertEqual(self.export().returncode, 0)
        self.assertEqual(self.snapshot(), first)
        self.assertEqual(self.export("--check").returncode, 0)

    def test_generated_files(self) -> None:
        self.export()
        files = self.snapshot()
        self.assertIn("resources/currencies.php", files)
        self.assertIn("src/Enums/ProblemCode.php", files)
        envelopes = [name for name in files if name.startswith("src/Data/Envelopes/")]
        self.assertEqual(len(envelopes), 54)
        self.assertIn("src/Data/Envelopes/MetricVersion.php", envelopes)
        self.assertIn("src/Data/Envelopes/RatedBasis.php", envelopes)
        registry = json.loads(files["resources/spec/envelopes.json"])
        self.assertEqual(
            [entry["class"] for entry in registry["payment.find.v1"]["variants"]],
            ["FindResult", "FindOperation"],
            "the variant that needs `status` comes first",
        )
        self.assertTrue(registry["payment.execute.v1"]["variants"][0]["verifyDigest"])
        self.assertFalse(registry["payment.outcome.v1"]["variants"][0]["verifyDigest"])
        self.assertEqual(registry["payment.observation.v1"]["variants"][0]["digestMember"], "contentDigest")
        execute = files["src/Data/Envelopes/ExecutePayment.php"].decode()
        self.assertIn("public Money $money,", execute)
        self.assertIn("public ?string $returnContextRef = null,", execute)
        self.assertNotIn("float", "\n".join(text.decode() for name, text in files.items() if name.endswith(".php")))

    def assert_stale(self, needle: str) -> None:
        result = self.export("--check")
        self.assertEqual(result.returncode, 1, result.stderr)
        self.assertIn("stale", result.stderr)
        self.assertIn(needle, result.stderr)

    def test_check_detects_an_edited_generated_file(self) -> None:
        self.export()
        target = self.out / "src/Enums/ProblemCode.php"
        target.write_text(target.read_text(encoding="utf-8") + "// hand edit\n", encoding="utf-8")
        before = self.snapshot()
        self.assert_stale("ProblemCode.php")
        self.assertEqual(self.snapshot(), before, "--check wrote a file")

    def test_check_detects_a_missing_and_a_leftover_file(self) -> None:
        self.export()
        (self.out / "resources/currencies.php").unlink()
        (self.out / "src/Data/Envelopes/Retired.php").write_text("<?php\n", encoding="utf-8")
        result = self.export("--check")
        self.assertEqual(result.returncode, 1)
        self.assertIn("currencies.php", result.stderr)
        self.assertIn("Retired.php", result.stderr)
        self.assertEqual(self.export().returncode, 0)
        self.assertFalse((self.out / "src/Data/Envelopes/Retired.php").exists(), "a leftover generated file is removed")

    def test_check_detects_a_changed_spec(self) -> None:
        self.export()
        path = self.rev / "currencies.json"
        document = json.loads(path.read_text(encoding="utf-8"))
        document["currencies"][0]["scale"] += 1
        path.write_text(json.dumps(document, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        self.assert_stale("currencies.php")

    def test_unmappable_member_is_refused(self) -> None:
        path = self.rev / "schema/payment/cancel.v1.json"
        document = json.loads(path.read_text(encoding="utf-8"))
        document["properties"]["operationRef"] = {"type": "number"}
        path.write_text(json.dumps(document, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        result = self.export()
        self.assertEqual(result.returncode, 1)
        self.assertIn("cannot map schema", result.stderr)


if __name__ == "__main__":
    unittest.main()
