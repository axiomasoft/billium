#!/usr/bin/env python3
"""Resolve Billium plans exclusively from its SwissKnifeman workspace."""
from __future__ import annotations

import argparse
import json
import os
import re
import sys
from pathlib import Path
from typing import Mapping

ROOT = Path(__file__).resolve().parents[2]
CODE = re.compile(r"[a-z](?:[a-z0-9]|-(?=[a-z0-9])){0,39}")


class WorkspaceError(ValueError):
    pass


def plans_root(repo: Path, env: Mapping[str, str] | None = None) -> Path:
    env = os.environ if env is None else env
    marker_path = repo / ".swissknifeman/project.json"
    try:
        marker = json.loads(marker_path.read_text(encoding="utf-8"))
    except (OSError, ValueError) as error:
        raise WorkspaceError(f"{marker_path}: workspace marker is required: {error}") from error
    if (
        not isinstance(marker, dict)
        or marker.get("$schema") != "urn:swissknifeman:aicore:project-marker:v1"
        or marker.get("plans_store") != "workspace"
        or any(not isinstance(marker.get(key), str) or not CODE.fullmatch(marker[key])
               for key in ("workspace", "project_id"))
    ):
        raise WorkspaceError(f"{marker_path}: valid workspace/project codes and plans_store=workspace are required")

    workspaces = None
    for name in ("SKM_WORKSPACES_ROOT", "SKM_WORKSPACES", "SKM_HOME"):
        value = env.get(name)
        if value:
            workspaces = Path(value)
            if not workspaces.is_absolute():
                raise WorkspaceError(f"{name} must be an absolute path: {value}")
            if name == "SKM_HOME":
                workspaces /= "workspaces"
            break
    if workspaces is None:
        home = Path(env["HOME"]) if env.get("HOME") else Path.home()
        workspaces = home / ".local/share/swissknifeman/workspaces"

    plans = workspaces / marker["workspace"] / "projects" / marker["project_id"] / "plans"
    if not plans.is_dir():
        raise WorkspaceError(
            f"workspace plans missing: {plans}; clone/sync workspace {marker['workspace']} "
            "or set SKM_WORKSPACES to the absolute directory containing workspaces"
        )
    return plans.resolve()


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--repo-root", type=Path, default=ROOT)
    args = parser.parse_args()
    try:
        print(plans_root(args.repo_root.resolve()))
    except WorkspaceError as error:
        print(f"workspace: {error}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
