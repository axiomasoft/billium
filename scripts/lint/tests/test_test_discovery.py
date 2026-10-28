"""Negative proofs for the MECH-02 discovery gate; no domain suite is run."""

import importlib.util
import subprocess
import unittest
from pathlib import Path

SPEC = importlib.util.spec_from_file_location(
    "test_discovery", Path(__file__).resolve().parents[1] / "test-discovery.py"
)
discovery = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(discovery)


class TestDiscoveryTest(unittest.TestCase):
    @staticmethod
    def inventory(xml, exit_code=0):
        def run(command, **kwargs):
            Path(command[-1]).write_text(xml, encoding="utf-8")
            return subprocess.CompletedProcess(command, exit_code, "inventory output")

        return run

    def test_nonempty_inventory_is_required(self):
        count = discovery.discover(
            ["--filter=known"],
            self.inventory(
                '<testSuite xmlns="https://xml.phpunit.de/testSuite"><tests>'
                '<testClass><testMethod name="test_known"/></testClass>'
                '</tests></testSuite>'
            ),
        )
        self.assertEqual(1, count)

    def test_zero_exit_with_empty_inventory_is_still_red(self):
        with self.assertRaisesRegex(RuntimeError, "Empty Pest selection"):
            discovery.discover(["--filter=missing"], self.inventory("<testSuite/>"))

    def test_invalid_inventory_is_red(self):
        with self.assertRaisesRegex(RuntimeError, "valid test inventory"):
            discovery.discover([], self.inventory("not XML"))

    def test_pest_failure_is_red_even_with_nonempty_inventory(self):
        with self.assertRaisesRegex(RuntimeError, "Pest discovery failed"):
            discovery.discover([], self.inventory("<testMethod/>", exit_code=2))

    def test_unrelated_pest_failure_does_not_prove_empty_filter_guard(self):
        def broken(command, **kwargs):
            return subprocess.CompletedProcess(command, 1, "PHP extension missing")

        with self.assertRaisesRegex(RuntimeError, "must fail because no tests"):
            discovery.prove_empty_filter_is_red(broken)

    def test_green_empty_filter_is_rejected(self):
        def green(command, **kwargs):
            return subprocess.CompletedProcess(command, 0, "No tests found")

        with self.assertRaisesRegex(RuntimeError, "must fail because no tests"):
            discovery.prove_empty_filter_is_red(green)

    def test_real_nonexistent_pest_filter_returns_nonzero(self):
        discovery.prove_empty_filter_is_red()

    def test_each_p3_selector_selects_at_least_one_real_test(self):
        for selector in discovery.P3_SELECTORS:
            with self.subTest(selector=selector):
                self.assertGreater(discovery.discover([f"--filter={selector}"]), 0)


if __name__ == "__main__":
    unittest.main()
