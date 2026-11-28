#!/usr/bin/env python3
"""External import conformance runner (V962 / V963).

Usage:
  python3 conformance/import/run.py --adapter php --suite all
  python3 conformance/import/run.py --adapter dart --suite codec

Enforces:
  - PHP is the sole monetary writer (--suite all / behavior / codec).
  - Dart runs only the shared input/output/error codec slice (--suite codec);
    any behavioral writer suite on Dart is refused fail-closed.
  - Golden outcomes in fixtures/integration/import/golden/golden-matrix.json are
    independently authored with canonical REQ/INV/BC/D71 citations and verified
    via the RFC 8785 JCS oracle (never regenerated from implementation output).
  - Emits hashed local runtime reports with explicit separation between local
    test-double/fixture counts, zero paid PSP transactions, and external owner
    activation inputs.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
from typing import Any

sys.dont_write_bytecode = True
HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[1]
sys.path[:0] = [str(ROOT / "conformance/runner"), str(ROOT / "conformance/oracles/jcs")]

import gate  # noqa: E402
import oracle  # noqa: E402
from protocol import AdapterError, Schemas, Session  # noqa: E402
from vectors import VectorError, compare, load_vectors, resolve_golden  # noqa: E402

GOLDEN_MATRIX_PATH = ROOT / "fixtures/integration/import/golden/golden-matrix.json"
IMPORT_VECTORS_DIR = ROOT / "fixtures/vectors/import"
REQUIRED_IM_IDS = tuple(f"IM-{i:02d}" for i in range(1, 18))


def canonical_digest(payload: dict[str, Any], exclude_key: str | None = None) -> str:
    body = {k: v for k, v in payload.items() if k != exclude_key} if exclude_key else payload
    return "sha256:" + hashlib.sha256(oracle.canonicalize(json.dumps(body, ensure_ascii=False))).hexdigest()


def load_golden_matrix() -> dict[str, Any]:
    if not GOLDEN_MATRIX_PATH.is_file():
        raise FileNotFoundError(f"Missing golden matrix: {GOLDEN_MATRIX_PATH}")
    doc = json.loads(GOLDEN_MATRIX_PATH.read_text(encoding="utf-8"))
    if doc.get("goldenAuthoring", {}).get("derivedFromImplementationOutput") is not False:
        raise ValueError("Golden matrix must never be derived from implementation output")
    cases = doc.get("cases", {})
    if set(cases.keys()) != set(REQUIRED_IM_IDS):
        diff = sorted(set(cases.keys()).symmetric_difference(REQUIRED_IM_IDS))
        raise ValueError(f"Golden matrix case inventory mismatch: {diff}")
    for cid, entry in cases.items():
        if not entry.get("spec_refs"):
            raise ValueError(f"Golden case {cid} missing spec_refs")
        expected = entry.get("expectedGolden")
        if not isinstance(expected, dict):
            raise ValueError(f"Golden case {cid} missing expectedGolden object")
        oracle_digest = canonical_digest(expected)
        if entry.get("expectedGoldenDigest") != oracle_digest:
            raise ValueError(
                f"Golden case {cid} expectedGoldenDigest mismatch (expected {oracle_digest}, got {entry.get('expectedGoldenDigest')})"
            )
    return doc


def run_codec_slice(adapter: str, root: Path, schemas: Schemas, spec_dir: Path, timeout: float) -> tuple[list[dict[str, Any]], list[str]]:
    adapters = json.loads((root / "conformance/adapters.json").read_text(encoding="utf-8"))
    if adapter not in adapters:
        return [], [f"unknown adapter {adapter!r}"]
    cmd = adapters[adapter]["cmd"]

    sources, problems = gate.lockstep_sources(root, spec_dir)
    violations: list[str] = list(problems)

    all_vectors = load_vectors(root / "fixtures/vectors", schemas)
    vectors = [
        v for v in all_vectors
        if v.id.startswith("import/") and (v.problem is not None or adapter in v.profiles)
    ]
    if not vectors:
        return [], [f"no shared import codec vectors found for adapter {adapter}"]

    golden_matrix = load_golden_matrix()
    codec_cases = golden_matrix.get("codecSlice", [])
    if not any(c.get("control") == "must-fail" for c in codec_cases):
        violations.append("codecSlice requires at least one control: must-fail vector")

    results: list[dict[str, Any]] = []
    session: Session | None = None
    ready_rev: str | None = None
    golden_root = root / "fixtures/golden"

    try:
        session = Session(cmd, root, adapter, schemas, timeout)
        ready = session.start()
        ready_rev = ready["spec_rev"]

        for vec in vectors:
            if vec.problem is not None:
                results.append({"id": vec.id, "status": "error", "diff": vec.problem})
                violations.append(f"{vec.id}: {vec.problem}")
                continue
            doc = vec.document
            outputs = session.run(vec.id, doc["given"], doc["script"])
            diff = compare(resolve_golden(doc["expect"], golden_root), outputs)
            status = "pass" if diff is None else "fail"
            entry = {"id": vec.id, "status": status, "slice": "codec"}
            if diff is not None:
                entry["diff"] = diff
                violations.append(f"{vec.id}: {diff}")
            results.append(entry)

        for ccase in codec_cases:
            cid = ccase["id"]
            is_control = ccase.get("control") == "must-fail"
            outputs = session.run(cid, ccase.get("given", {}), ccase["script"])
            diff = compare(ccase["expect"], outputs)
            status = "pass" if diff is None else "fail"
            entry = {"id": cid, "status": status, "slice": "codec", "control": is_control}
            if is_control:
                if status == "pass":
                    violations.append(f"control vector passed (must fail): {cid}")
            elif status != "pass":
                entry["diff"] = diff
                violations.append(f"{cid}: {diff}")
            results.append(entry)
    except (AdapterError, VectorError) as exc:
        violations.append(f"codec adapter error: {exc}")
    finally:
        if session is not None:
            stop_problem = session.stop()
            if stop_problem is not None:
                violations.append(stop_problem)

    violations.extend(gate.check_lockstep(sources, ready_rev))
    return results, violations


def run_php_behavior_slice(selected_ids: list[str], root: Path) -> tuple[list[dict[str, Any]], list[str], dict[str, Any]]:
    golden_matrix = load_golden_matrix()
    php_bin = os.environ.get("PHP84_BIN", "php")
    cmd = [php_bin, str(HERE / "php_behavior.php"), *selected_ids]
    proc = subprocess.run(
        cmd,
        cwd=root,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
        timeout=180,
        check=False,
    )
    if proc.returncode != 0:
        return [], [f"php_behavior.php exited {proc.returncode}: {proc.stderr.strip()}"], {}

    payload = json.loads(proc.stdout)
    observed_cases = payload.get("cases", {})
    results: list[dict[str, Any]] = []
    violations: list[str] = []

    if payload.get("paidPspTransactions") != 0:
        violations.append(f"paidPspTransactions must be 0, got {payload.get('paidPspTransactions')}")

    for cid in selected_ids:
        golden_entry = golden_matrix["cases"][cid]
        expected_golden = golden_entry["expectedGolden"]
        expected_digest = golden_entry["expectedGoldenDigest"]
        actual = observed_cases.get(cid)
        if not isinstance(actual, dict):
            results.append({"id": cid, "status": "error", "slice": "behavior", "diff": "missing observed case output"})
            violations.append(f"{cid}: missing observed output")
            continue

        actual_digest = canonical_digest(actual)
        diff = compare([{"ok": expected_golden}], [{"ok": actual}])
        if diff is None and actual_digest == expected_digest:
            results.append({
                "id": cid,
                "status": "pass",
                "slice": "behavior",
                "spec_refs": golden_entry["spec_refs"],
                "goldenDigest": expected_digest,
            })
        else:
            detail = diff or f"JCS digest mismatch: expected {expected_digest}, got {actual_digest}"
            results.append({
                "id": cid,
                "status": "fail",
                "slice": "behavior",
                "spec_refs": golden_entry["spec_refs"],
                "diff": detail,
            })
            violations.append(f"{cid}: {detail}")

    return results, violations, payload


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--adapter", required=True, choices=["php", "dart"], help="Target adapter (php or dart)")
    parser.add_argument("--suite", default="all", help="Suite selector: all, codec, behavior, or IM-01..IM-17")
    parser.add_argument("--report", default="", help="Optional output report path")
    parser.add_argument("--timeout", type=float, default=15.0, help="Per-vector codec timeout in seconds")
    parser.add_argument("--regenerate-golden", action="store_true", help=argparse.SUPPRESS)
    args = parser.parse_args(argv)

    if args.regenerate_golden:
        print("conformance/import: regenerating golden from implementation output is forbidden", file=sys.stderr)
        return 2

    if args.adapter == "dart" and args.suite != "codec":
        print(
            f"conformance/import: D53/D71 forbids Dart behavioral writer suite ({args.suite!r}); PHP is sole writer, Dart supports --suite codec only",
            file=sys.stderr,
        )
        return 2

    spec_dir = sorted(p for p in (ROOT / "spec/revisions").iterdir() if p.is_dir())[-1]
    schemas = Schemas(spec_dir / "schema")
    golden_matrix = load_golden_matrix()

    results: list[dict[str, Any]] = []
    violations: list[str] = []
    behavior_meta: dict[str, Any] = {}

    if args.suite in {"all", "codec"}:
        codec_results, codec_violations = run_codec_slice(args.adapter, ROOT, schemas, spec_dir, args.timeout)
        results.extend(codec_results)
        violations.extend(codec_violations)

    if args.adapter == "php" and args.suite != "codec":
        if args.suite in {"all", "behavior"}:
            selected_im = list(REQUIRED_IM_IDS)
        elif args.suite in REQUIRED_IM_IDS:
            selected_im = [args.suite]
        else:
            print(f"conformance/import: unknown suite {args.suite!r}", file=sys.stderr)
            return 2
        beh_results, beh_violations, behavior_meta = run_php_behavior_slice(selected_im, ROOT)
        results.extend(beh_results)
        violations.extend(beh_violations)

    counts = {
        "pass": sum(1 for r in results if r["status"] == "pass"),
        "fail": sum(1 for r in results if r["status"] == "fail"),
        "error": sum(1 for r in results if r["status"] == "error"),
    }

    report: dict[str, Any] = {
        "protocol": "billium.import.conformance.report.v1",
        "evidence_class": "import-codec-conformance" if args.suite == "codec" else "import-behavior-conformance",
        "localEvidenceClass": "sanitized_fixture_local_runtime",
        "adapter": args.adapter,
        "suite": args.suite,
        "spec_rev": spec_dir.name,
        "writerAuthority": "php_sole_writer" if args.adapter == "php" else "none_codec_slice_only",
        "counts": counts,
        "paidPspTransactions": 0,
        "testDoublePspCalls": behavior_meta.get("testDoublePspCalls", 0),
        "externalActivationInputsSeparated": True,
        "externalActivationInputs": golden_matrix["evidenceBoundary"]["externalActivationInputs"],
        "goldenMatrixDigest": golden_matrix["matrixDigest"],
        "results": results,
        "violations": violations,
    }
    report["reportDigest"] = canonical_digest(report, "reportDigest")

    default_report = ROOT / f"conformance/reports/import-{args.adapter}-{args.suite}.json"
    report_path = Path(args.report).resolve() if args.report else default_report
    report_path.parent.mkdir(parents=True, exist_ok=True)
    report_path.write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    for v in violations:
        print(f"  violation: {v}", file=sys.stderr)

    green = len(violations) == 0
    print(
        f"conformance/import {args.adapter} ({args.suite}): pass {counts['pass']}, fail {counts['fail']}, "
        f"error {counts['error']}, paidPsp=0, {'GREEN' if green else 'RED'}"
    )
    return 0 if green else 1


if __name__ == "__main__":
    sys.exit(main())
