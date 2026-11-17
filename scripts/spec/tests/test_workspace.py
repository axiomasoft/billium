"""Workspace-only plan resolution and provenance, independent of machine settings."""
from __future__ import annotations

import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

SPEC = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(SPEC))

from lint import Lint
from workspace import WorkspaceError, plans_root


class WorkspaceTest(unittest.TestCase):
    def setUp(self) -> None:
        temporary = tempfile.TemporaryDirectory(prefix="billium-workspace-")
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name)
        self.repo = self.root / "checkout"
        self.marker = self.repo / ".swissknifeman/project.json"
        self.marker.parent.mkdir(parents=True)
        self.document = {
            "$schema": "urn:swissknifeman:aicore:project-marker:v1",
            "workspace": "axioma",
            "project_id": "billium",
            "plans_store": "workspace",
        }
        self.write_marker()

    def write_marker(self) -> None:
        self.marker.write_text(json.dumps(self.document), encoding="utf-8")

    def store(self, workspaces: Path) -> Path:
        plans = workspaces / "axioma/projects/billium/plans"
        plans.mkdir(parents=True)
        return plans

    def test_default_workspace_under_home(self) -> None:
        home = self.root / "home"
        expected = self.store(home / ".local/share/swissknifeman/workspaces")
        self.assertEqual(plans_root(self.repo, {"HOME": str(home)}), expected)

    def test_skm_home(self) -> None:
        machine = self.root / "machine"
        expected = self.store(machine / "workspaces")
        self.assertEqual(plans_root(self.repo, {"SKM_HOME": str(machine)}), expected)

    def test_explicit_workspace_directories_take_precedence(self) -> None:
        workspaces = self.root / "portable"
        expected = self.store(workspaces)
        env = {"SKM_WORKSPACES": str(workspaces), "SKM_HOME": str(self.root / "missing")}
        self.assertEqual(plans_root(self.repo, env), expected)
        env["SKM_WORKSPACES_ROOT"] = str(workspaces)
        env["SKM_WORKSPACES"] = str(self.root / "also-missing")
        self.assertEqual(plans_root(self.repo, env), expected)

    def test_relative_environment_paths_are_refused(self) -> None:
        for name in ("SKM_HOME", "SKM_WORKSPACES", "SKM_WORKSPACES_ROOT"):
            with self.subTest(name=name), self.assertRaisesRegex(WorkspaceError, name):
                plans_root(self.repo, {name: "relative"})

    def test_marker_is_required_even_with_local_plans(self) -> None:
        (self.repo / "plans").mkdir()
        self.marker.unlink()
        with self.assertRaisesRegex(WorkspaceError, "workspace marker is required"):
            plans_root(self.repo, {})

    def test_invalid_markers_and_legacy_store_are_refused(self) -> None:
        for key, value in (("plans_store", "legacy"), ("workspace", "../other"), ("project_id", None)):
            with self.subTest(key=key):
                original = self.document[key]
                self.document[key] = value
                self.write_marker()
                with self.assertRaisesRegex(WorkspaceError, "plans_store=workspace"):
                    plans_root(self.repo, {})
                self.document[key] = original

    def test_missing_workspace_fails_without_using_local_plans(self) -> None:
        (self.repo / "plans").mkdir()
        with self.assertRaisesRegex(WorkspaceError, "workspace plans missing"):
            plans_root(self.repo, {"SKM_WORKSPACES": str(self.root / "absent")})

    def test_provenance_reads_only_this_projects_workspace(self) -> None:
        workspaces = self.root / "portable"
        plans = self.store(workspaces)
        for base, decision, method in (
            (plans, "D29", "M-15"),
            (self.repo / "plans", "D999", "M-99"),
            (workspaces / "axioma/projects/other/plans", "D777", "M-77"),
        ):
            decisions = base / "plan/decisions"
            decisions.mkdir(parents=True)
            (decisions / f"{decision}.json").write_text("{}", encoding="utf-8")
            methods = base / "plan/artifacts/P1-methods/methods.json"
            methods.parent.mkdir(parents=True)
            methods.write_text(json.dumps({"practices": [{"id": method}]}), encoding="utf-8")
        requirements = self.repo / "docs/requirements.md"
        requirements.parent.mkdir()
        requirements.write_text("| REQ-CON-01 | Example SRC-001 |\n", encoding="utf-8")
        from unittest.mock import patch
        with patch.dict("os.environ", {"SKM_WORKSPACES_ROOT": str(workspaces)}):
            lint = Lint(self.repo / "spec/2026-10", self.repo)
            self.assertEqual(lint.known_refs(), {"D29", "M-15", "REQ-CON-01", "SRC-001"})
            self.assertEqual(lint.errors, [])

    def test_cli_reports_missing_marker_without_a_traceback(self) -> None:
        self.marker.unlink()
        result = subprocess.run(
            [sys.executable, str(SPEC / "lint.py"), "--repo-root", str(self.repo)],
            text=True, capture_output=True, check=False,
        )
        self.assertEqual(result.returncode, 1)
        self.assertIn("workspace marker is required", result.stderr)
        self.assertNotIn("Traceback", result.stderr)
        self.assertNotIn("reference D", result.stderr)


if __name__ == "__main__":
    unittest.main()
