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
GIT = ["git", "-c", "safe.directory=*"]
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
        self.assertNotIn("--no-build", text)
        self.assertNotIn("vendor/bin/pest", text)
        self.assertNotIn("dart test", text)
        self.assertIn("branches: [main]", text)
        self.assertIn("pull_request:", text)
        self.assertNotIn("push:\n  pull_request:", text)
        self.assertIn("SKM_WORKSPACES: ${{ github.workspace }}/workspaces", text)
        self.assertIn("path: workspaces/axioma", text)
        self.assertNotIn(".local/share/swissknifeman", text)

    def test_non_final_command_failure_aborts_single_layer_and_parallel_run(self) -> None:
        with tempfile.TemporaryDirectory(prefix="billium-ci-stub-") as directory:
            stub_bin = Path(directory)
            python_stub = stub_bin / "python3"
            python_stub.write_text(
                "#!/usr/bin/env bash\n"
                'if [[ "${1:-}" == "scripts/spec/build-rules.py" ]]; then\n'
                "  echo 'stub-fail-build-rules' >&2\n"
                "  exit 7\n"
                "fi\n"
                "exit 0\n",
                encoding="utf-8",
            )
            python_stub.chmod(0o755)
            dart_stub = stub_bin / "dart"
            dart_stub.write_text("#!/usr/bin/env bash\nexit 0\n", encoding="utf-8")
            dart_stub.chmod(0o755)

            env = {**os.environ, "PATH": f"{stub_bin}:{os.environ.get('PATH', '')}"}

            single = subprocess.run(
                ["bash", str(CI), "--layer", "derived"],
                cwd=ROOT,
                env=env,
                capture_output=True,
                text=True,
                check=False,
            )
            self.assertNotEqual(single.returncode, 0, single.stdout + single.stderr)
            self.assertIn("derived: FAIL", single.stdout)

            full = subprocess.run(
                ["bash", str(CI)],
                cwd=ROOT,
                env=env,
                capture_output=True,
                text=True,
                check=False,
            )
            self.assertNotEqual(full.returncode, 0, full.stdout + full.stderr)
            self.assertIn("derived: FAIL", full.stdout)
            self.assertNotIn("php:", full.stdout)
            self.assertNotIn("conformance:", full.stdout)

    def affected_for(self, relative: str, suffix: str) -> list[str]:
        with tempfile.TemporaryDirectory(prefix="billium-ci-git-") as directory:
            repo = Path(directory) / "repo"
            repo.mkdir()
            subprocess.run([*GIT, "init", "-q"], cwd=repo, env=GIT_ENV, check=True)
            (repo / ".gitkeep").write_text("", encoding="utf-8")
            subprocess.run([*GIT, "add", ".gitkeep"], cwd=repo, env=GIT_ENV, check=True)
            subprocess.run(
                [*GIT, "commit", "--no-verify", "-q", "-m", "base"],
                cwd=repo,
                env=GIT_ENV,
                check=True,
            )
            parent = subprocess.run(
                [*GIT, "rev-parse", "HEAD"],
                cwd=repo,
                env=GIT_ENV,
                check=True,
                capture_output=True,
                text=True,
            ).stdout.strip()

            target = repo / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_text(suffix, encoding="utf-8")
            subprocess.run([*GIT, "add", "--", relative], cwd=repo, env=GIT_ENV, check=True)
            subprocess.run(
                [*GIT, "commit", "--no-verify", "-q", "-m", "ci-selector fixture"],
                cwd=repo,
                env=GIT_ENV,
                check=True,
            )
            sha = subprocess.run(
                [*GIT, "rev-parse", "HEAD"],
                cwd=repo,
                env=GIT_ENV,
                check=True,
                capture_output=True,
                text=True,
            ).stdout.strip()

            result = subprocess.run(
                ["bash", str(CI), "--affected", f"{parent}..{sha}"],
                cwd=ROOT,
                env={**GIT_ENV, "GIT_DIR": str(repo / ".git"), "GIT_WORK_TREE": str(repo)},
                capture_output=True,
                text=True,
                check=True,
            )
            return [line for line in result.stdout.splitlines() if line]


if __name__ == "__main__":
    unittest.main()
