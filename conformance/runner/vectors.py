"""Vectors: loading, golden resolution and comparison of adapter outputs with `expect`."""
from __future__ import annotations

import json
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any

from protocol import Schemas, first_error


class VectorError(Exception):
    """A fixture defect (invalid vector, missing or malformed golden); the vector is an `error`."""


@dataclass
class Vector:
    id: str
    path: Path
    document: dict[str, Any] = field(default_factory=dict)
    problem: str | None = None

    @property
    def control(self) -> bool:
        return self.document.get("control") == "must-fail"

    @property
    def profiles(self) -> list[str]:
        return self.document.get("profiles", [])


def load_vectors(root: Path, schemas: Schemas) -> list[Vector]:
    vectors = []
    for path in sorted(root.rglob("*.json")):
        vector_id = path.relative_to(root).with_suffix("").as_posix()
        vector = Vector(id=vector_id, path=path)
        try:
            document = json.loads(path.read_text(encoding="utf-8"))
        except (ValueError, OSError) as error:
            vector.problem = f"vector file is not readable JSON: {error}"
        else:
            problem = first_error(schemas.vector, document)
            if problem is not None:
                vector.problem = f"vector violates vector.json: {problem}"
            elif document["id"] != vector_id:
                vector.problem = f"id {document['id']!r} differs from path {vector_id!r}"
            elif len(document["expect"]) != len(document["script"]):
                vector.problem = "expect length differs from script length"
            else:
                vector.document = document
        vectors.append(vector)
    return vectors


def read_golden(golden_root: Path, rel: str) -> str:
    """Golden text: UTF-8 without BOM, exactly one trailing LF, which comparison drops (M-07)."""
    path = golden_root / rel
    try:
        raw = path.read_bytes()
    except OSError as error:
        raise VectorError(f"golden {rel!r} is not readable: {error}") from error
    if raw.startswith(b"\xef\xbb\xbf"):
        raise VectorError(f"golden {rel!r} starts with a BOM")
    if not raw.endswith(b"\n") or raw.endswith(b"\n\n"):
        raise VectorError(f"golden {rel!r} must end with exactly one LF")
    try:
        return raw[:-1].decode("utf-8")
    except UnicodeDecodeError as error:
        raise VectorError(f"golden {rel!r} is not UTF-8") from error


def golden_value(golden_root: Path, rel: str) -> Any:
    """Golden text; a `.json` golden is parsed and compared as JSON (e.g. `errors/registry.json`)."""
    text = read_golden(golden_root, rel)
    if not rel.endswith(".json"):
        return text
    try:
        return json.loads(text)
    except ValueError as error:
        raise VectorError(f"golden {rel!r} is not JSON: {error}") from error


def golden_ref(value: Any) -> str | None:
    if isinstance(value, dict) and set(value) == {"golden"} and isinstance(value["golden"], str):
        return value["golden"]
    return None


def resolve_golden(expect: list[dict[str, Any]], golden_root: Path) -> list[dict[str, Any]]:
    """Replace `{"golden": path}` values of top-level `expect.ok` fields by the golden value."""
    resolved = []
    for output in expect:
        ok = output.get("ok")
        if isinstance(ok, dict):
            ok = {
                key: golden_value(golden_root, ref) if (ref := golden_ref(value)) is not None else value
                for key, value in ok.items()
            }
            output = {"ok": ok}
        resolved.append(output)
    return resolved


def same(expected: Any, actual: Any) -> bool:
    """JSON equality without Python's True == 1 and 1 == 1.0 coincidences."""
    if isinstance(expected, bool) or isinstance(actual, bool):
        return isinstance(expected, bool) and isinstance(actual, bool) and expected == actual
    if isinstance(expected, dict):
        return (
            isinstance(actual, dict)
            and expected.keys() == actual.keys()
            and all(same(value, actual[key]) for key, value in expected.items())
        )
    if isinstance(expected, list):
        return isinstance(actual, list) and len(expected) == len(actual) and all(
            same(left, right) for left, right in zip(expected, actual)
        )
    if isinstance(expected, int) != isinstance(actual, int):
        return False
    return type(expected) is type(actual) and expected == actual


def compare(expect: list[dict[str, Any]], outputs: list[dict[str, Any]]) -> str | None:
    """First difference between resolved `expect` and adapter outputs, or None when they match."""
    if len(outputs) != len(expect):
        return f"outputs length {len(outputs)} differs from expect length {len(expect)}"
    for index, (want, got) in enumerate(zip(expect, outputs)):
        if "error" in want:
            if "error" not in got:
                return f"step {index}: expected error {want['error']['code']}, got ok"
            if got["error"]["code"] != want["error"]["code"]:
                return f"step {index}: expected error {want['error']['code']}, got {got['error']['code']}"
            continue
        if "ok" not in got:
            return f"step {index}: expected ok, got error {got['error']['code']}"
        wanted, actual = want["ok"], got["ok"]
        if not isinstance(wanted, dict):
            if not same(wanted, actual):
                return f"step {index}: ok differs: expected {_short(wanted)}, got {_short(actual)}"
            continue
        if not isinstance(actual, dict):
            return f"step {index}: expected ok object, got {_short(actual)}"
        for key, value in wanted.items():
            if key not in actual:
                return f"step {index}: ok.{key} is missing"
            if not same(value, actual[key]):
                return f"step {index}: ok.{key} expected {_short(value)}, got {_short(actual[key])}"
    return None


def _short(value: Any) -> str:
    text = json.dumps(value, ensure_ascii=False)
    return text if len(text) <= 160 else text[:157] + "..."
