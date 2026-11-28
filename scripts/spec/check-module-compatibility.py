#!/usr/bin/env python3
"""Static schema-module compatibility gate (D55, D63).

Checks spec/modules.json against the repository without a database: schema, module DAG, version and
schema ranges, ports, compositions, owned tables, config keys, migration identities, event/command
declarations, packaged resource and registered identities. Exits 1 and lists every violation.
A missing tool or file is a violation, never a skip.
"""
from __future__ import annotations

import argparse
import hashlib
import importlib.util
import json
import re
import subprocess
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from export_common import ROOT, ExportError  # noqa: E402

TOOL = "check-module-compatibility"
SOURCE = ROOT / "spec/modules.json"
RESOURCE = ROOT / "packages/core/resources/modules.json"
CORE = ROOT / "packages/core"
CONFIG = CORE / "config/billium.php"
FINDING_ENUM = CORE / "src/Enums/Modules/CompatibilityFindingCode.php"
REVISION = ROOT / "spec/revisions/2026-10"
SNAPSHOTS = ROOT / "spec/identities/snapshots"

BASE_BLOCKERS = {
    "pending_operations",
    "unknown_effects",
    "feed_gaps",
    "unexported_facts",
    "outbox_inbox_gaps",
    "historical_reader_missing",
    "dependent_module_active",
}
BASE_KEEPS = {"receipt_replay", "historical_read"}
SEMVER = re.compile(r"^(\d+)\.(\d+)\.(\d+)$")


def _exporter():
    spec = importlib.util.spec_from_file_location("export_modules", Path(__file__).with_name("export-modules.py"))
    assert spec is not None and spec.loader is not None
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def version(text: str) -> tuple[int, int, int]:
    match = SEMVER.match(text)
    if match is None:
        raise ValueError(f"not a semantic version: {text}")
    return int(match[1]), int(match[2]), int(match[3])


def in_range(value: str, api_range: dict[str, str]) -> bool:
    return version(api_range["min"]) <= version(value) < version(api_range["maxExclusive"])


def digest_of(node: object) -> str:
    text = json.dumps(node, sort_keys=True, separators=(",", ":"), ensure_ascii=False)
    return "sha256:" + hashlib.sha256(text.encode("utf-8")).hexdigest()


def event_key(module_event: dict[str, str]) -> tuple[str, str, str]:
    return module_event["eventContractRevision"], module_event["envelopeSchema"], module_event["eventType"]


