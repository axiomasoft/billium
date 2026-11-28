#!/usr/bin/env python3
"""Build spec/identities.json and spec/identities/snapshots/<kind>.json from a spec revision.

The registry is the frozen-identity ledger: every envelope, envelope field, problem code, event type,
operation status, invoice status and the spec revision itself; config keys, migration identities, route names and doctor
findings are also tracked. Migration, config and route identities come from their product files, doctor
findings from spec/modules.json. Entries are appended with status "new";
an entry that disappears from the sources must be kept with status "retired" by hand, otherwise
the check fails. Snapshots are sorted id lists of the live (not retired) entries per kind.

Writes by default; --check compares without writing and exits 1 on drift.
"""
from __future__ import annotations

import argparse
import json
import re
import subprocess
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from export_common import ENVELOPES, DEFAULT_REVISION, SUPPORTED_REVISIONS, ExportError, Revision  # noqa: E402

ROOT = Path(__file__).resolve().parents[2]
REGISTRY = ROOT / "spec/identities.json"
SNAPSHOTS = ROOT / "spec/identities/snapshots"
MODULES = ROOT / "spec/modules.json"


def product_identities(root: Path) -> dict[tuple[str, str], str]:
    """Read real migration basenames, flattened PHP config and named server routes."""
    found: dict[tuple[str, str], str] = {}
    for path in sorted((root / 'packages/core/database/migrations').rglob('*.php')):
        key = ('migration', path.stem)
        if key in found:
            raise IdentityError(f'duplicate migration identity {path.stem}: {found[key]} and {path.relative_to(root)}')
        found[key] = path.relative_to(root).as_posix()
    def walk(value: object, prefix: str, config: str) -> None:
        if isinstance(value, dict) and value:
            for key, item in value.items():
                walk(item, prefix + '.' + key, config)
        else:
            # Lists are values (enabled modules, allow-lists), not public numeric config keys.
            found[("config-key", prefix)] = config

    for config in ['packages/core/config/billium.php', 'packages/server/config/billium-server.php']:
        # Standalone config may call Laravel env(); load the helper without booting an application.
        result = subprocess.run(['php', '-r', 'require "vendor/autoload.php"; echo json_encode(require $argv[1], JSON_THROW_ON_ERROR);', config],
                                cwd=root, capture_output=True, text=True, check=True)
        walk(json.loads(result.stdout), Path(config).stem, config)
    routes = 'packages/server/routes/http.php'
    route_source = root / routes
    if route_source.is_file():
        for name in re.findall(r"->name\('([^']+)'\)", route_source.read_text(encoding='utf-8')):
            key = ('route', name)
            if key in found:
                raise IdentityError(f'duplicate route identity {name}: {routes}')
            found[key] = routes
    return found


KINDS = (
    "envelope",
    "envelope-field",
    "problem-code",
    "event-type",
    "operation-status",
    "invoice-status",
    "spec-revision",
    "config-key",
    "migration",
    "route",
    "doctor-finding",
)
STATUSES = ("new", "frozen", "changed", "retired")
DEFS_ID = "urn:billium:spec:{revision}:common/defs.json"


class IdentityError(Exception):
    pass


