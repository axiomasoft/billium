#!/usr/bin/env python3
"""Execute universal product-agnostic consumer/bridge contract suite (CT-01..20) on real PostgreSQL/HTTP."""

from __future__ import annotations

import argparse
from contextlib import contextmanager
import json
import os
from pathlib import Path
import shlex
import socket
import subprocess
import sys
import tempfile
from time import monotonic, sleep
from typing import Any

sys.dont_write_bytecode = True
HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[2]
if str(HERE) not in sys.path:
    sys.path.insert(0, str(HERE))

import importlib.util  # noqa: E402
from protocol import (  # noqa: E402
    BridgeAdapterClient,
    CASE_PROTOCOL_ID,
    FIXTURES,
    REPORT_PROTOCOL_ID,
    REQUIRED_CASES,
    canonical_digest,
    check_no_d71_forbidden_terms,
    json_compact_bytes,
    load_json,
    producer_identity,
    schema_validator,
    workspace_hashes,
)

_spec = importlib.util.spec_from_file_location("check_report", HERE / "check-report.py")
assert _spec is not None and _spec.loader is not None
check_report = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(check_report)


def php_command(*args: Any) -> list[str]:
    return [os.environ.get("PHP84_BIN", "php"), *map(str, args)]


def reserve_port() -> int:
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        return int(sock.getsockname()[1])


def load_corpus() -> dict[str, dict[str, Any]]:
    validator = schema_validator("case")
    cases: dict[str, dict[str, Any]] = {}
    for path in sorted((FIXTURES / "vectors").glob("CT-*.json")):
        raw = path.read_text(encoding="utf-8")
        d71_errors = check_no_d71_forbidden_terms(raw, path.name)
        if d71_errors:
            raise ValueError("; ".join(d71_errors))
        doc = json.loads(raw)
        validator.validate(doc)
        if doc.get("protocol") != CASE_PROTOCOL_ID:
            raise ValueError(f"{path.name}: invalid case protocol")
        cid = doc["id"]
        if cid in cases:
            raise ValueError(f"Duplicate consumer contract case {cid}")
        cases[cid] = doc
    if set(cases) != set(REQUIRED_CASES):
        diff = sorted(set(cases).symmetric_difference(REQUIRED_CASES))
        raise ValueError(f"Consumer fixture inventory mismatch: {diff}")
    return cases


def resolve_field(value: Any, dotted: str) -> Any:
    current = value
    for part in dotted.split(".") if dotted else []:
        if part == "#":
            current = len(current)
        elif isinstance(current, list):
            current = current[int(part)]
        else:
            current = current[part]
    return current


def expand_refs(node: Any, context: dict[str, Any]) -> Any:
    if isinstance(node, dict):
        if set(node) == {"$ref"}:
            return resolve_field(context, str(node["$ref"]))
        return {k: expand_refs(v, context) for k, v in node.items()}
    if isinstance(node, list):
        return [expand_refs(item, context) for item in node]
    return node


def assert_expectations(actual: Any, expected: dict[str, Any], label: str) -> None:
    for dotted, wanted in expected.items():
        try:
            found = resolve_field(actual, dotted)
        except (KeyError, IndexError, TypeError) as exc:
            raise ValueError(f"{label}.{dotted}: missing (expected {wanted!r})") from exc
        if found != wanted or type(found) is not type(wanted):
            raise ValueError(f"{label}.{dotted}: expected {wanted!r}, got {found!r}")


