"""Run-level gates: baseline, lockstep and the golden check by the JCS oracle."""
from __future__ import annotations

import hashlib
import json
import re
import sys
from pathlib import Path
from typing import Any

from vectors import Vector, golden_ref, read_golden, VectorError

BASELINE_FIELDS = ("id", "reason", "owner", "since", "issue", "phase")
PHASE = re.compile(r"^P[0-9]+$")


def load_baseline(path: Path, phase: str | None) -> tuple[dict[str, dict[str, Any]], list[str]]:
    """Baseline entries by vector id and the violations found in the file itself."""
    if not path.exists():
        return {}, []
    violations: list[str] = []
    try:
        document = json.loads(path.read_text(encoding="utf-8"))
        entries = document["entries"]
        if not isinstance(entries, list):
            raise TypeError("entries is not a list")
    except (ValueError, KeyError, TypeError) as error:
        return {}, [f"baseline {path.name} is unreadable: {error}"]
    result: dict[str, dict[str, Any]] = {}
    for entry in entries:
        entry_id = entry.get("id") if isinstance(entry, dict) else None
        if not isinstance(entry_id, str):
            violations.append(f"baseline {path.name}: entry without id")
            continue
        for name in BASELINE_FIELDS:
            if not isinstance(entry.get(name), str) or not entry[name].strip():
                violations.append(f"baseline entry {entry_id}: field {name!r} is missing or empty")
        if isinstance(entry.get("phase"), str) and not PHASE.match(entry["phase"]):
            violations.append(f"baseline entry {entry_id}: phase {entry['phase']!r} is not P<n>")
        elif phase is not None and isinstance(entry.get("phase"), str) and int(entry["phase"][1:]) <= int(phase[1:]):
            violations.append(f"baseline entry {entry_id}: phase {entry['phase']} is not after the current {phase}")
        if entry_id in result:
            violations.append(f"baseline entry {entry_id}: duplicate")
        result[entry_id] = entry
    return result, violations


def check_baseline(non_pass: set[str], baseline: dict[str, dict[str, Any]]) -> list[str]:
    violations = []
    for vector_id in sorted(non_pass - baseline.keys()):
        violations.append(f"not pass and not in baseline: {vector_id}")
    for vector_id in sorted(baseline.keys() - non_pass):
        violations.append(f"stale baseline entry (vector passed or disappeared): {vector_id}")
    return violations


def pubspec_spec_rev(path: Path) -> str | None:
    """`billium.spec_rev` of a pubspec.yaml; pubspec is block YAML, a tiny reader avoids a dependency."""
    in_block = False
    for line in path.read_text(encoding="utf-8").splitlines():
        if re.match(r"^billium:\s*$", line):
            in_block = True
        elif in_block and re.match(r"^\S", line):
            in_block = False
        elif in_block:
            match = re.match(r"^\s+spec_rev:\s*[\"']?([^\"'\s#]+)", line)
            if match:
                return match.group(1)
    return None


def lockstep_sources(root: Path, spec_dir: Path) -> tuple[dict[str, str | None], list[str]]:
    sources: dict[str, str | None] = {}
    problems: list[str] = []
    readers = {
        "revision.lock.json": (spec_dir / "revision.lock.json", lambda p: json.loads(p.read_text("utf-8"))["revision"]),
        "packages/contracts/composer.json": (
            root / "packages/contracts/composer.json",
            lambda p: json.loads(p.read_text("utf-8"))["extra"]["billium"]["spec_rev"],
        ),
        "packages/contracts-dart/pubspec.yaml": (root / "packages/contracts-dart/pubspec.yaml", pubspec_spec_rev),
    }
    for name, (path, read) in readers.items():
        try:
            sources[name] = read(path)
        except (OSError, ValueError, KeyError, TypeError) as error:
            sources[name] = None
            problems.append(f"lockstep: cannot read spec_rev of {name}: {error}")
    return sources, problems


def check_lockstep(sources: dict[str, str | None], ready_rev: str | None) -> list[str]:
    values = dict(sources)
    if ready_rev is not None:
        values["adapter ready.spec_rev"] = ready_rev
    if None not in values.values() and len(set(values.values())) == 1:
        return []
    listing = ", ".join(f"{name}={value}" for name, value in values.items())
    return [f"lockstep mismatch: {listing}"]


# -- golden check by the oracle ---------------------------------------------------------------------------

ORACLE_FIELDS = {
    ("canon.canonicalize", "text"),
    ("envelope.roundtrip", "text"),
    ("canon.digest", "digest"),
    ("envelope.decode", "digest"),
}


def oracle_value(oracle: Any, op: str, field: str, json_text: str) -> str:
    if field == "text":
        return oracle.canonicalize(json_text).decode("utf-8")
    oracle.canonicalize(json_text)  # rejects duplicate keys and non-finite numbers before the digest form is built
    document = json.loads(json_text)
    if isinstance(document, dict):
        document.pop("digest", None)
    canonical = oracle.canonicalize(json.dumps(document, ensure_ascii=False))
    return "sha256:" + hashlib.sha256(canonical).hexdigest()


def check_golden(vectors: list[Vector], golden_root: Path, oracle_dir: Path) -> tuple[int, list[str]]:
    """Every golden under canon/ must be reproduced byte for byte by the oracle; returns (checked, findings)."""
    sys.path.insert(0, str(oracle_dir))
    try:
        import oracle  # type: ignore[import-not-found]
    finally:
        sys.path.remove(str(oracle_dir))

    findings: list[str] = []
    referenced: set[str] = set()
    checked = 0
    for vector in vectors:
        if vector.problem is not None:
            findings.append(f"{vector.id}: {vector.problem}")
            continue
        for step, want in zip(vector.document["script"], vector.document["expect"]):
            ok = want.get("ok")
            if not isinstance(ok, dict):
                continue
            for name, value in ok.items():
                ref = golden_ref(value)
                if ref is None or not ref.startswith("canon/"):
                    continue
                referenced.add(ref)
                if vector.control:
                    continue
                if (step["op"], name) not in ORACLE_FIELDS or not isinstance(step["args"].get("json_text"), str):
                    findings.append(f"{vector.id}: golden {ref} of {step['op']}.{name} cannot be derived by the oracle")
                    continue
                try:
                    expected = read_golden(golden_root, ref)
                    actual = oracle_value(oracle, step["op"], name, step["args"]["json_text"])
                except (VectorError, ValueError) as error:
                    findings.append(f"{vector.id}: {error}")
                    continue
                checked += 1
                if actual != expected:
                    findings.append(f"{vector.id}: golden {ref} differs from the oracle output")
    canon_root = golden_root / "canon"
    if canon_root.is_dir():
        for path in sorted(canon_root.rglob("*")):
            rel = path.relative_to(golden_root).as_posix()
            if path.is_file() and rel not in referenced:
                findings.append(f"golden {rel} is not referenced by any vector")
    if checked == 0:
        findings.append("no golden under canon/ was checked: the golden check has no subject")
    return checked, findings