def collect(directory: Path) -> dict[tuple[str, str], dict[str, str]]:
    """(kind, id) -> {since, source} for every identity the revision sources define."""
    revision = Revision(directory)
    rev = revision.name
    base = f"spec/revisions/{rev}"
    found: dict[tuple[str, str], dict[str, str]] = {}

    def add(kind: str, identity: str, source: str) -> None:
        found[(kind, identity)] = {"since": rev, "source": source}

    rev_envelopes = [(c, s, src, v) for (c, s, src, v) in ENVELOPES if revision.document_id(src) in revision.documents]
    for envelope, (_class, _schema, _source, variant) in zip(revision.envelopes(), rev_envelopes, strict=True):
        source = f"{base}/schema/{envelope.source}"
        add("envelope", envelope.schema, source)
        prefix = f"{envelope.schema}#/$defs/{variant}" if variant else f"{envelope.schema}#"
        for member in envelope.members:
            add("envelope-field", f"{prefix}/properties/{member.name}", source)
        document = revision.documents[revision.document_id(envelope.source)]
        event_type = document.get("properties", {}).get("eventType", {}).get("const")
        if isinstance(event_type, str):
            add("event-type", event_type, source)
    for entry in revision.codes:
        add("problem-code", str(entry["code"]), f"{base}/problem-codes.json")
    defs = revision.documents[DEFS_ID.format(revision=rev)]["$defs"]  # type: ignore[index]
    for kind, name in (
        ("event-type", "eventType"),
        ("operation-status", "operationStatus"),
        ("invoice-status", "invoiceStatus"),
    ):
        for identity in defs[name]["enum"]:  # type: ignore[index]
            add(kind, identity, f"{base}/schema/common/defs.json")
    for catalog in sorted((directory / 'event-catalogs').glob('*.json')):
        for event in json.loads(catalog.read_text(encoding='utf-8'))['events']:
            add('event-type', event['eventType'], f'{base}/event-catalogs/{catalog.name}')
    for sup_rev in SUPPORTED_REVISIONS:
        found[("spec-revision", sup_rev)] = {"since": sup_rev, "source": f"spec/revisions/{sup_rev}/README.md"}
    modules = MODULES.read_text(encoding="utf-8") if MODULES.is_file() else None
    if modules is not None:
        registry = json.loads(modules)
        source = "spec/modules.json"
        for code in registry["findingCodes"]:
            add("doctor-finding", code, source)
    for (kind, identity), source in product_identities(ROOT).items():
        add(kind, identity, source)
    return found


def merge(found: dict[tuple[str, str], dict[str, str]], previous: list[dict[str, str]]) -> list[dict[str, str]]:
    old = {(entry["kind"], entry["id"]): entry for entry in previous}
    for key, entry in old.items():
        if entry["status"] not in STATUSES:
            raise IdentityError(f"{key[0]} {key[1]}: unknown status {entry['status']!r}")
        if key not in found and entry["status"] != "retired":
            raise IdentityError(f"{key[0]} {key[1]}: removed from the sources without status 'retired'")
    entries = []
    for key in sorted(set(found) | set(old), key=lambda k: (KINDS.index(k[0]), k[1])):
        if key in old:
            entry = dict(old[key])
            if key in found and key[0] in ('migration', 'config-key', 'route'):
                entry['source'] = found[key]['source']
            entries.append(entry)
        else:
            entries.append({"kind": key[0], "id": key[1], "status": "new", **found[key]})
    return entries


def render_registry(revision: str, entries: list[dict[str, str]]) -> str:
    return json.dumps({"revision": revision, "entries": entries}, ensure_ascii=False, indent=2) + "\n"


def render_snapshots(entries: list[dict[str, str]]) -> dict[Path, str]:
    files = {}
    for kind in KINDS:
        ids = sorted(e["id"] for e in entries if e["kind"] == kind and e["status"] != "retired")
        files[SNAPSHOTS / f"{kind}.json"] = json.dumps(ids, ensure_ascii=False, indent=2) + "\n"
    return files


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--rev", type=Path, default=DEFAULT_REVISION, help="revision directory")
    parser.add_argument("--check", action="store_true", help="compare only, never write")
    args = parser.parse_args()

    try:
        previous: list[dict[str, str]] = []
        if REGISTRY.is_file():
            previous = json.loads(REGISTRY.read_text(encoding="utf-8"))["entries"]
        elif args.check:
            raise IdentityError(f"{REGISTRY.relative_to(ROOT)} is missing")
        directory = args.rev.resolve()
        entries = merge(collect(directory), previous)
    except (OSError, ExportError, IdentityError, KeyError, subprocess.CalledProcessError, json.JSONDecodeError) as error:
        print(f"identities: {error}", file=sys.stderr)
        return 1

    files = {REGISTRY: render_registry(directory.name, entries), **render_snapshots(entries)}
    stale = [
        path for path, text in files.items()
        if not path.is_file() or path.read_text(encoding="utf-8") != text
    ]
    if args.check:
        for path in stale:
            print(f"identities: {path.relative_to(ROOT)} is stale; run scripts/spec/identities.py", file=sys.stderr)
        return 1 if stale else 0
    SNAPSHOTS.mkdir(parents=True, exist_ok=True)
    for path in stale:
        path.write_text(files[path], encoding="utf-8")
        print(f"identities: wrote {path.relative_to(ROOT)}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