class ProducerStand:
    def __init__(self, directory: Path, log_handle: Any) -> None:
        self.directory = directory
        self.log_handle = log_handle

    def cli(self, action: str, payload: dict[str, Any] | None = None, *extra: str) -> Any:
        cmd = php_command(HERE / "host.php", action, self.directory, *extra)
        proc = subprocess.run(
            cmd,
            cwd=ROOT,
            input=json_compact_bytes(payload or {}),
            stdout=subprocess.PIPE,
            stderr=self.log_handle,
            timeout=60,
            check=True,
        )
        return None if action == "drop" else json.loads(proc.stdout)

    @contextmanager
    def serve(self, stand_profile: str):
        runtime_info = self.cli("create", None, stand_profile)
        try:
            port = reserve_port()
            workers = max(8, int(os.environ.get("PHP_CLI_SERVER_WORKERS", "8") or "8"))
            env = {
                **os.environ,
                "BILLIUM_BRIDGE_WORK": str(self.directory),
                "PHP_CLI_SERVER_WORKERS": str(workers),
            }
            server = subprocess.Popen(
                php_command("-S", f"127.0.0.1:{port}", HERE / "host.php"),
                cwd=self.directory,
                env=env,
                stdout=self.log_handle,
                stderr=self.log_handle,
            )
            try:
                deadline = monotonic() + 6.0
                while monotonic() < deadline and server.poll() is None:
                    try:
                        with socket.create_connection(("127.0.0.1", port), timeout=0.1):
                            break
                    except OSError:
                        sleep(0.02)
                else:
                    raise RuntimeError("Loopback Billium producer HTTP server did not start")
                yield port, runtime_info
            finally:
                server.terminate()
                try:
                    server.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    server.kill()
                    server.wait()
        finally:
            self.cli("drop")


def classify_step(boundary: str, target: str, op: str, args: dict[str, Any]) -> tuple[str, str]:
    if boundary == "double":
        if op in {"provider_evidence", "worker_dispatch", "fenced_worker_dispatch", "late_provider_callback"}:
            return "provider-double", "double"
        if op == "host_provisioning_drill":
            return "host-double", "double"
        return "transport-contract", "double"

    if target == "stand" and op in {"provider_evidence", "worker_dispatch", "fenced_worker_dispatch", "late_provider_callback"}:
        return "provider-double", "double"
    if op == "host_provisioning_drill":
        return "host-double", "double"
    if op in {"preflight_command", "archive_decode_matrix"} or args.get("fault") in {"drop_response", "corrupt_response", "retry_after_503"}:
        return "transport-contract", boundary
    return "consumer-contract", boundary


def build_pending_external_report(suite: str, cases: dict[str, dict[str, Any]], selected: tuple[str, ...], reason: str) -> dict[str, Any]:
    results = [
        {
            "id": cid,
            "status": "pending",
            "standProfile": cases[cid]["standProfile"],
            "authoritySource": cases[cid]["authoritySource"],
            "executionProfile": cases[cid]["executionProfile"],
            "packageDelivery": cases[cid]["packageDelivery"],
            "sources": cases[cid]["sources"],
            "transcript": [],
        }
        for cid in selected
    ]
    report: dict[str, Any] = {
        "protocol": REPORT_PROTOCOL_ID,
        "evidence_class": "consumer-contract",
        "boundary": "external-runtime",
        "status": "pending",
        "pendingReason": reason,
        "suite": suite,
        "required": list(REQUIRED_CASES),
        "producer": producer_identity(ROOT),
        "consumer": {
            "name": "external-consumer-unconfigured",
            "available": False,
            "commitHash": None,
            "packageHash": None,
            "specRevision": None,
            "specHash": None,
            "deliveryProfile": "external-http",
        },
        "runtime": {},
        "results": results,
        "evidence": [
            {"evidence_class": "consumer-contract", "boundary": "external-runtime", "steps": []},
        ],
        "hashes": workspace_hashes(ROOT),
        "paidPspTransactions": 0,
        "passed": False,
        "localRequiredSuiteComplete": False,
        "externalReady": False,
        "rolloutGatePassed": False,
    }
    report["reportDigest"] = canonical_digest(report, "reportDigest")
    return report


def is_bundled_reference_command(adapter_cmd: list[str]) -> bool:
    bundled_adapter = (HERE / "adapter.php").resolve()
    for token in adapter_cmd:
        candidate = Path(token)
        resolved = (candidate if candidate.is_absolute() else (ROOT / candidate)).resolve()
        if resolved == bundled_adapter:
            return True
    return False


