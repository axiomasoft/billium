#!/usr/bin/env python3
"""Export the normative module registry spec/modules.json into the core package resources.

The registry is validated against spec/schema/module-descriptor.json and written in canonical form to
packages/core/resources/modules.json; the installed package reads nothing outside itself (D55).
Writes by default; --check compares without writing and exits 1 on drift.
"""
from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from export_common import ROOT, ExportError, write_or_check  # noqa: E402
from jsonschema import Draft202012Validator  # noqa: E402
from jsonschema.exceptions import SchemaError  # noqa: E402

TOOL = "export-modules"
SOURCE = ROOT / "spec/modules.json"
SCHEMA = ROOT / "spec/schema/module-descriptor.json"
DEFAULT_OUT = ROOT / "packages/core/resources/modules.json"


def load_registry(source: Path = SOURCE, schema: Path = SCHEMA) -> dict[str, object]:
    """The registry document, schema-valid; ExportError otherwise."""
    try:
        document = json.loads(source.read_text(encoding="utf-8"))
        definition = json.loads(schema.read_text(encoding="utf-8"))
        Draft202012Validator.check_schema(definition)
    except (OSError, ValueError, SchemaError) as error:
        raise ExportError(f"{error}") from error
    problems = sorted(
        Draft202012Validator(definition).iter_errors(document),
        key=lambda error: [str(part) for part in error.absolute_path],
    )
    if problems:
        first = problems[0]
        where = "/".join(str(part) for part in first.absolute_path) or "<root>"
        raise ExportError(f"{source.name} violates {schema.name} at {where}: {first.message}")
    return document


def render(document: dict[str, object]) -> str:
    return json.dumps(document, ensure_ascii=False, indent=2) + "\n"


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source", type=Path, default=SOURCE)
    parser.add_argument("--out", type=Path, default=DEFAULT_OUT, help="resource file")
    parser.add_argument("--check", action="store_true", help="compare only, never write")
    args = parser.parse_args()

    try:
        text = render(load_registry(args.source))
    except ExportError as error:
        print(f"{TOOL}: {error}", file=sys.stderr)
        return 1
    return write_or_check({args.out: text}, args.check, TOOL)


if __name__ == "__main__":
    sys.exit(main())
