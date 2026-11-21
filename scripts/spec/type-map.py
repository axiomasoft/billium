#!/usr/bin/env python3
"""Maintain spec/type-map.json: the schema -> PHP class -> Dart class map and check it against the code.

The map is written by hand for the non-envelope types and derived for the envelopes; --check verifies that
every mapped PHP class exists (reflection through `php`), that the set of mapped PHP types equals the public
types of packages/contracts/src outside Internal, and that the Dart names equal the exports of
packages/contracts-dart/lib/billium_contracts.dart. Exits 1 on any difference.
"""
from __future__ import annotations

import argparse
import json
import re
import subprocess
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from export_common import ENVELOPES  # noqa: E402

ROOT = Path(__file__).resolve().parents[2]
MAP = ROOT / "spec/type-map.json"
PHP_SRC = ROOT / "packages/contracts/src"
PHP_NS = "Billium\\Contracts\\"
DART_LIB = ROOT / "packages/contracts-dart/lib"

# (php path under src without .php or None, dart name or None); category = first php namespace segment
PLAIN = (
    ("Data/Money", "Money"),
    ("Data/Currency", "Currency"),
    ("Data/Envelope", "Envelope"),
    ("Data/Problem", "Problem"),
    ("Enums/ProblemCode", "ProblemCode"),
    ("Enums/RoundingMode", "RoundingMode"),
    ("Contracts/Codec", "Codec"),
    ("Contracts/MoneyMath", "MoneyMath"),
    ("Contracts/MoneyMathFactory", None),
    ("Contracts/SpecRevision", None),
    ("Errors/BilliumErrorCode", None),
    ("Errors/BilliumProblemDefinitions", None),
    ("Exceptions/ProblemException", "ProblemException"),
    ("Attributes/Api", None),
    ("Attributes/Spi", None),
    ("Attributes/SpiKind", None),
)


def build() -> list[dict[str, object]]:
    entries: list[dict[str, object]] = []
    for class_name, schema, _source, variant in ENVELOPES:
        entries.append(
            {
                "schema": schema,
                "variant": variant,
                "php": f"{PHP_NS}Data\\Envelopes\\{class_name}",
                "dart": class_name,
                "category": "Data",
            }
        )
    for path, dart in PLAIN:
        namespace = path.split("/")[0]
        entries.append(
            {
                "schema": None,
                "variant": None,
                "php": PHP_NS + path.replace("/", "\\"),
                "dart": dart,
                "category": namespace,
            }
        )
    return entries


def render(entries: list[dict[str, object]]) -> str:
    return json.dumps(entries, ensure_ascii=False, indent=2) + "\n"


def php_public_types() -> set[str]:
    types = set()
    for file in PHP_SRC.rglob("*.php"):
        relative = file.relative_to(PHP_SRC)
        if relative.parts[0] == "Internal":
            continue
        types.add(PHP_NS + "\\".join(relative.with_suffix("").parts))
    return types


def php_existing(names: list[str]) -> set[str]:
    code = (
        "require 'vendor/autoload.php';"
        "$found = [];"
        "foreach (json_decode($argv[1], true) as $name) {"
        "  if (class_exists($name) || interface_exists($name) || enum_exists($name)) { $found[] = (new ReflectionClass($name))->getName(); }"
        "}"
        "echo json_encode($found);"
    )
    result = subprocess.run(
        ["php", "-r", code, json.dumps(names)], cwd=ROOT, capture_output=True, text=True, check=False
    )
    if result.returncode != 0:
        raise RuntimeError(f"php reflection failed: {result.stderr.strip() or result.stdout.strip()}")
    return set(json.loads(result.stdout))


def dart_exports() -> set[str]:
    names: set[str] = set()
    library = (DART_LIB / "billium_contracts.dart").read_text(encoding="utf-8")
    for match in re.finditer(r"export\s+'([^']+)'(?:\s+(show|hide)\s+([^;]+))?;", library):
        path, mode, listed = match.groups()
        if mode == "show":
            names.update(name.strip() for name in listed.split(",") if name.strip()[:1].isupper())
            continue
        hidden = {name.strip() for name in listed.split(",")} if mode == "hide" else set()
        text = (DART_LIB / path).read_text(encoding="utf-8")
        declared = re.findall(r"^(?:abstract |final |base |sealed |interface )*(?:class|enum|mixin|typedef) (\w+)", text, re.M)
        names.update(name for name in declared if name not in hidden and not name.startswith("_"))
    return names


def check(entries: list[dict[str, object]]) -> list[str]:
    errors = []
    php = [str(e["php"]) for e in entries]
    if len(set(php)) != len(php):
        errors.append("type-map: duplicate php class")
    missing = php_existing(php)
    errors += [f"type-map: PHP class does not exist: {name}" for name in php if name not in missing]
    errors += [f"type-map: public PHP type without entry: {name}" for name in sorted(php_public_types() - set(php))]
    dart = {str(e["dart"]) for e in entries if e["dart"]}
    exports = dart_exports()
    errors += [f"type-map: Dart type is not exported: {name}" for name in sorted(dart - exports)]
    errors += [f"type-map: exported Dart type without entry: {name}" for name in sorted(exports - dart)]
    return errors


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--check", action="store_true", help="compare only, never write")
    args = parser.parse_args()

    entries = build()
    text = render(entries)
    if args.check:
        errors = []
        if not MAP.is_file() or MAP.read_text(encoding="utf-8") != text:
            errors.append("type-map: spec/type-map.json is stale; run scripts/spec/type-map.py")
        try:
            errors += check(entries)
        except (OSError, RuntimeError) as error:
            errors.append(f"type-map: {error}")
        for error in errors:
            print(error, file=sys.stderr)
        return 1 if errors else 0
    MAP.write_text(text, encoding="utf-8")
    print("type-map: wrote spec/type-map.json")
    return 0


if __name__ == "__main__":
    sys.exit(main())
