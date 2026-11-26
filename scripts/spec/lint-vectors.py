#!/usr/bin/env python3
"""Lint the conformance corpus: vectors, golden and coverage.

Usage: scripts/spec/lint-vectors.py [--rev DIR] [--vectors DIR] [--golden DIR] [--coverage FILE] [--repo-root DIR]
Exits 1 with one line per finding.

- every vector is valid by schema/conformance/vector.json, its id equals its path, expect matches script in length;
- every spec_ref resolves (BC-* in rules.json, REQ/INV/D# as in lint.py);
- every rule of rules.json is in some spec_refs or in fixtures/coverage.json with a reason (not_testable);
- every closed command schema has a vector with an unknown member that is rejected for that reason alone;
- literal digests and canonical texts in vectors equal the oracle output; a control golden differs from it;
- every golden file exists and is referenced; the required sets are not empty.
"""
from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path
from typing import Any

from jsonschema import Draft202012Validator

ROOT = Path(__file__).resolve().parents[2]
CITATION = re.compile(r"^(?:D\d+|M-\d{2})$")
sys.path.insert(0, str(ROOT / "scripts/spec"))
sys.path.insert(0, str(ROOT / "conformance/runner"))
sys.path.insert(0, str(ROOT / "conformance/oracles/jcs"))

import lint  # noqa: E402
import oracle  # noqa: E402
from gate import ORACLE_FIELDS, oracle_value  # noqa: E402
from protocol import Schemas  # noqa: E402
from vectors import Vector, golden_ref, load_vectors, read_golden, VectorError  # noqa: E402

REQUIRED_SETS = [
    "money/scale", "money/bounds", "money/allocation", "money/rounding", "canon/digest", "payment/execute",
    "payment/admission", "payment/outcome", "usage/fact", "errors/codes", "compat/pilot",
]
INVALID = "billium.validation.invalid"


