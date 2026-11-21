#!/usr/bin/env python3
"""Lint a spec revision: schema conventions 1–8, rule documents, problem codes and currencies.

Usage: scripts/spec/lint.py [REV_DIR] [--repo-root DIR]
Offline: every $ref resolves inside the revision; exits 1 with one line per finding.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import re
import sys
from pathlib import Path
from typing import Any, Iterator

from jsonschema import Draft202012Validator
from jsonschema.exceptions import SchemaError
from referencing import Registry
from referencing.exceptions import Unresolvable
from referencing.jsonschema import DRAFT202012

from workspace import WorkspaceError, plans_root

ROOT = Path(__file__).resolve().parents[2]
DRAFT = "https://json-schema.org/draft/2020-12/schema"
SCHEMA_NAME = re.compile(r"^[a-z][a-z_]*(?:\.[a-z][a-z_]*)+\.v[1-9][0-9]*$")

# Conventions 4 and 5: (schema name, JSON pointer of its node in the schema file).
CLOSED = {
    ("payment.execute.v1", ""),
    ("payment.refund.v1", ""),
    ("payment.cancel.v1", ""),
    ("payment.find.v1", "/$defs/request"),
    ("obligation.prepare.v1", ""),
    ("occurrence.ensure.v1", ""),
    ("collection.authorize.v1", ""),
    ("usage.fact.v1", ""),
    ("payment.observation.v1", ""),
    ("receipt.find.v1", "/$defs/request"),
    ("billium.feed.open.v1", ""),
    ("billium.feed.read.v1", ""),
    ("billium.feed.bootstrap_request.v1", ""),
}
OPEN = {
    ("payment.outcome.v1", ""),
    ("payment.admission.v1", ""),
    ("payment.find.v1", "/$defs/response"),
    ("usage.receipt.v1", ""),
    ("billium.event.v1", ""),
    ("receipt.find.v1", "/$defs/response"),
    ("billium.capabilities.v1", ""),
    ("ledger.allocation_receipt.v1", ""),
    ("ledger.observation_custody.v1", ""),
    ("billium.command_receipt.v1", ""),
    ("ledger.obligation.v1", ""),
    ("ledger.invoice.v1", ""),
    ("ledger.allocations.v1", ""),
    ("ledger.refund_authorization.v1", ""),
}
NON_ENVELOPE_DIRS = {"common", "problem", "conformance"}
# D63: rating correction.applied is an internal event payload, not a public wire envelope.
NON_ENVELOPE_FILES = {"feed/defs.json", "rating/correction-applied.v1.json"}

NORMATIVE = re.compile(r"\b(MUST|SHALL|SHOULD)\b|\b(долж(ен|на|но|ны)|обязан(а|о|ы)?)\b", re.IGNORECASE)
AMOUNT_KEY = re.compile(r"^(amount|quantity|money|requested)$|Total$")

DOC_AREAS = {
    "01-money-wire.md": "MW",
    "02-idempotency-digest.md": "ID",
    "03-envelopes.md": "ENV",
    "04-errors.md": "ERR",
    "05-events-receipts.md": "EVT",
    "06-conformance.md": "CNF",
}
RULE_LINE = re.compile(r"^\[BC-([A-Z]+)-(\d{2})\] (.+) \(([^()]+)\)$")
REF = re.compile(r"^(REQ-[A-Z]+-\d{2}|INV-\d{2}|D\d+|M-\d{2}|SRC-\d{3})$")
BC_MENTION = re.compile(r"BC-[A-Z]+-\d{2}")
JSON_BLOCK = re.compile(r"^```json\n(.*?)^```", re.MULTILINE | re.DOTALL)
TABLE_ROW = re.compile(r"^\| `([a-z_.0-9]+)` \| `schema/([^`]+)` \|", re.MULTILINE)

CODE_KEYS = {"code", "category", "retryable", "sameKey", "receipt", "producer", "when", "clientAction", "basis"}
CODE = re.compile(r"^billium\.([a-z_]+)\.[a-z_]+$")
NONE_CATEGORIES = {"validation", "schema", "money", "auth", "transport", "lookup", "installation"}
CONTRACT_CODE_ROW = re.compile(r"^\| `(billium\.[a-z_.]+)` \|(.*)\|\s*$", re.MULTILINE)


class Lint:
    def __init__(self, rev: Path, repo: Path) -> None:
        self.rev = rev
        self.repo = repo
        self.plans = plans_root(repo)
        self.urn = f"urn:billium:spec:{rev.name}:"
        self.errors: list[str] = []
        self.schemas: dict[str, Any] = {}
        self.registry: Registry = Registry()

    def error(self, where: Path | str, message: str) -> None:
        where = where.relative_to(self.repo).as_posix() if isinstance(where, Path) and where.is_relative_to(self.repo) else where
        self.errors.append(f"{where}: {message}")

    # -- loading --------------------------------------------------------------------------------------------

    def load_json(self, path: Path) -> Any:
        try:
            return json.loads(path.read_text(encoding="utf-8"))
        except (OSError, ValueError) as error:
            self.error(path, f"invalid JSON: {error}")
            return None

    def load_schemas(self) -> None:
        schema_dir = self.rev / "schema"
        paths = sorted(schema_dir.rglob("*.json"))
        if not paths:
            self.error(schema_dir, "no schema files")
        resources = []
        for path in paths:
            document = self.load_json(path)
            if document is None:
                continue
            rel = path.relative_to(schema_dir).as_posix()
            if document.get("$schema") != DRAFT:
                self.error(path, f"$schema must be {DRAFT}")
            if document.get("$id") != self.urn + rel:
                self.error(path, f"$id must be {self.urn + rel}")
            try:
                Draft202012Validator.check_schema(document)
            except SchemaError as error:
                self.error(path, f"invalid JSON Schema: {error.message}")
                continue
            self.schemas[rel] = document
            resources.append((self.urn + rel, DRAFT202012.create_resource(document)))
        self.registry = Registry().with_resources(resources)

    # -- schema conventions ---------------------------------------------------------------------------------

    @staticmethod
    def walk(node: Any, pointer: str = "") -> Iterator[tuple[str, Any]]:
        yield pointer, node
        if isinstance(node, dict):
            for key, value in node.items():
                if key in ("enum", "const"):
                    continue
                yield from Lint.walk(value, f"{pointer}/{key}")
        elif isinstance(node, list):
            for index, value in enumerate(node):
                yield from Lint.walk(value, f"{pointer}/{index}")

    def check_refs(self) -> None:
        for rel, document in self.schemas.items():
            resolver = self.registry.resolver(base_uri=self.urn + rel)
            for pointer, node in self.walk(document):
                if isinstance(node, dict) and isinstance(node.get("$ref"), str):
                    try:
                        resolver.lookup(node["$ref"])
                    except Unresolvable:
                        self.error(f"schema/{rel}#{pointer}", f"unresolvable $ref {node['$ref']} (offline, convention 1)")

    def check_enums(self) -> None:
        seen: dict[tuple[str, ...], list[str]] = {}
        for rel, document in self.schemas.items():
            for pointer, node in self.walk(document):
                if isinstance(node, dict) and isinstance(node.get("enum"), list):
                    key = tuple(sorted(json.dumps(value, sort_keys=True) for value in node["enum"]))
                    seen.setdefault(key, []).append(f"schema/{rel}#{pointer}")
        for places in seen.values():
            if len(places) > 1:
                self.error(places[0], f"enumeration repeated in {', '.join(places[1:])}; declare it once in common/defs.json (convention 2)")

    def check_descriptions(self) -> None:
        for rel, document in self.schemas.items():
            for pointer, node in self.walk(document):
                if isinstance(node, dict) and isinstance(node.get("description"), str):
                    word = NORMATIVE.search(node["description"])
                    if word:
                        self.error(f"schema/{rel}#{pointer}", f"normative word '{word.group(0)}' in description (convention 3)")

    def envelope_nodes(self) -> dict[tuple[str, str], tuple[str, dict[str, Any]]]:
        nodes = {}
        for rel, document in self.schemas.items():
            for pointer, node in self.walk(document):
                if not isinstance(node, dict) or not isinstance(node.get("properties"), dict):
                    continue
                schema_prop = node["properties"].get("schema")
                if isinstance(schema_prop, dict) and "const" in schema_prop:
                    nodes[(schema_prop["const"], pointer)] = (rel, node)
        return nodes

    def check_envelopes(self, readme_set: dict[str, str]) -> None:
        nodes = self.envelope_nodes()
        files: dict[str, set[str]] = {}
        for (name, pointer), (rel, node) in nodes.items():
            if not isinstance(name, str) or not SCHEMA_NAME.match(name):
                self.error(f"schema/{rel}#{pointer}", f"schema const {name!r} is not <namespace>.<name>.v<N> (convention 7)")
            files.setdefault(rel, set()).add(name)
        for rel, document in self.schemas.items():
            if rel.split("/")[0] in NON_ENVELOPE_DIRS or rel in NON_ENVELOPE_FILES:
                continue
            if rel not in files:
                self.error(f"schema/{rel}", "envelope schema has no properties.schema.const (convention 7)")
            elif len(files[rel]) != 1:
                self.error(f"schema/{rel}", f"one envelope per file, found {sorted(files[rel])}")
        for name, pointer in sorted(CLOSED):
            found = nodes.get((name, pointer))
            if found is None:
                self.error(self.rev / "schema", f"incoming command {name} ({pointer or 'root'}) not found")
            elif found[1].get("additionalProperties") is not False:
                self.error(f"schema/{found[0]}#{pointer}", f"incoming command {name} needs additionalProperties: false (convention 4)")
        for name, pointer in sorted(OPEN):
            found = nodes.get((name, pointer))
            if found is None:
                self.error(self.rev / "schema", f"outcome/response/event {name} ({pointer or 'root'}) not found")
            elif found[1].get("additionalProperties", True) is False or found[1].get("unevaluatedProperties", True) is False:
                self.error(f"schema/{found[0]}#{pointer}", f"{name} stays open for additive optional fields (convention 5)")
        declared = {name: rel for rel, names in files.items() for name in names}
        if declared != readme_set:
            self.error(self.rev / "README.md", f"envelope table {sorted(readme_set.items())} != schema files {sorted(declared.items())}")

    def check_amounts(self) -> None:
        for rel, document in self.schemas.items():
            for pointer, node in self.walk(document):
                if not isinstance(node, dict):
                    continue
                types = node.get("type")
                types = types if isinstance(types, list) else [types]
                if "number" in types:
                    self.error(f"schema/{rel}#{pointer}", "JSON number is not allowed in the revision (convention 6)")
                properties = node.get("properties")
                if not isinstance(properties, dict):
                    continue
                for key, sub in properties.items():
                    if AMOUNT_KEY.search(key) and isinstance(sub, dict):
                        sub_types = sub.get("type")
                        sub_types = sub_types if isinstance(sub_types, list) else [sub_types]
                        if set(sub_types) - {None, "string", "object"}:
                            self.error(f"schema/{rel}#{pointer}/properties/{key}", "amounts and quantities are strings or MoneyWire (convention 6)")

    # -- examples (convention 8) ----------------------------------------------------------------------------

    def check_examples(self, readme_set: dict[str, str], docs: list[Path]) -> None:
        for doc in docs:
            if not doc.is_file():
                self.error(doc, "document missing")
                continue
            for index, block in enumerate(JSON_BLOCK.findall(doc.read_text(encoding="utf-8")), start=1):
                where = f"{doc.relative_to(self.repo).as_posix() if doc.is_relative_to(self.repo) else doc} json example {index}"
                try:
                    example = json.loads(block)
                except ValueError as error:
                    self.error(where, f"invalid JSON: {error} (convention 8)")
                    continue
                name = example.get("schema") if isinstance(example, dict) else None
                if name not in readme_set:
                    self.error(where, f"example schema {name!r} is not an envelope of the revision (convention 8)")
                    continue
                rel = readme_set[name]
                if rel not in self.schemas:
                    continue
                validator = Draft202012Validator(self.schemas[rel], registry=self.registry)
                try:
                    for failure in validator.iter_errors(example):
                        self.error(where, f"does not validate against {rel}: {failure.message}")
                except Unresolvable as error:
                    self.error(where, f"cannot validate against {rel}: {error}")

    # -- rule documents -------------------------------------------------------------------------------------

    def known_refs(self) -> set[str]:
        known: set[str] = set()
        requirements = self.repo / "docs/requirements.md"
        if requirements.is_file():
            text = requirements.read_text(encoding="utf-8")
            known |= set(re.findall(r"^\| ((?:REQ-[A-Z]+|INV)-\d{2}) \|", text, re.MULTILINE))
            known |= set(re.findall(r"\bSRC-\d{3}\b", text))
        else:
            self.error(requirements, "missing; REQ/INV references cannot be resolved")
        for decision in self.plans.glob("**/decisions/D*.json"):
            known.add(decision.stem)
        for methods in self.plans.glob("**/artifacts/P1-methods/methods.json"):
            document = self.load_json(methods)
            if isinstance(document, dict):
                known |= {practice["id"] for practice in document.get("practices", [])}
        return known

    def check_ref_list(self, where: str, refs: list[str], known: set[str]) -> None:
        if not refs:
            self.error(where, "no source reference (D7)")
        for ref in refs:
            if not REF.match(ref):
                self.error(where, f"{ref!r} is not a REQ/INV/D#/M/SRC reference")
            elif ref not in known:
                self.error(where, f"reference {ref} does not resolve")

    def check_rule_docs(self, known: set[str]) -> None:
        defined: dict[str, str] = {}
        mentions: list[tuple[str, str]] = []
        for name, area in DOC_AREAS.items():
            doc = self.rev / name
            if not doc.is_file():
                self.error(doc, "rule document missing")
                continue
            count = 0
            for number, line in enumerate(doc.read_text(encoding="utf-8").splitlines(), start=1):
                where = f"{doc.relative_to(self.repo).as_posix() if doc.is_relative_to(self.repo) else doc}:{number}"
                if line.startswith("[BC-"):
                    match = RULE_LINE.match(line)
                    if not match:
                        self.error(where, "rule line must be '[BC-<AREA>-<NN>] text. (REF, …)'")
                        continue
                    rule_id = f"BC-{match.group(1)}-{match.group(2)}"
                    if match.group(1) != area:
                        self.error(where, f"{rule_id} belongs to area {area} in {name}")
                    if rule_id in defined:
                        self.error(where, f"duplicate rule {rule_id} (first at {defined[rule_id]})")
                    defined[rule_id] = where
                    self.check_ref_list(where, [ref.strip() for ref in match.group(4).split(",")], known)
                    mentions += [(where, mention) for mention in BC_MENTION.findall(match.group(3))]
                    count += 1
                elif "[BC-" in line:
                    self.error(where, "rule marker must start the line")
                else:
                    mentions += [(where, mention) for mention in BC_MENTION.findall(line)]
            if count == 0:
                self.error(doc, "no rule lines")
        for where, mention in mentions:
            if mention not in defined:
                self.error(where, f"mentions undefined rule {mention}")

    # -- registries -----------------------------------------------------------------------------------------

    def check_codes(self, known: set[str]) -> None:
        path = self.rev / "problem-codes.json"
        codes = self.load_json(path)
        if not isinstance(codes, list) or not codes:
            self.error(path, "must be a non-empty array")
            return
        seen: list[str] = []
        for index, entry in enumerate(codes):
            where = f"{path.relative_to(self.repo).as_posix() if path.is_relative_to(self.repo) else path}[{index}]"
            if not isinstance(entry, dict) or set(entry) != CODE_KEYS:
                self.error(where, f"entry keys must be {sorted(CODE_KEYS)}")
                continue
            code = entry["code"]
            match = CODE.match(code) if isinstance(code, str) else None
            if not match:
                self.error(where, f"code {code!r} is not billium.<category>.<slug>")
                continue
            if code in seen:
                self.error(where, f"code {code} declared twice (M-46)")
            seen.append(code)
            if entry["category"] != match.group(1):
                self.error(where, f"category {entry['category']!r} != second segment of {code} (BC-ERR-03)")
            for key in ("retryable", "sameKey"):
                if not isinstance(entry[key], bool):
                    self.error(where, f"{key} must be boolean")
            if entry["receipt"] not in ("stored", "none"):
                self.error(where, "receipt must be stored or none")
            if (match.group(1) in NONE_CATEGORIES or code == "billium.idempotency.in_flight") and entry["receipt"] != "none":
                self.error(where, f"{code} keeps no receipt (M-45, D31)")
            for key in ("producer", "when", "clientAction"):
                if not isinstance(entry[key], str) or not entry[key].strip():
                    self.error(where, f"{key} must be a non-empty string")
            if not isinstance(entry["basis"], list):
                self.error(where, "basis must be an array")
            else:
                self.check_ref_list(where, entry["basis"], known)

        defs = self.schemas.get("common/defs.json", {}).get("$defs", {})
        if defs.get("problemCode", {}).get("enum") != seen:
            self.error(self.rev / "schema/common/defs.json", "$defs/problemCode enum differs from problem-codes.json (BC-ERR-02)")
        categories = sorted({code.split(".")[1] for code in seen})
        if defs.get("problemCategory", {}).get("enum") != categories:
            self.error(self.rev / "schema/common/defs.json", f"$defs/problemCategory enum must be {categories}")

        contracts = self.repo / "docs/contracts/billium-contracts-v1.md"
        if contracts.is_file():
            rows = {code: cells for code, cells in CONTRACT_CODE_ROW.findall(contracts.read_text(encoding="utf-8"))}
            if set(rows) != set(seen):
                self.error(path, f"codes differ from docs/contracts § Ошибки: {sorted(set(rows) ^ set(seen))}")
            by_code = {entry["code"]: entry for entry in codes if isinstance(entry, dict) and "code" in entry}
            for code, cells in rows.items():
                columns = [cell.strip() for cell in cells.split("|")]
                receipt = "stored" if len(columns) > 3 and columns[3].startswith("stored") else "none"
                if code in by_code and by_code[code].get("receipt") != receipt:
                    self.error(path, f"{code}: receipt {by_code[code].get('receipt')} != docs/contracts column {columns[3]!r}")

    def check_currencies(self) -> None:
        path = self.rev / "currencies.json"
        document = self.load_json(path)
        if not isinstance(document, dict):
            self.error(path, "must be an object {source, currencies}")
            return
        source = document.get("source", {})
        if not (isinstance(source, dict) and isinstance(source.get("url"), str) and source["url"].startswith("https://")):
            self.error(path, "source.url must be an https URL")
        if not re.match(r"^\d{4}-\d{2}-\d{2}$", str(source.get("published", ""))):
            self.error(path, "source.published must be the Pblshd date YYYY-MM-DD")
        xml = self.rev / "sources/list-one.xml"
        if not xml.is_file():
            self.error(xml, "ISO 4217 source XML missing")
        elif source.get("sha256") != hashlib.sha256(xml.read_bytes()).hexdigest():
            self.error(path, "source.sha256 does not match sources/list-one.xml")
        rows = document.get("currencies")
        if not isinstance(rows, list) or not rows:
            self.error(path, "currencies must be a non-empty array")
            return
        codes = []
        for row in rows:
            ok = (
                isinstance(row, dict)
                and set(row) == {"code", "numeric", "scale"}
                and re.match(r"^[A-Z]{3}$", str(row["code"]))
                and re.match(r"^\d{3}$", str(row["numeric"]))
                and isinstance(row["scale"], int)
                and not isinstance(row["scale"], bool)
                and 0 <= row["scale"] <= 9
            )
            if not ok:
                self.error(path, f"invalid currency row {row!r}")
                continue
            codes.append(row["code"])
        if codes != sorted(set(codes)):
            self.error(path, "currency codes must be unique and sorted")

    # -- entry ----------------------------------------------------------------------------------------------

    def readme_set(self) -> dict[str, str]:
        readme = self.rev / "README.md"
        if not readme.is_file():
            self.error(readme, "missing")
            return {}
        return dict(TABLE_ROW.findall(readme.read_text(encoding="utf-8")))

    def run(self, contracts_doc: Path) -> list[str]:
        if not self.rev.is_dir():
            self.error(str(self.rev), "revision directory missing")
            return self.errors
        for path in sorted(self.rev.rglob("*.json")):
            if not path.is_relative_to(self.rev / "schema"):
                self.load_json(path)
        self.load_schemas()
        self.check_refs()
        self.check_enums()
        self.check_descriptions()
        self.check_amounts()
        envelopes = self.readme_set()
        self.check_envelopes(envelopes)
        self.check_examples(envelopes, [*sorted(self.rev.glob("*.md")), contracts_doc])
        known = self.known_refs()
        self.check_rule_docs(known)
        self.check_codes(known)
        self.check_currencies()
        return self.errors


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("rev", nargs="?", type=Path, default=ROOT / "spec/revisions/2026-10")
    parser.add_argument("--repo-root", type=Path, default=ROOT)
    parser.add_argument("--contracts-doc", type=Path, help="default: <repo>/docs/contracts/billium-contracts-v1.md")
    args = parser.parse_args()

    repo = args.repo_root.resolve()
    rev = (args.rev if args.rev.is_absolute() else Path.cwd() / args.rev).resolve()
    contracts = args.contracts_doc or repo / "docs/contracts/billium-contracts-v1.md"
    try:
        errors = Lint(rev, repo).run(contracts.resolve())
    except WorkspaceError as error:
        print(f"lint: {error}", file=sys.stderr)
        return 1
    for line in errors:
        print(f"lint: {line}", file=sys.stderr)
    if errors:
        print(f"lint: {len(errors)} finding(s)", file=sys.stderr)
        return 1
    print(f"lint: {rev.name} OK")
    return 0


if __name__ == "__main__":
    sys.exit(main())