def registry_errors(document: dict, v1_event_types: set[str], envelopes: set[str]) -> list[str]:
    """Violations that need only the registry document and the frozen v1 identities."""
    errors: list[str] = []
    modules: list[dict] = document["modules"]
    ids = [module["moduleId"] for module in modules]
    by_id = {module["moduleId"]: module for module in modules}
    core_version = document["coreApiVersion"]
    retention = {policy["id"] for policy in document["retentionPolicies"]}
    ports = {port["id"]: port["providedBy"] for port in document["ports"]}

    def duplicates(label: str, values: list[str]) -> None:
        for value in sorted({v for v in values if values.count(v) > 1}):
            errors.append(f"{label} {value!r} is declared more than once")

    duplicates("moduleId", ids)
    duplicates("schemaNamespace", [m["schemaNamespace"] for m in modules])
    duplicates("migrationStream", [m["migrationStream"] for m in modules])
    duplicates("ownedTable", [t for m in modules for t in m["ownedTables"]])
    duplicates("configKey", [k for m in modules for k in m["configKeys"]])
    duplicates("port", list(p["id"] for p in document["ports"]))
    duplicates("composition", [c["id"] for c in document["compositions"]])
    duplicates("findingCode", document["findingCodes"])

    for port, provider in ports.items():
        if provider != "host" and provider not in by_id:
            errors.append(f"port {port} is provided by unknown module {provider}")

    for module in modules:
        name = module["moduleId"]
        if module["migrationStream"] != name:
            errors.append(f"{name}: migrationStream must equal moduleId (one stream directory per module)")
        if module["schemaNamespace"] != f"billium.{name}":
            errors.append(f"{name}: schemaNamespace must be billium.{name}")
        if not in_range(core_version, module["coreApiRange"]):
            errors.append(f"{name}: coreApiRange excludes core API {core_version}")
        read, write = module["supportedSchemaReadRange"], module["supportedSchemaWriteRange"]
        current = module["currentSchemaVersion"]
        if read["min"] > read["max"] or write["min"] > write["max"]:
            errors.append(f"{name}: schema range is inverted")
        if not (read["min"] <= write["min"] and write["max"] <= read["max"]):
            errors.append(f"{name}: write range must lie inside the read range")
        if not write["min"] <= current <= write["max"]:
            errors.append(f"{name}: currentSchemaVersion {current} is outside the write range")
        versions = [m["version"] for m in module["migrationIdentities"]]
        if versions != sorted(set(versions)) or (versions and versions != list(range(1, len(versions) + 1))):
            errors.append(f"{name}: migration versions must be 1..n in order")
        if current != (versions[-1] if versions else 0):
            errors.append(f"{name}: currentSchemaVersion {current} does not match the last migration identity")
        duplicates(f"{name}: migration identity", [m["id"] for m in module["migrationIdentities"]])
        for dependency in module["requiresModules"]:
            target = by_id.get(dependency["moduleId"])
            if target is None:
                errors.append(f"{name}: requires unknown module {dependency['moduleId']}")
            elif not in_range(target["moduleApiVersion"], dependency["apiRange"]):
                errors.append(f"{name}: apiRange of {target['moduleId']} excludes {target['moduleApiVersion']}")
        for port in module["requiresPorts"] + module["optionalPorts"]:
            if port not in ports:
                errors.append(f"{name}: uses unknown port {port}")
        if set(module["requiresPorts"]) & set(module["optionalPorts"]):
            errors.append(f"{name}: a port is both required and optional")
        for schema in module["emits"] + module["consumes"]:
            if schema not in envelopes:
                errors.append(f"{name}: wire schema {schema} is not a registered envelope")
        if module["retentionClass"] not in retention:
            errors.append(f"{name}: unknown retentionClass {module['retentionClass']}")
        if not BASE_BLOCKERS <= set(module["retirementPolicy"]["blockers"]):
            errors.append(f"{name}: retirementPolicy lacks base blockers {sorted(BASE_BLOCKERS - set(module['retirementPolicy']['blockers']))}")
        if not BASE_KEEPS <= set(module["disablePolicy"]["keeps"]):
            errors.append(f"{name}: disablePolicy must keep receipt_replay and historical_read")
        if "new_intent" in module["disablePolicy"]["keeps"]:
            errors.append(f"{name}: disablePolicy must not keep new_intent")
        if "frozen_intent_completion" not in module["disablePolicy"]["keeps"] and (
            "original_owner_refund" in module["disablePolicy"]["keeps"]
        ):
            errors.append(f"{name}: original_owner_refund recovery needs frozen_intent_completion")
        errors.extend(event_errors(module, retention, v1_event_types, envelopes))

    errors.extend(cycle_errors(by_id))
    seen: dict[tuple[str, str, str], tuple[str, dict]] = {}
    for module in modules:
        for event in module["publicEvents"]:
            key = event_key(event)
            if key in seen:
                other, previous = seen[key]
                why = "duplicate" if previous == event and other == module["moduleId"] else "conflicting"
                errors.append(f"{module['moduleId']}: {why} event contract {'/'.join(key)} also declared by {other}")
            else:
                seen[key] = (module["moduleId"], event)

    for composition in document["compositions"]:
        errors.extend(composition_errors(composition, by_id, ports))
    return errors