def execute_suite(args: argparse.Namespace) -> int:
    cases = load_corpus()
    selected = REQUIRED_CASES if args.suite in {"all", "required"} else (args.suite,)
    if not set(selected) <= set(cases):
        raise ValueError(f"Unknown suite or case selector: {args.suite}")

    report_path = Path(args.report).resolve()
    report_path.parent.mkdir(parents=True, exist_ok=True)
    log_path = report_path.with_suffix(".log")

    # External consumer runtime requires an explicit external adapter command; if absent, record honest pending report.
    external_cmd_str = args.consumer_cmd or os.environ.get("BILLIUM_EXTERNAL_CONSUMER_CMD", "")
    if args.boundary == "external-runtime" and not external_cmd_str.strip():
        report = build_pending_external_report(
            args.suite,
            cases,
            selected,
            "Authorized external consumer deployment is not configured in this environment",
        )
        findings = check_report.validate_report(report, repo_root=ROOT)
        if findings:
            raise RuntimeError(f"Generated pending external report failed validation: {findings}")
        report_path.write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        print("external-runtime pending: externalReady=false rolloutGatePassed=false", flush=True)
        return 0 if args.allow_pending_external else 1

    adapter_cmd = shlex.split(external_cmd_str) if external_cmd_str.strip() else php_command(HERE / "adapter.php")
    if args.boundary == "external-runtime" and is_bundled_reference_command(adapter_cmd):
        print(
            "conformance/consumers/bridge: bundled reference adapter cannot be promoted to --boundary external-runtime (D59/D71)",
            file=sys.stderr,
        )
        return 1

    results: list[dict[str, Any]] = []
    runtime_meta: dict[str, Any] = {}
    ready_meta: dict[str, Any] = {}

    with log_path.open("w", encoding="utf-8") as log_handle:
        for case_id in selected:
            case = cases[case_id]
            entry: dict[str, Any] = {
                "id": case_id,
                "status": "fail",
                "standProfile": case["standProfile"],
                "authoritySource": case["authoritySource"],
                "executionProfile": case["executionProfile"],
                "packageDelivery": case["packageDelivery"],
                "sources": case["sources"],
                "transcript": [],
            }
            results.append(entry)
            try:
                with tempfile.TemporaryDirectory(prefix=f"ct-{case_id.lower()}-", dir=report_path.parent) as tmp_str:
                    work_dir = Path(tmp_str)
                    stand = ProducerStand(work_dir, log_handle)
                    with stand.serve(case["standProfile"]) as (port, runtime_meta):
                        producer_url = args.producer_url or f"http://127.0.0.1:{port}"
                        client = BridgeAdapterClient(adapter_cmd, ROOT, log_handle)
                        try:
                            ready_meta = client.start(args.boundary, producer_url, work_dir)
                            ctx_data: dict[str, Any] = {"port": port, "specHash": ready_meta["specHash"]}
                            for raw_step in case["steps"]:
                                step = expand_refs(raw_step, ctx_data)
                                s_name = step["name"]
                                s_target = step["target"]
                                s_op = step["op"]
                                s_args = step.get("args", {})
                                if s_target == "consumer":
                                    observed = client.step(case_id, s_name, s_op, s_args)
                                else:
                                    if s_op in {"checkpoint", "restore_quarantined"}:
                                        observed = stand.cli(s_op)
                                    else:
                                        observed = stand.cli(s_op, s_args)
                                ev_cls, ev_bnd = classify_step(args.boundary, s_target, s_op, s_args)
                                entry["transcript"].append({
                                    "step": s_name,
                                    "target": s_target,
                                    "op": s_op,
                                    "evidence_class": ev_cls,
                                    "boundary": ev_bnd,
                                    "observed": observed,
                                })
                                assert_expectations(observed, step["expect"], f"{case_id}.{s_name}")
                                ctx_data[s_name] = observed
                        finally:
                            client.stop()
                entry["status"] = "pass"
            except Exception as exc:
                entry["error"] = str(exc)
            suffix = f": {entry['error']}" if "error" in entry else ""
            print(f"{case_id} {entry['status']}{suffix}", flush=True)

    summary_buckets: dict[tuple[str, str], list[str]] = {}
    for res in results:
        for step_entry in res["transcript"]:
            key = (step_entry["evidence_class"], step_entry["boundary"])
            summary_buckets.setdefault(key, []).append(f"{res['id']}/{step_entry['step']}")

    evidence = [
        {"evidence_class": cls, "boundary": bnd, "steps": steps}
        for (cls, bnd), steps in sorted(summary_buckets.items())
    ] or [{"evidence_class": "consumer-contract", "boundary": args.boundary, "steps": []}]

    all_passed = all(r["status"] == "pass" for r in results)
    full_suite = set(selected) == set(REQUIRED_CASES)
    producer_info = producer_identity(ROOT)
    is_bundled_ref = bool(
        ready_meta.get("isBundledReference") is True
        or ready_meta.get("adapter") == check_report.BUNDLED_REFERENCE_ADAPTER_NAME
        or ready_meta.get("packageHash") == check_report.bundled_reference_package_hash(ROOT)
    )
    is_external_ready = (
        args.boundary == "external-runtime"
        and all_passed
        and full_suite
        and not is_bundled_ref
        and ready_meta.get("deliveryProfile") == "external-http"
        and ready_meta.get("packageHash") != producer_info["packageHash"]
    )

    consumer_block: dict[str, Any] = {
        "name": ready_meta.get("adapter", "unknown-consumer"),
        "available": True,
        "commitHash": ready_meta.get("commitHash"),
        "packageHash": ready_meta.get("packageHash"),
        "specRevision": ready_meta.get("specRevision"),
        "specHash": ready_meta.get("specHash"),
        "deliveryProfile": ready_meta.get(
            "deliveryProfile",
            "double-transport" if args.boundary == "double" else "loopback-http",
        ),
    }
    if "isBundledReference" in ready_meta:
        consumer_block["isBundledReference"] = bool(ready_meta["isBundledReference"])

    report = {
        "protocol": REPORT_PROTOCOL_ID,
        "evidence_class": "consumer-contract",
        "boundary": args.boundary,
        "status": "passed" if all_passed else "failed",
        "suite": args.suite,
        "required": list(REQUIRED_CASES),
        "producer": producer_info,
        "consumer": consumer_block,
        "runtime": runtime_meta,
        "results": results,
        "evidence": evidence,
        "hashes": workspace_hashes(ROOT),
        "paidPspTransactions": 0,
        "passed": all_passed,
        "localRequiredSuiteComplete": all_passed and full_suite and args.boundary in {"loopback", "external-runtime"},
        "externalReady": is_external_ready,
        "rolloutGatePassed": is_external_ready,
    }
    report["reportDigest"] = canonical_digest(report, "reportDigest")

    findings = check_report.validate_report(report, repo_root=ROOT)
    if findings:
        raise RuntimeError(f"Generated report failed self-check: {findings}")

    report_path.write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    return 0 if all_passed else 1


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--boundary", choices=["double", "loopback", "external-runtime"], default="loopback")
    parser.add_argument("--suite", default="all", help="all, required, or CT-XX")
    parser.add_argument("--report", default=str(ROOT / "conformance/reports/consumer-loopback.json"))
    parser.add_argument("--consumer-cmd", default="", help="External consumer adapter command for external-runtime")
    parser.add_argument("--producer-url", default="", help="Optional external producer base URL")
    parser.add_argument("--allow-pending-external", action="store_true", help="Exit 0 after emitting honest pending report when external consumer is absent")
    args = parser.parse_args(argv)
    return execute_suite(args)


if __name__ == "__main__":
    sys.exit(main())
