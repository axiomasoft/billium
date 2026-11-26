#!/usr/bin/env python3
"""Reusable JSON-Lines adapter client, JCS hashing and metadata helpers for consumer/bridge contracts."""

from __future__ import annotations

import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
from typing import Any

from jsonschema import Draft202012Validator

sys.dont_write_bytecode = True
HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[2]
FIXTURES = ROOT / "fixtures/integration/consumer"
SPEC = ROOT / "spec/revisions/2026-10"
if str(ROOT / "conformance/oracles/jcs") not in sys.path:
    sys.path.insert(0, str(ROOT / "conformance/oracles/jcs"))

import oracle  # noqa: E402

PROTOCOL_ID = "billium.consumer.bridge.protocol.v1"
REPORT_PROTOCOL_ID = "billium.consumer.contract.report.v1"
CASE_PROTOCOL_ID = "billium.consumer.bridge.case.v1"
REQUIRED_CASES = tuple(f"CT-{idx:02d}" for idx in range(1, 21))

# D71 strict self-isolation terms (constructed without literal forbidden tokens).
FORBIDDEN_D71_TERMS = (
    "mem" + "ster",
    "edu" + "fy",
    "com" + "merz",
    "leg" + "acy",
)


def sha256_bytes(data: bytes) -> str:
    return "sha256:" + hashlib.sha256(data).hexdigest()


def json_compact_bytes(value: Any) -> bytes:
    return json.dumps(value, separators=(",", ":"), ensure_ascii=False).encode("utf-8")


def canonical_digest(document: dict[str, Any], exclude_member: str | None = "reportDigest") -> str:
    body = {k: v for k, v in document.items() if k != exclude_member}
    canonical = oracle.canonicalize(json.dumps(body, ensure_ascii=False))
    return sha256_bytes(canonical)


def load_json(path: Path) -> Any:
    return json.loads(path.read_text(encoding="utf-8"))


def schema_validator(name: str) -> Draft202012Validator:
    schema = load_json(FIXTURES / "schema" / f"{name}.json")
    Draft202012Validator.check_schema(schema)
    return Draft202012Validator(schema)


def tree_digest(paths: list[Path], repo_root: Path = ROOT) -> str:
    members: dict[str, str] = {}
    ignored = {"__pycache__", ".dart_tool", ".git"}
    for item in paths:
        files = sorted(item.rglob("*")) if item.is_dir() else ([item] if item.is_file() else [])
        for file_path in files:
            if file_path.is_file() and not (set(file_path.parts) & ignored):
                rel = file_path.relative_to(repo_root).as_posix()
                members[rel] = sha256_bytes(file_path.read_bytes())
    return sha256_bytes(oracle.canonicalize(json.dumps(members, ensure_ascii=False)))


def current_git_commit(repo_root: Path = ROOT) -> str:
    result = subprocess.run(
        ["git", "rev-parse", "HEAD"],
        cwd=repo_root,
        stdout=subprocess.PIPE,
        stderr=subprocess.DEVNULL,
        check=False,
        text=True,
    )
    commit = result.stdout.strip()
    return commit if len(commit) >= 7 else ("0" * 40)


def current_spec_hash(repo_root: Path = ROOT) -> str:
    return sha256_bytes((repo_root / "spec/revisions/2026-10/revision.lock.json").read_bytes())


def producer_identity(repo_root: Path = ROOT) -> dict[str, str]:
    pkg_paths = [
        repo_root / "packages" / name
        for name in ("contracts", "core", "server")
    ]
    return {
        "name": "billium-server-loopback-producer",
        "commitHash": current_git_commit(repo_root),
        "packageHash": tree_digest(pkg_paths, repo_root),
        "specRevision": "2026-10",
        "specHash": current_spec_hash(repo_root),
    }


def workspace_hashes(repo_root: Path = ROOT) -> dict[str, str]:
    return {
        "spec": current_spec_hash(repo_root),
        "fixtures": tree_digest([repo_root / "fixtures/integration/consumer"], repo_root),
        "harness": tree_digest([repo_root / "conformance/consumers/bridge"], repo_root),
        "packages": tree_digest(
            [repo_root / "packages" / p for p in ("core", "contracts", "contracts-dart", "server", "testkit")],
            repo_root,
        ),
        "dependencies": sha256_bytes((repo_root / "composer.lock").read_bytes()),
    }


def check_no_d71_forbidden_terms(text: str, label: str) -> list[str]:
    lowered = text.lower()
    findings: list[str] = []
    for term in FORBIDDEN_D71_TERMS:
        if term in lowered:
            findings.append(f"{label}: contains forbidden D71 self-isolation term '{term}'")
    return findings


class BridgeAdapterClient:
    """JSON-Lines subprocess client speaking billium.consumer.bridge.protocol.v1."""

    def __init__(self, command: list[str], cwd: Path, log_handle: Any) -> None:
        self.command = command
        self.cwd = cwd
        self.log_handle = log_handle
        self.validator = schema_validator("protocol")
        self.proc: subprocess.Popen[str] | None = None
        self.ready: dict[str, Any] = {}

    def start(self, boundary: str, producer_url: str, work_dir: Path) -> dict[str, Any]:
        env = {**os.environ}
        self.proc = subprocess.Popen(
            self.command,
            cwd=self.cwd,
            env=env,
            stdin=subprocess.PIPE,
            stdout=subprocess.PIPE,
            stderr=self.log_handle,
            text=True,
            bufsize=1,
        )
        hello = {
            "type": "start",
            "protocol": PROTOCOL_ID,
            "boundary": boundary,
            "producerUrl": producer_url,
            "workDir": str(work_dir),
        }
        self.validator.validate(hello)
        reply = self._exchange(hello)
        if reply.get("type") != "ready":
            raise RuntimeError(f"Expected adapter ready handshake, got {reply!r}")
        self.ready = reply
        return reply

    def step(self, case_id: str, step_name: str, op: str, args: dict[str, Any]) -> dict[str, Any]:
        if op not in set(self.ready.get("supportedOps", [])):
            raise RuntimeError(f"Consumer adapter does not support op '{op}'")
        msg = {
            "type": "step",
            "caseId": case_id,
            "step": step_name,
            "op": op,
            "args": args,
        }
        self.validator.validate(msg)
        reply = self._exchange(msg)
        if reply.get("type") != "result" or reply.get("caseId") != case_id or reply.get("step") != step_name:
            raise RuntimeError(f"Mismatched consumer step result: {reply!r}")
        return reply["observed"]

    def stop(self) -> None:
        if self.proc is None:
            return
        try:
            if self.proc.poll() is None and self.proc.stdin is not None:
                stop_msg = {"type": "stop"}
                self.validator.validate(stop_msg)
                self.proc.stdin.write(json.dumps(stop_msg) + "\n")
                self.proc.stdin.flush()
                self.proc.wait(timeout=5)
        except Exception:
            if self.proc.poll() is None:
                self.proc.kill()
                self.proc.wait()
        finally:
            self.proc = None

    def _exchange(self, message: dict[str, Any]) -> dict[str, Any]:
        if self.proc is None or self.proc.stdin is None or self.proc.stdout is None:
            raise RuntimeError("Consumer adapter process is not running")
        self.proc.stdin.write(json.dumps(message, ensure_ascii=False) + "\n")
        self.proc.stdin.flush()
        line = self.proc.stdout.readline()
        if not line:
            code = self.proc.poll()
            raise RuntimeError(f"Consumer adapter exited unexpectedly (code={code})")
        parsed = json.loads(line)
        self.validator.validate(parsed)
        return parsed