def event_errors(module: dict, retention: set[str], v1_types: set[str], envelopes: set[str]) -> list[str]:
    name = module["moduleId"]
    errors: list[str] = []
    event_types = [e["eventType"] for e in module["publicEvents"]]
    for event in module["publicEvents"]:
        label = f"{name}: event {event['eventType']}"
        if event["envelopeSchema"] == "billium.event.v1":
            if event["eventType"] not in v1_types:
                errors.append(f"{label} is not in the strict billium.event.v1 enum (D63)")
            if event["eventContractRevision"] != "events-2026-10-v1":
                errors.append(f"{label}: v1 events use contract revision events-2026-10-v1")
        elif event["envelopeSchema"] not in envelopes:
            if not event["eventContractRevision"].endswith(event["envelopeSchema"].rsplit(".", 1)[1]):
                errors.append(f"{label}: contract revision does not match envelope {event['envelopeSchema']}")
        if event["retentionPolicyRef"] not in retention:
            errors.append(f"{label}: unknown retentionPolicyRef {event['retentionPolicyRef']}")
        source = event.get("snapshotSourceKey")
        if source is not None and source.split(".", 1)[0] != name:
            errors.append(f"{label}: snapshotSourceKey must be owned by {name}")
    for command in module["internalCommands"]:
        if command["commandType"] in event_types:
            errors.append(f"{name}: command {command['commandType']} reuses a public event type")
    duplicates = {t for t in (c["commandType"] for c in module["internalCommands"])
                  if [c["commandType"] for c in module["internalCommands"]].count(t) > 1}
    for command_type in sorted(duplicates):
        errors.append(f"{name}: command {command_type} is declared more than once")
    return errors


def cycle_errors(by_id: dict[str, dict]) -> list[str]:
    errors: list[str] = []
    state: dict[str, int] = {}

    def visit(node: str, path: list[str]) -> None:
        if state.get(node) == 2 or node not in by_id:
            return
        if state.get(node) == 1:
            errors.append("dependency cycle: " + " -> ".join([*path[path.index(node):], node]))
            return
        state[node] = 1
        for dependency in by_id[node]["requiresModules"]:
            visit(dependency["moduleId"], [*path, node])
        state[node] = 2

    for node in by_id:
        visit(node, [])
    return errors


def composition_errors(composition: dict, by_id: dict[str, dict], ports: dict[str, str]) -> list[str]:
    name = composition["id"]
    errors: list[str] = []
    members = composition["modules"]
    if set(members) != set(by_id):
        errors.append(f"composition {name}: must classify exactly the modules {sorted(by_id)}")
    for module_id, presence in members.items():
        if module_id not in by_id:
            continue
        module = by_id[module_id]
        if presence != "M":
            continue
        for dependency in module["requiresModules"]:
            if members.get(dependency["moduleId"]) == "X":
                errors.append(f"composition {name}: mandatory {module_id} requires forbidden {dependency['moduleId']}")
        for port in module["requiresPorts"]:
            if composition["ports"].get(port) == "X":
                errors.append(f"composition {name}: mandatory {module_id} requires forbidden port {port}")
    for port in composition["ports"]:
        if port not in ports:
            errors.append(f"composition {name}: unknown port {port}")
    return errors


def php_json(expression: str) -> object:
    result = subprocess.run(["php", "-r", expression], capture_output=True, text=True, cwd=ROOT, check=False)
    if result.returncode != 0:
        raise RuntimeError(f"php failed: {result.stderr.strip()}")
    return json.loads(result.stdout)


def config_leaves() -> set[str]:
    """Dotted leaf keys of config/billium.php (a leaf is a scalar, null or a list)."""
    expression = (
        "function env($key, $default = null) { return $default; }"
        f"echo json_encode(require '{CONFIG}');"
    )
    tree = php_json(expression)
    leaves: set[str] = set()

    def walk(node: object, prefix: str) -> None:
        if isinstance(node, dict) and node:
            for key, value in node.items():
                walk(value, f"{prefix}.{key}")
        else:
            leaves.add(prefix)

    walk(tree, "billium")
    return leaves


def migration_tables(source: str) -> list[str]:
    source = re.sub(r"/\*.*?\*/|//[^\n]*|#[^\n]*", "", source, flags=re.S)
    found = re.findall(r"\bSchema\s*::\s*create\s*\(\s*['\"](billium_[a-z0-9_]+)['\"]", source, flags=re.I)
    found += re.findall(
        r"\bCREATE\s+(?:UNLOGGED\s+)?TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:\"?public\"?\.)?\"?(billium_[a-z0-9_]+)\"?",
        source,
        flags=re.I,
    )
    return list(dict.fromkeys(found))