class CorpusLint:
    def __init__(self, rev: Path, vectors_dir: Path, golden_dir: Path, coverage: Path, repo: Path) -> None:
        self.rev, self.vectors_dir, self.golden_dir, self.coverage_path, self.repo = rev, vectors_dir, golden_dir, coverage, repo
        self.findings: list[str] = []

    def error(self, where: str, message: str) -> None:
        self.findings.append(f"{where}: {message}")

    def run(self) -> list[str]:
        schemas = Schemas(self.rev / "schema")
        vectors = load_vectors(self.vectors_dir, schemas)
        valid = []
        for vector in vectors:
            if vector.problem is not None:
                self.error(vector.id, vector.problem)
            else:
                valid.append(vector)
        self.check_sets(valid)
        rules = {rule["id"] for rule in json.loads((self.rev / "rules.json").read_text(encoding="utf-8"))}
        self.check_refs(valid, rules)
        self.check_coverage(valid, rules)
        self.check_unknown_member_vectors(valid, schemas)
        self.check_oracle_consistency(valid)
        self.check_golden_files(valid)
        self.vector_count = len(vectors)
        return self.findings

    def check_sets(self, vectors: list[Vector]) -> None:
        for name in REQUIRED_SETS:
            if not any(vector.id.startswith(name + "/") for vector in vectors):
                self.error(name, "set has no vectors")

    def check_refs(self, vectors: list[Vector], rules: set[str]) -> None:
        checker = lint.Lint(self.rev, self.repo)
        known = checker.known_refs()
        self.findings.extend(f"known refs: {line}" for line in checker.errors)
        for vector in vectors:
            for ref in vector.document["spec_refs"]:
                if ref.startswith("BC-"):
                    resolvable = ref in rules
                elif CITATION.match(ref):
                    resolvable = True
                else:
                    resolvable = ref in known
                if not resolvable:
                    self.error(vector.id, f"spec_ref {ref} does not resolve")

    def check_coverage(self, vectors: list[Vector], rules: set[str]) -> None:
        try:
            document = json.loads(self.coverage_path.read_text(encoding="utf-8"))
            entries = document["not_testable"]
        except (OSError, ValueError, KeyError) as error:
            self.error(str(self.coverage_path), f"unreadable coverage file: {error}")
            return
        referenced = {ref for vector in vectors for ref in vector.document["spec_refs"]}
        listed: set[str] = set()
        for entry in entries:
            rule = entry.get("rule") if isinstance(entry, dict) else None
            reason = entry.get("reason") if isinstance(entry, dict) else None
            if rule not in rules:
                self.error("coverage.json", f"not_testable names an unknown rule: {rule!r}")
            if not isinstance(reason, str) or not reason.strip():
                self.error("coverage.json", f"not_testable {rule}: reason is missing")
            if rule in listed:
                self.error("coverage.json", f"not_testable {rule}: listed twice")
            if rule in referenced:
                self.error("coverage.json", f"not_testable {rule}: covered by a vector, remove the entry")
            listed.add(rule)
        for rule in sorted(rules - referenced - listed):
            self.error("coverage", f"rule {rule} is in no vector spec_refs and not in coverage.json")

    def check_unknown_member_vectors(self, vectors: list[Vector], schemas: Schemas) -> None:
        """M-05: for each closed schema a vector fails because of an unknown member alone."""
        urn = f"urn:billium:spec:{self.rev.name}:"
        for name, pointer in sorted(lint.CLOSED):
            validator = self.closed_validator(schemas, urn, name, pointer)
            if validator is None:
                self.error(name, "closed schema not found in the revision")
                continue
            if not any(self.rejects_unknown_member(vector, validator, name) for vector in vectors):
                self.error(name, "no vector rejects an unknown member of this closed schema (M-05)")

    @staticmethod
    def closed_validator(schemas: Schemas, urn: str, name: str, pointer: str) -> Draft202012Validator | None:
        for uri, document in schemas.documents.items():
            node = document.get("properties", {}).get("schema", {}).get("const") if not pointer else None
            if pointer:
                if document.get("$defs", {}).get(pointer.rsplit("/", 1)[-1], {}).get("properties", {}).get("schema", {}).get("const") != name:
                    continue
            elif node != name:
                continue
            return Draft202012Validator({"$ref": uri + ("#" + pointer if pointer else "")}, registry=schemas.registry)
        return None

    @staticmethod
    def rejects_unknown_member(vector: Vector, validator: Draft202012Validator, name: str) -> bool:
        for step, want in zip(vector.document["script"], vector.document["expect"]):
            if step["op"] != "envelope.decode" or want != {"error": {"code": INVALID}}:
                continue
            try:
                instance = json.loads(step["args"]["json_text"])
            except (KeyError, ValueError):
                continue
            if not isinstance(instance, dict) or instance.get("schema") != name:
                continue
            errors = list(validator.iter_errors(instance))
            if errors and all(error.validator == "additionalProperties" for error in errors):
                return True
        return False

    def check_oracle_consistency(self, vectors: list[Vector]) -> None:
        controls = [vector for vector in vectors if vector.control]
        if not controls:
            self.error("control", "no control vector (control: must-fail)")
        for vector in vectors:
            for step, want in zip(vector.document["script"], vector.document["expect"]):
                ok = want.get("ok")
                json_text = step["args"].get("json_text")
                if not isinstance(ok, dict) or not isinstance(json_text, str):
                    continue
                for field, expected in ok.items():
                    if (step["op"], field) not in ORACLE_FIELDS:
                        continue
                    if isinstance(expected, str):
                        try:
                            actual = oracle_value(oracle, step["op"], field, json_text)
                        except ValueError as error:
                            self.error(vector.id, f"oracle cannot process json_text: {error}")
                            continue
                        if actual != expected:
                            self.error(vector.id, f"literal {field} differs from the oracle output")
                    elif vector.control and (ref := golden_ref(expected)) is not None:
                        self.check_control_golden(vector, step, field, json_text, ref)

    def check_control_golden(self, vector: Vector, step: dict[str, Any], field: str, json_text: str, ref: str) -> None:
        try:
            golden = read_golden(self.golden_dir, ref)
            actual = oracle_value(oracle, step["op"], field, json_text)
        except (VectorError, ValueError) as error:
            self.error(vector.id, str(error))
            return
        if golden == actual:
            self.error(vector.id, f"control golden {ref} equals the oracle output: the control would pass")

    def check_golden_files(self, vectors: list[Vector]) -> None:
        referenced: set[str] = set()
        for vector in vectors:
            for want in vector.document["expect"]:
                ok = want.get("ok")
                for value in ok.values() if isinstance(ok, dict) else []:
                    ref = golden_ref(value)
                    if ref is None:
                        continue
                    referenced.add(ref)
                    if not (self.golden_dir / ref).is_file():
                        self.error(vector.id, f"golden {ref} does not exist")
        if self.golden_dir.is_dir():
            for path in sorted(self.golden_dir.rglob("*")):
                rel = path.relative_to(self.golden_dir).as_posix()
                if path.is_file() and rel not in referenced:
                    self.error("golden", f"{rel} is not referenced by any vector")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--rev", type=Path, default=ROOT / "spec/revisions/2026-10")
    parser.add_argument("--vectors", type=Path, default=ROOT / "fixtures/vectors")
    parser.add_argument("--golden", type=Path, default=ROOT / "fixtures/golden")
    parser.add_argument("--coverage", type=Path, default=ROOT / "fixtures/coverage.json")
    parser.add_argument("--repo-root", type=Path, default=ROOT)
    args = parser.parse_args()

    checker = CorpusLint(args.rev.resolve(), args.vectors.resolve(), args.golden.resolve(), args.coverage.resolve(), args.repo_root.resolve())
    findings = checker.run()
    for line in findings:
        print(f"lint-vectors: {line}", file=sys.stderr)
    if findings:
        print(f"lint-vectors: {len(findings)} finding(s)", file=sys.stderr)
        return 1
    print(f"lint-vectors: {checker.vector_count} vectors OK")
    return 0


if __name__ == "__main__":
    sys.exit(main())
