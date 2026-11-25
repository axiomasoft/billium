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
SELECT = ROOT / "scripts" / "ci" / "select-jobs.py"
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

    def test_list_steps_names_only_existing_step_functions(self) -> None:
        steps = self.steps()
        self.assertIn("php-pg", steps)
        text = CI.read_text(encoding="utf-8")
        for step in steps:
            self.assertIn(f"step_{step.replace('-', '_')}() {{", text)

    def test_unknown_step_exits_nonzero(self) -> None:
        result = subprocess.run(
            ["bash", str(CI), "--step", "not-a-step"],
            cwd=ROOT,
            capture_output=True,
            text=True,
            check=False,
        )
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("unknown step", result.stderr)

    def test_workflow_runs_every_step_and_layer_through_the_gate_script(self) -> None:
        text = WORKFLOW.read_text(encoding="utf-8")
        # Jobs come from select-jobs.py, so a new ci.sh step it does not schedule fails here.
        selected = self.select("--layers", " ".join(LAYERS))
        scheduled: set[str] = set()
        for key in ("analyse", "quality", "smoke", "conformance"):
            scheduled.update(json.loads(selected[key]))
        if selected["unit"] == "true":
            scheduled.add("php-unit")
        if selected["pg-serial"] == "true":
            scheduled.add("php-pg-serial")
        if json.loads(selected["shards"]):
            scheduled.add("php-pg")
        self.assertEqual(sorted(scheduled), sorted(self.steps()))
        for output in ("analyse", "quality", "smoke", "conformance", "shards"):
            self.assertIn(f"fromJSON(needs.changes.outputs.{output})", text)
        for layer in ("lint", "derived", "dart"):
            self.assertIn(f"bash scripts/ci.sh --layer {layer}", text)
        self.assertIn('bash scripts/ci.sh --step "$STEP"', text)
        self.assertIn("name: CI gate", text)
        self.assertNotIn("--no-build", text)
        self.assertNotIn("vendor/bin/pest", text)
        self.assertNotIn("dart test", text)
        self.assertIn("branches: [main]", text)
        self.assertIn("pull_request:", text)
        self.assertNotIn("push:\n  pull_request:", text)
        self.assertNotIn("WORKSPACE_READ", text)
        self.assertNotIn("SKM_", text)
        self.assertNotIn("swissknifeman", text)
        self.assertNotIn("workspaces/axioma", text)

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

    def test_select_jobs_narrows_a_manual_run_to_named_cells(self) -> None:
        selected = self.select("--layers", " ".join(LAYERS), "--only", "php-pg:3 conformance-integration derived")
        self.assertEqual(selected["shards"], "[3]")
        self.assertEqual(selected["shard-total"], "8")
        self.assertEqual(selected["conformance"], '["conformance-integration"]')
        self.assertEqual(selected["derived"], "true")
        self.assertEqual(selected["analyse"], "[]")
        self.assertEqual(selected["unit"], "false")
        self.assertEqual(selected["workflows"], "false")

    def test_select_jobs_follows_layers_and_skips_coverage_on_pull_requests(self) -> None:
        selected = self.select("--event", "pull_request", "--layers", "dart")
        self.assertEqual(selected["dart"], "true")
        self.assertEqual(selected["shards"], "[]")
        self.assertEqual(selected["conformance"], "[]")
        self.assertEqual(selected["workflows"], "true")
        self.assertEqual(selected["coverage"], "false")

    def test_select_jobs_rejects_unknown_names(self) -> None:
        for extra in (["--layers", "php typo"], ["--layers", "php", "--only", "php-pg:99"]):
            result = subprocess.run(
                ["python3", str(SELECT), "--event", "workflow_dispatch", *extra],
                capture_output=True,
                text=True,
                check=False,
            )
            self.assertEqual(result.returncode, 2, extra)

    def select(self, *args: str) -> dict[str, str]:
        if "--event" not in args:
            args = ("--event", "workflow_dispatch", *args)
        result = subprocess.run(
            ["python3", str(SELECT), *args],
            capture_output=True,
            text=True,
            check=True,
        )
        return dict(line.split("=", 1) for line in result.stdout.splitlines() if line)

    def steps(self) -> list[str]:
        result = subprocess.run(
            ["bash", str(CI), "--list-steps"],
            cwd=ROOT,
            capture_output=True,
            text=True,
            check=True,
        )
        return [line for line in result.stdout.splitlines() if line]

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