def repository_errors(document: dict, root: Path = ROOT) -> list[str]:
    """Violations that need the repository: packaged resource, migrations, config, enum, identities."""
    errors: list[str] = []
    modules: list[dict] = document["modules"]
    by_id = {m["moduleId"]: m for m in modules}
    resource = root / "packages/core/resources/modules.json"
    if not resource.is_file():
        errors.append(f"{resource.relative_to(root)} is missing; run scripts/spec/export-modules.py")
    elif json.loads(resource.read_text(encoding="utf-8")) != document:
        errors.append(f"{resource.relative_to(root)} differs from spec/modules.json; run scripts/spec/export-modules.py")

    migrations = root / "packages/core/database/migrations"
    streams = {m["migrationStream"] for m in modules}
    for directory in sorted(p for p in migrations.glob("*") if p.is_dir()):
        if directory.name not in streams:
            errors.append(f"migration directory {directory.name} has no module descriptor")
    for module in modules:
        stream = migrations / module["migrationStream"]
        files = sorted(stream.glob("*.php")) if stream.is_dir() else []
        created = {t for f in files for t in migration_tables(f.read_text(encoding="utf-8"))}
        if created != set(module["ownedTables"]):
            errors.append(
                f"{module['moduleId']}: ownedTables {sorted(module['ownedTables'])} differ from tables created by "
                f"its migrations {sorted(created)}"
            )
        declared = {m["id"]: m for m in module["migrationIdentities"]}
        if {f.stem for f in files} != set(declared):
            errors.append(f"{module['moduleId']}: migration files {sorted(f.stem for f in files)} differ from identities {sorted(declared)}")
        for file in files:
            entry = declared.get(file.stem)
            if entry is not None and entry["checksum"] != "sha256:" + hashlib.sha256(file.read_bytes()).hexdigest():
                errors.append(f"{module['moduleId']}: migration {file.stem} changed after its checksum was recorded")
    for directory in sorted(p for p in (root / "packages/core/modules").glob("*") if p.is_dir()):
        if directory.name not in by_id:
            errors.append(f"packages/core/modules/{directory.name} has no module descriptor")

    try:
        leaves = config_leaves() if root == ROOT else set()
    except (RuntimeError, ValueError) as error:
        errors.append(f"config/billium.php cannot be evaluated: {error}")
        leaves = set()
    if root == ROOT and leaves:
        owned = {k for m in modules for k in m["configKeys"]}
        for key in sorted(owned - leaves):
            errors.append(f"configKey {key} does not exist in config/billium.php")
        for key in sorted(leaves - owned):
            errors.append(f"config/billium.php key {key} is owned by no module")

    enum = root / "packages/core/src/Enums/Modules/CompatibilityFindingCode.php"
    if not enum.is_file():
        errors.append(f"{enum.relative_to(root)} is missing")
    else:
        cases = set(re.findall(r"case\s+\w+\s*=\s*'([^']+)'", enum.read_text(encoding="utf-8")))
        if cases != set(document["findingCodes"]):
            errors.append("CompatibilityFindingCode cases differ from findingCodes in spec/modules.json")

    for kind, expected in (
        ("config-key", {k for m in modules for k in m["configKeys"]}),
        ("migration", {i["id"] for m in modules for i in m["migrationIdentities"]}),
        ("doctor-finding", set(document["findingCodes"])),
    ):
        snapshot = root / f"spec/identities/snapshots/{kind}.json"
        actual = set(json.loads(snapshot.read_text(encoding="utf-8"))) if snapshot.is_file() else None
        if kind == "config-key" and actual is not None:
            # The global snapshot also tracks server config. This registry owns billium.php.
            actual = {key for key in actual if key.startswith("billium.")}
        if actual != expected:
            errors.append(f"identity snapshot {kind} differs from the module registry; run scripts/spec/identities.py")

    errors.extend(payload_schema_errors(modules, root))
    if root == ROOT:
        errors.extend(doc_parity_errors(modules, root))
    return errors


