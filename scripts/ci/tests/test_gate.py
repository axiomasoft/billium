"""Selector and workflow checks for the shipped gate."""

import fnmatch
import json
import os
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
CI = ROOT / "scripts" / "ci.sh"
ZONES = ROOT / "tests" / "zones.json"
WORKFLOW = ROOT / ".github" / "workflows" / "ci.yml"
LAYERS = ["lint", "derived", "php", "dart", "conformance"]
GIT_ENV = {
    **os.environ,
    "GIT_AUTHOR_NAME": "billium-ci-test",
    "GIT_AUTHOR_EMAIL": "ci-test@billium.local",
    "GIT_COMMITTER_NAME": "billium-ci-test",
    "GIT_COMMITTER_EMAIL": "ci-test@billium.local",
}


def expected_layers(paths: list[str]) -> list[str]:
    zones = json.loads(ZONES.read_text(encoding="utf-8"))["zones"]
    hit: set[str] = set()
    for zone in zones:
        patterns = zone["paths"]
        if any(fnmatch.fnmatch(path, pattern) for path in paths for pattern in patterns):
            hit.update(zone["layers"])
    return [layer for layer in LAYERS if layer in hit]


class GateSelectorTest(unittest.TestCase):
    def test_unknown_layer_exits_nonzero(self) -> None:
        result = subprocess.run(
            ["bash", str(CI), "--layer", "not-a-layer"],
            cwd=ROOT,
            capture_output=True,
            text=True,
            check=False,
        )
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("unknown layer", result.stderr)

    def test_list_prints_the_five_layers_in_order(self) -> None:
        result = subprocess.run(
            ["bash", str(CI), "--list"],
            cwd=ROOT,
            capture_output=True,
            text=True,
            check=True,
        )
        names = [line.split(":", 1)[0] for line in result.stdout.splitlines() if line]
        self.assertEqual(names, LAYERS)

    def test_affected_matches_zones_for_a_dart_path_and_for_the_gate(self) -> None:
        self.assertEqual(
            self.affected_for("packages/contracts-dart/pubspec.yaml", "\n# ci-selector\n"),
            expected_layers(["packages/contracts-dart/pubspec.yaml"]),
        )
        self.assertEqual(
            self.affected_for(".github/workflows/ci.yml", "\n# ci-selector\n"),
            LAYERS,
        )
        self.assertEqual(
            self.affected_for("scripts/ci.sh", "\n# ci-selector\n"),
            LAYERS,
        )

    def test_workflow_runs_one_gate_against_the_checked_out_workspace(self) -> None:
        text = WORKFLOW.read_text(encoding="utf-8")
        self.assertIn("bash scripts/ci.sh", text)
        self.assertNotIn("vendor/bin/pest", text)
        self.assertNotIn("dart test", text)
        self.assertIn("branches: [main]", text)
        self.assertIn("pull_request:", text)
        self.assertNotIn("push:\n  pull_request:", text)
        self.assertIn("SKM_WORKSPACES: ${{ github.workspace }}/workspaces", text)
        self.assertIn("path: workspaces/axioma", text)
        self.assertNotIn(".local/share/swissknifeman", text)

    def affected_for(self, relative: str, suffix: str) -> list[str]:
        with tempfile.TemporaryDirectory(prefix="billium-ci-wt-") as directory:
            worktree = Path(directory) / "wt"
            subprocess.run(
                ["git", "worktree", "add", "--detach", str(worktree), "HEAD"],
                cwd=ROOT,
                check=True,
                capture_output=True,
                text=True,
            )
            try:
                target = worktree / relative
                target.parent.mkdir(parents=True, exist_ok=True)
                existing = target.read_text(encoding="utf-8") if target.exists() else ""
                target.write_text(existing + suffix, encoding="utf-8")
                subprocess.run(["git", "add", "--", relative], cwd=worktree, check=True, env=GIT_ENV)
                subprocess.run(
                    ["git", "commit", "--no-verify", "-m", "ci-selector fixture"],
                    cwd=worktree,
                    check=True,
                    env=GIT_ENV,
                    capture_output=True,
                    text=True,
                )
                sha = subprocess.run(
                    ["git", "rev-parse", "HEAD"],
                    cwd=worktree,
                    check=True,
                    capture_output=True,
                    text=True,
                ).stdout.strip()
                parent = subprocess.run(
                    ["git", "rev-parse", "HEAD^"],
                    cwd=worktree,
                    check=True,
                    capture_output=True,
                    text=True,
                ).stdout.strip()
            finally:
                subprocess.run(
                    ["git", "worktree", "remove", "--force", str(worktree)],
                    cwd=ROOT,
                    check=True,
                    capture_output=True,
                    text=True,
                )
        result = subprocess.run(
            ["bash", str(CI), "--affected", f"{parent}..{sha}"],
            cwd=ROOT,
            capture_output=True,
            text=True,
            check=True,
        )
        return [line for line in result.stdout.splitlines() if line]


if __name__ == "__main__":
    unittest.main()
