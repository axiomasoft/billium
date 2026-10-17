#!/usr/bin/env python3
"""Conformance runner: drives one adapter over the vector corpus and judges the run.

Usage:
  conformance/runner/run.py --adapter php|dart [--vectors DIR] [--report FILE] [--timeout SEC] [--phase Pn]
  conformance/runner/run.py --check-golden

Protocol: start → config → run × N → stop (JSON Lines). Statuses are closed: pass, fail, error. A run is
green only if the non-pass vectors equal the baseline, no control vector passed, the lockstep revisions
agree and the adapter stopped cleanly. Exit 0 green, 1 red, 2 usage error.
"""
from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path
from typing import Any

sys.path.insert(0, str(Path(__file__).resolve().parent))

import gate  # noqa: E402
from protocol import AdapterError, Schemas, Session  # noqa: E402
from vectors import Vector, VectorError, compare, load_vectors, resolve_golden  # noqa: E402

ROOT = Path(__file__).resolve().parents[2]
DEFAULT_TIMEOUT = 10.0


def latest_revision(root: Path) -> Path:
    revisions = sorted(path for path in (root / "spec/revisions").iterdir() if path.is_dir())
    if not revisions:
        raise FileNotFoundError(f"no spec revision under {root / 'spec/revisions'}")
    return revisions[-1]


def judge_vector(vector: Vector, session: Session, golden_root: Path) -> dict[str, Any]:
    """Run one vector and return its report entry; raises AdapterError when the session must be dropped."""
    document = vector.document
    unknown = {step["op"] for step in document["script"]} - set(session.ready["ops"])
    if unknown:
        return {"id": vector.id, "status": "fail", "diff": f"unknown op: {', '.join(sorted(unknown))}"}
    outputs = session.run(vector.id, document["given"], document["script"])
    diff = compare(resolve_golden(document["expect"], golden_root), outputs)
    return {"id": vector.id, "status": "pass"} if diff is None else {"id": vector.id, "status": "fail", "diff": diff}


def run_adapter(args: argparse.Namespace, root: Path, schemas: Schemas, spec_dir: Path) -> int:
    adapters = json.loads(Path(args.adapters or root / "conformance/adapters.json").read_text(encoding="utf-8"))
    violations: list[str] = []
    if args.adapter not in adapters:
        print(f"conformance: adapter {args.adapter!r} is not declared in adapters.json", file=sys.stderr)
        return 1
    cmd = adapters[args.adapter]["cmd"]

    vectors_dir = Path(args.vectors or root / "fixtures/vectors")
    golden_root = Path(args.golden or root / "fixtures/golden")
    selected = [v for v in load_vectors(vectors_dir, schemas) if v.problem is not None or args.adapter in v.profiles]
    if not any(v.control for v in selected):
        violations.append("no control vector (control: must-fail) in the profile: the run could accept any output")
    if not [v for v in selected if v.problem is None]:
        violations.append(f"no vector for profile {args.adapter}: the run has no subject")

    sources, problems = gate.lockstep_sources(root, spec_dir)
    violations += problems

    results: list[dict[str, Any]] = []
    session: Session | None = None
    unavailable: str | None = None
    ready_revs: set[str] = set()
    for vector in selected:
        if vector.problem is not None:
            results.append({"id": vector.id, "status": "error", "diff": vector.problem})
            continue
        if unavailable is not None:
            results.append({"id": vector.id, "status": "error", "diff": unavailable})
            continue
        try:
            if session is None:
                session = Session(cmd, root, args.adapter, schemas, args.timeout)
                ready_revs.add(session.start()["spec_rev"])
            results.append(judge_vector(vector, session, golden_root))
        except VectorError as error:
            results.append({"id": vector.id, "status": "error", "diff": str(error)})
        except AdapterError as error:
            starting = session is not None and not session.ready
            if session is not None:
                session.kill()
            session = None
            results.append({"id": vector.id, "status": "error", "diff": str(error)})
            if starting:
                unavailable = f"adapter did not start: {error}"
    if session is not None:
        problem = session.stop()
        if problem is not None:
            violations.append(problem)

    for rev in sorted(ready_revs):
        violations += gate.check_lockstep(sources, rev)
    if not ready_revs:
        violations += gate.check_lockstep(sources, None)

    by_id = {v.id: v for v in selected}
    non_pass: set[str] = set()
    for entry in results:
        control = by_id[entry["id"]].control
        if control and entry["status"] == "pass":
            violations.append(f"control vector passed (must fail): {entry['id']}")
        elif entry["status"] != "pass" and not (control and entry["status"] == "fail"):
            non_pass.add(entry["id"])
    baseline_path = Path(args.baseline or root / f"conformance/baseline/{args.adapter}.json")
    baseline, baseline_problems = gate.load_baseline(baseline_path, args.phase)
    violations += baseline_problems
    violations += gate.check_baseline(non_pass, baseline)

    counts = {status: sum(1 for entry in results if entry["status"] == status) for status in ("pass", "fail", "error")}
    report = {
        "evidence_class": "contracts-conformance",
        "adapter": args.adapter,
        "spec_rev": sources.get("revision.lock.json"),
        "counts": counts,
        "results": results,
        "violations": violations,
    }
    report_path = Path(args.report or root / f"conformance/reports/{args.adapter}.json")
    report_path.parent.mkdir(parents=True, exist_ok=True)
    report_path.write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    for entry in results:
        if entry["id"] in non_pass:
            print(f"  {entry['status']}: {entry['id']}: {entry.get('diff', '')}", file=sys.stderr)
    for violation in violations:
        print(f"  violation: {violation}", file=sys.stderr)
    green = not violations
    print(
        f"conformance {args.adapter}: pass {counts['pass']}, fail {counts['fail']}, error {counts['error']}, "
        f"baseline {len(baseline)}, {'GREEN' if green else 'RED'}"
    )
    return 0 if green else 1