def doc_parity_errors(modules: list[dict], root: Path) -> list[str]:
    errors: list[str] = []
    expected_requires = {
        m["moduleId"]: {r["moduleId"] for r in m["requiresModules"]}
        for m in modules
    }

    data_model = root / "docs/architecture/data-model.md"
    if data_model.is_file():
        text = data_model.read_text(encoding="utf-8")
        if "будущие потоки" in text:
            errors.append("docs/architecture/data-model.md still marks implemented modules as future streams")
        for stream in expected_requires:
            if not re.search(rf"\b{re.escape(stream)}\b", text):
                errors.append(f"docs/architecture/data-model.md does not mention migration stream {stream}")

    composition = root / "docs/architecture/module-composition.md"
    if composition.is_file():
        text = composition.read_text(encoding="utf-8")
        edges = re.findall(r"^\s*([a-z_]+)\s*-->\s*([a-z_]+)\s*$", text, re.MULTILINE)
        actual_requires: dict[str, set[str]] = {mid: set() for mid in expected_requires}
        for dep, target in edges:
            if target in actual_requires:
                actual_requires[target].add(dep)
        for mid, reqs in expected_requires.items():
            if actual_requires.get(mid) != reqs:
                errors.append(
                    f"docs/architecture/module-composition.md DAG for {mid} has {sorted(actual_requires.get(mid, set()))}, "
                    f"expected {sorted(reqs)}"
                )

    compat = root / "docs/architecture/module-compatibility.md"
    if compat.is_file():
        text = compat.read_text(encoding="utf-8")
        match = re.search(r"Внутренний DAG:\s*(.+?)\.\s+Нельзя", text, re.DOTALL)
        if not match:
            errors.append("docs/architecture/module-compatibility.md is missing 'Внутренний DAG:' summary")
        else:
            dag_text = re.sub(r"\([^)]*\)", "", match.group(1))
            for item in dag_text.split(";"):
                if "→" not in item:
                    continue
                mod, deps_raw = item.split("→", 1)
                mod = mod.strip()
                deps = {d.strip() for d in deps_raw.split("+") if d.strip()}
                if mod in expected_requires and deps != expected_requires[mod]:
                    errors.append(
                        f"docs/architecture/module-compatibility.md DAG for {mod} has {sorted(deps)}, "
                        f"expected {sorted(expected_requires[mod])}"
                    )

    return errors


def payload_schema_errors(modules: list[dict], root: Path) -> list[str]:
    errors: list[str] = []
    cache: dict[str, object] = {}
    for module in modules:
        for item in [*module["publicEvents"], *module["internalCommands"]]:
            ref = item["payloadSchemaRef"]
            path_text, _, pointer = ref.partition("#")
            path = root / path_text
            if not path.is_file():
                errors.append(f"{module['moduleId']}: payloadSchemaRef {ref} points to a missing file")
                continue
            node = cache.setdefault(path_text, json.loads(path.read_text(encoding="utf-8")))
            try:
                for part in [p for p in pointer.split("/") if p]:
                    node = node[part.replace("~1", "/").replace("~0", "~")]  # type: ignore[index]
            except (KeyError, TypeError):
                errors.append(f"{module['moduleId']}: payloadSchemaRef {ref} does not resolve")
                continue
            if digest_of(node) != item["payloadSchemaDigest"]:
                errors.append(f"{module['moduleId']}: payloadSchemaDigest of {ref} does not match the schema")
    return errors


def frozen_identities(root: Path = ROOT) -> tuple[set[str], set[str]]:
    # The identity snapshot includes events from every envelope revision; v1 stays closed.
    definitions = json.loads((root / "spec/revisions/2026-10/schema/common/defs.json").read_text(encoding="utf-8"))
    types = set(definitions["$defs"]["eventType"]["enum"])
    envelopes = set(json.loads((root / "spec/identities/snapshots/envelope.json").read_text(encoding="utf-8")))
    return types, envelopes


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--check", action="store_true", help="accepted for symmetry with export-modules.py; the checker never writes")
    parser.parse_args()
    try:
        document = _exporter().load_registry()
        types, envelopes = frozen_identities()
        errors = registry_errors(document, types, envelopes) + repository_errors(document)  # type: ignore[arg-type]
    except (ExportError, OSError, ValueError, KeyError) as error:
        print(f"{TOOL}: {error}", file=sys.stderr)
        return 1
    for error in errors:
        print(f"{TOOL}: {error}", file=sys.stderr)
    return 1 if errors else 0


if __name__ == "__main__":
    sys.exit(main())
