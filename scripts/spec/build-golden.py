#!/usr/bin/env python3
"""Build fixtures/golden from the vectors: canon/* with the JCS oracle, errors/registry.json from problem-codes.json.

Golden is authoritative and created only here: a golden file referenced by a vector under canon/ is the
oracle's output for the vector's input (`canon.canonicalize`/`envelope.roundtrip` → text, `canon.digest`/
`envelope.decode` → digest). The golden of a control vector is the oracle digest with its last hex
digit changed, so the control is deliberately wrong. Writes by default; --check compares without writing and
exits 1 on drift, on an unreferenced golden or on a golden without a generator.
"""
from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "conformance/runner"))
sys.path.insert(0, str(ROOT / "conformance/oracles/jcs"))

import oracle  # noqa: E402
from gate import ORACLE_FIELDS, oracle_value  # noqa: E402
from protocol import Schemas  # noqa: E402
from vectors import golden_ref, load_vectors  # noqa: E402

REGISTRY_GOLDEN = "errors/registry.json"
REGISTRY_FIELDS = ("code", "category", "retryable", "sameKey", "receipt")


def corrupt(digest: str) -> str:
    """A well-formed digest that differs from the oracle's in its last hex digit."""
    return digest[:-1] + ("0" if digest[-1] != "0" else "1")


def registry_golden(codes_path: Path) -> str:
    codes = json.loads(codes_path.read_text(encoding="utf-8"))
    rows = [{name: entry[name] for name in REGISTRY_FIELDS} for entry in codes]
    return json.dumps(rows, ensure_ascii=False, indent=2) + "\n"


def build(rev: Path, vectors_dir: Path) -> tuple[dict[str, str], list[str]]:
    """Expected golden files by path under fixtures/golden and the problems that stop a build."""
    files: dict[str, str] = {}
    problems: list[str] = []
    schemas = Schemas(rev / "schema")
    for vector in load_vectors(vectors_dir, schemas):
        if vector.problem is not None:
            problems.append(f"{vector.id}: {vector.problem}")
            continue
        for step, want in zip(vector.document["script"], vector.document["expect"]):
            ok = want.get("ok")
            if not isinstance(ok, dict):
                continue
            for name, value in ok.items():
                ref = golden_ref(value)
                if ref is None:
                    continue
                if ref == REGISTRY_GOLDEN:
                    files[ref] = registry_golden(rev / "problem-codes.json")
                    continue
                if not ref.startswith("canon/") or (step["op"], name) not in ORACLE_FIELDS:
                    problems.append(f"{vector.id}: golden {ref} of {step['op']}.{name} has no generator")
                    continue
                json_text = step["args"].get("json_text")
                if not isinstance(json_text, str):
                    problems.append(f"{vector.id}: {step['op']} has no json_text for golden {ref}")
                    continue
                value_text = oracle_value(oracle, step["op"], name, json_text)
                if vector.control:
                    if name != "digest":
                        problems.append(f"{vector.id}: a control golden is supported for digest only")
                        continue
                    value_text = corrupt(value_text)
                text = value_text + "\n"
                if files.setdefault(ref, text) != text:
                    problems.append(f"{vector.id}: golden {ref} would get two different contents")
    return files, problems


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--rev", type=Path, default=ROOT / "spec/revisions/2026-10")
    parser.add_argument("--vectors", type=Path, default=ROOT / "fixtures/vectors")
    parser.add_argument("--golden", type=Path, default=ROOT / "fixtures/golden")
    parser.add_argument("--check", action="store_true", help="compare only, never write")
    args = parser.parse_args()

    files, problems = build(args.rev, args.vectors)
    present = {path.relative_to(args.golden).as_posix() for path in args.golden.rglob("*") if path.is_file()}
    for rel in sorted(present - files.keys()):
        problems.append(f"golden {rel} is not referenced by any vector")
    for rel, text in sorted(files.items()):
        path = args.golden / rel
        if args.check:
            if not path.is_file() or path.read_bytes() != text.encode("utf-8"):
                problems.append(f"golden {rel} differs from the generator output")
        else:
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(text.encode("utf-8"))
    for problem in problems:
        print(f"build-golden: {problem}", file=sys.stderr)
    if problems:
        return 1
    print(f"build-golden: {len(files)} golden files {'up to date' if args.check else 'written'}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