def run_golden_check(args: argparse.Namespace, root: Path, schemas: Schemas) -> int:
    vectors_dir = Path(args.vectors or root / "fixtures/vectors")
    golden_root = Path(args.golden or root / "fixtures/golden")
    checked, findings = gate.check_golden(
        load_vectors(vectors_dir, schemas), golden_root, Path(args.oracle or root / "conformance/oracles/jcs")
    )
    for finding in findings:
        print(f"  golden: {finding}", file=sys.stderr)
    print(f"conformance golden: {checked} checked by the oracle, {'RED' if findings else 'GREEN'}")
    return 1 if findings else 0


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--adapter", help="adapter name from adapters.json (php or dart)")
    parser.add_argument("--check-golden", action="store_true", help="check golden under canon/ with the JCS oracle")
    parser.add_argument("--root", default=str(ROOT), help="repository root (default: this repository)")
    parser.add_argument("--adapters", help="adapters.json (default: conformance/adapters.json)")
    parser.add_argument("--vectors", help="vector directory (default: fixtures/vectors)")
    parser.add_argument("--golden", help="golden directory (default: fixtures/golden)")
    parser.add_argument("--baseline", help="baseline file (default: conformance/baseline/<adapter>.json)")
    parser.add_argument("--report", help="report file (default: conformance/reports/<adapter>.json)")
    parser.add_argument("--oracle", help="oracle directory (default: conformance/oracles/jcs)")
    parser.add_argument("--timeout", type=float, default=DEFAULT_TIMEOUT, help="seconds per vector (default: 10)")
    parser.add_argument("--phase", help="current phase Pn: baseline entries must belong to a later phase")
    args = parser.parse_args(argv)
    if args.check_golden == bool(args.adapter):
        parser.error("exactly one of --adapter or --check-golden is required")
    if args.phase is not None and not gate.PHASE.match(args.phase):
        parser.error("--phase must look like P2")

    root = Path(args.root).resolve()
    try:
        spec_dir = latest_revision(root)
        schemas = Schemas(spec_dir / "schema")
    except (OSError, ValueError, KeyError) as error:
        print(f"conformance: cannot load the spec revision: {error}", file=sys.stderr)
        return 1
    if args.check_golden:
        return run_golden_check(args, root, schemas)
    return run_adapter(args, root, schemas, spec_dir)


if __name__ == "__main__":
    sys.exit(main())
