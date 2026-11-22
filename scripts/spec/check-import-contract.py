#!/usr/bin/env python3
"""External import contract, source mapping and handoff evidence checker (P9.1 / D71 / MECH-03).

CLI modes:
  --check            Validate external.import.v1 schemas, enrollment, type-map, identities,
                     D71 product-agnostic isolation, platform schema ownership, shared codec vectors
                     and positive/negative schema fixtures.
  --source-mappings  Validate deterministic source mapping namespaces, seller/environment-scoped
                     source identities, immutable checksums and negative conflict/batch-identity fixtures.
  --handoff          Validate handoff closure and authority evidence fixtures (D63/F06) and reject
                     activation without freeze/retirement/activation certificates or commit_no freshness claims.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import re
import sys
from pathlib import Path
from typing import Any

from jsonschema import Draft202012Validator
from referencing import Registry
from referencing.jsonschema import DRAFT202012

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "scripts/spec"))
sys.path.insert(0, str(ROOT / "conformance/oracles/jcs"))

import export_common  # noqa: E402
import oracle  # noqa: E402

REV = ROOT / "spec/revisions/2026-10"
IMPORT_SCHEMA_DIR = REV / "schema/import"
SCHEMA_FIXTURES_DIR = ROOT / "fixtures/integration/import/schema"
VECTOR_DIR = ROOT / "fixtures/vectors/import"
CONTRACT_DOC = ROOT / "docs/contracts/external-import-v1.md"

REQUIRED_SCHEMA_FILES = (
    "defs.json",
    "external.v1.json",
    "manifest.v1.json",
    "preview.v1.json",
)
REQUIRED_ENVELOPES = (
    ("ExternalImport", "external.import.v1", "import/external.v1.json"),
    ("ExternalImportManifest", "external.import.manifest.v1", "import/manifest.v1.json"),
    ("ExternalImportPreview", "external.import.preview.v1", "import/preview.v1.json"),
)
REQUIRED_PLATFORM_TABLES = (
    "billium_import_batch",
    "billium_import_record",
    "billium_import_mapping",
    "billium_import_receipt",
)
SOURCE_NAMESPACE_RE = re.compile(r"^[a-z][a-z0-9_.-]{0,63}(?:/[a-z0-9_.-]{1,64})*$")
DIGEST_RE = re.compile(r"^sha256:[0-9a-f]{64}$")
SOURCE_IDENTITY_FIELDS = (
    "installationRef",
    "environment",
    "sellerRef",
    "sourceInstallation",
    "sourceBook",
    "sourceRecordId",
    "recordKind",
)

# D71: strict product-agnostic self-isolation guards (built dynamically to keep this file clean of literal leaks).
FORBIDDEN_TERMS = (
    ("mem" + "ster", "D71 forbids external product names in Billium import surfaces"),
    ("edu" + "fy", "D71 forbids external product names in Billium import surfaces"),
    ("com" + "merz", "D71 forbids external product names in Billium import surfaces"),
    ("leg" + "acy.import.v1", "D71 forbids legacy contract naming; use external.import.v1"),
    ("leg" + "acy_unanchored", "D71 forbids legacy record class naming; use unanchored_evidence"),
    ("Leg" + "acyScenarioTest", "D71 forbids legacy test class naming"),
)
FORBIDDEN_HOST_DB_PATTERNS = (
    re.compile(r"\bmysql\s*:", re.IGNORECASE),
    re.compile(r"\bexternal_host_db\b", re.IGNORECASE),
    re.compile(r"\bforeign_db\b", re.IGNORECASE),
)


def canonical_digest(payload: dict[str, Any], exclude_member: str | None = "digest") -> str:
    body = {k: v for k, v in payload.items() if k != exclude_member}
    canonical = oracle.canonicalize(json.dumps(body, ensure_ascii=False))
    return "sha256:" + hashlib.sha256(canonical).hexdigest()


def source_identity_digest(record: dict[str, Any]) -> str:
    projection = {field: record[field] for field in SOURCE_IDENTITY_FIELDS}
    canonical = oracle.canonicalize(json.dumps(projection, ensure_ascii=False))
    return "sha256:" + hashlib.sha256(canonical).hexdigest()


def build_registry(rev: Path = REV) -> tuple[Registry, dict[str, dict[str, Any]]]:
    schema_dir = rev / "schema"
    urn = f"urn:billium:spec:{rev.name}:"
    documents: dict[str, dict[str, Any]] = {}
    resources = []
    for path in sorted(schema_dir.rglob("*.json")):
        rel = path.relative_to(schema_dir).as_posix()
        doc = json.loads(path.read_text(encoding="utf-8"))
        documents[rel] = doc
        resources.append((urn + rel, DRAFT202012.create_resource(doc)))
    return Registry().with_resources(resources), documents


def validate_envelope_document(
    document: dict[str, Any],
    schema_rel: str,
    registry: Registry,
    documents: dict[str, dict[str, Any]],
    verify_digest: bool = True,
) -> list[str]:
    errors: list[str] = []
    validator = Draft202012Validator(documents[schema_rel], registry=registry)
    for err in sorted(validator.iter_errors(document), key=lambda e: list(e.absolute_path)):
        where = "/".join(str(part) for part in err.absolute_path) or "<root>"
        errors.append(f"{schema_rel}@{where}: {err.message}")
    if not errors and verify_digest and isinstance(document.get("digest"), str):
        expected = canonical_digest(document, "digest")
        if document["digest"] != expected:
            errors.append(f"{schema_rel}: digest mismatch (expected {expected}, got {document['digest']})")
    return errors


def check_d71_isolation(paths: list[Path], repo_root: Path = ROOT) -> list[str]:
    errors: list[str] = []
    for path in paths:
        if not path.is_file():
            continue
        text = path.read_text(encoding="utf-8")
        lowered = text.lower()
        rel = path.relative_to(repo_root).as_posix() if path.is_relative_to(repo_root) else str(path)
        for term, reason in FORBIDDEN_TERMS:
            if term.lower() in lowered:
                errors.append(f"{rel}: forbidden term matched ({reason})")
        for pattern in FORBIDDEN_HOST_DB_PATTERNS:
            if pattern.search(text):
                errors.append(f"{rel}: forbidden direct foreign host DB reference")
    return errors


def check_import_contract(repo_root: Path = ROOT) -> list[str]:
    errors: list[str] = []
    rev = repo_root / "spec/revisions/2026-10"
    schema_dir = rev / "schema/import"

    # 1. Required schema files & valid Draft 2020-12.
    for filename in REQUIRED_SCHEMA_FILES:
        path = schema_dir / filename
        if not path.is_file():
            errors.append(f"missing import schema: {path.relative_to(repo_root)}")
    if errors:
        return errors

    registry, documents = build_registry(rev)
    for filename in REQUIRED_SCHEMA_FILES:
        rel = f"import/{filename}"
        doc = documents[rel]
        Draft202012Validator.check_schema(doc)
        raw = json.dumps(doc)
        if '"type": "number"' in raw or '"type":"number"' in raw:
            errors.append(f"{rel}: JSON number type is forbidden")

    # 2. Enrollment in export_common, README, type-map, identities/snapshots.
    enrolled = {(cls, name, src) for cls, name, src, _ in export_common.ENVELOPES}
    readme_text = (rev / "README.md").read_text(encoding="utf-8")
    type_map = json.loads((repo_root / "spec/type-map.json").read_text(encoding="utf-8"))
    type_map_by_schema = {entry["schema"]: entry for entry in type_map if entry.get("schema")}
    envelope_snapshot = set(json.loads((repo_root / "spec/identities/snapshots/envelope.json").read_text(encoding="utf-8")))

    for cls, schema_name, rel in REQUIRED_ENVELOPES:
        if (cls, schema_name, rel) not in enrolled:
            errors.append(f"export_common.ENVELOPES missing {(cls, schema_name, rel)}")
        if f"`{schema_name}`" not in readme_text:
            errors.append(f"spec/revisions/2026-10/README.md missing {schema_name}")
        entry = type_map_by_schema.get(schema_name)
        if entry is None:
            errors.append(f"spec/type-map.json missing {schema_name}")
        else:
            if entry.get("php") != f"Billium\\Contracts\\Data\\Envelopes\\{cls}":
                errors.append(f"spec/type-map.json PHP class mismatch for {schema_name}")
            if entry.get("dart") != cls:
                errors.append(f"spec/type-map.json Dart class mismatch for {schema_name}")
        if schema_name not in envelope_snapshot:
            errors.append(f"spec/identities/snapshots/envelope.json missing {schema_name}")

    # 3. Platform module table ownership.
    modules_doc = json.loads((repo_root / "spec/modules.json").read_text(encoding="utf-8"))
    platform = next((m for m in modules_doc["modules"] if m["moduleId"] == "platform"), None)
    if platform is None:
        errors.append("spec/modules.json missing platform module")
    else:
        owned = set(platform.get("ownedTables", []))
        for table in REQUIRED_PLATFORM_TABLES:
            if table not in owned:
                errors.append(f"spec/modules.json platform.ownedTables missing {table}")

    # 4. Contract doc exists and documents core D71 concepts.
    doc_path = repo_root / "docs/contracts/external-import-v1.md"
    if not doc_path.is_file():
        errors.append("missing docs/contracts/external-import-v1.md")
    else:
        doc_text = doc_path.read_text(encoding="utf-8")
        for needle in ("external.import.v1", "external.import.manifest.v1", "external.import.preview.v1", "unanchored_evidence", "sourceFactRef"):
            if needle not in doc_text:
                errors.append(f"docs/contracts/external-import-v1.md missing required term {needle}")

    # 5. Schema fixtures (positive must pass, negative must fail).
    fixtures_dir = repo_root / "fixtures/integration/import/schema"
    schema_by_name = {name: rel for _, name, rel in REQUIRED_ENVELOPES}
    positive_fixtures = sorted(fixtures_dir.glob("valid-*.json"))
    negative_fixtures = sorted(fixtures_dir.glob("negative-*.json"))
    if not positive_fixtures:
        errors.append("no valid-*.json fixtures under fixtures/integration/import/schema/")
    if not negative_fixtures:
        errors.append("no negative-*.json fixtures under fixtures/integration/import/schema/")

    for fixture in positive_fixtures:
        data = json.loads(fixture.read_text(encoding="utf-8"))
        schema_name = data.get("schema")
        rel = schema_by_name.get(schema_name)
        if rel is None:
            errors.append(f"{fixture.name}: unknown import schema {schema_name!r}")
            continue
        verify = schema_name != "external.import.preview.v1" or "digest" in data
        for finding in validate_envelope_document(data, rel, registry, documents, verify_digest=verify):
            errors.append(f"{fixture.name}: {finding}")

    for fixture in negative_fixtures:
        wrapper = json.loads(fixture.read_text(encoding="utf-8"))
        target_schema = wrapper.get("targetSchema")
        payload = wrapper.get("payload")
        rel = schema_by_name.get(target_schema)
        if rel is None or not isinstance(payload, dict):
            errors.append(f"{fixture.name}: negative fixture requires targetSchema and object payload")
            continue
        findings = validate_envelope_document(payload, rel, registry, documents, verify_digest=True)
        if not findings:
            errors.append(f"{fixture.name}: negative fixture unexpectedly passed validation")

    # 6. Shared PHP+Dart codec vectors.
    vector_dir = repo_root / "fixtures/vectors/import"
    vectors = sorted(vector_dir.glob("*.json")) if vector_dir.is_dir() else []
    if not vectors:
        errors.append("no shared codec vectors under fixtures/vectors/import/")
    for vector_path in vectors:
        vector = json.loads(vector_path.read_text(encoding="utf-8"))
        profiles = set(vector.get("profiles", []))
        if not {"php", "dart"}.issubset(profiles):
            errors.append(f"{vector_path.name}: import codec vector must run on both php and dart profiles")

    # 7. D71 isolation scan across all P9.1 surfaces.
    isolation_targets: list[Path] = [
        doc_path,
        *sorted(schema_dir.glob("*.json")),
        *sorted(fixtures_dir.rglob("*.json")),
        *vectors,
        *sorted((repo_root / "packages/core/modules/platform/src/Contracts/Import").rglob("*.php")),
        *sorted((repo_root / "packages/core/modules/platform/src/Data/Import").rglob("*.php")),
        *sorted((repo_root / "packages/core/modules/platform/src/Internal/Import").rglob("*.php")),
        *sorted((repo_root / "packages/core/modules/platform/tests/Import").rglob("*.php")),
    ]
    errors.extend(check_d71_isolation(isolation_targets, repo_root))
    return errors


def validate_source_mapping_document(document: dict[str, Any]) -> list[str]:
    """Validate deterministic mapping namespaces, unique seller-scoped source identities and checksums."""
    errors: list[str] = []
    namespace = document.get("namespace")
    if not isinstance(namespace, str) or not SOURCE_NAMESPACE_RE.match(namespace):
        errors.append(f"invalid deterministic mapping namespace: {namespace!r}")
    if document.get("batchIdInEconomicIdentity") is True:
        errors.append("batchId cannot participate in economic source identity")
    if document.get("renamesSourceBookOnMapperUpdate") is True:
        errors.append("sourceBook and sourceRecordId cannot be renamed on mapper/policy update")

    entries = document.get("mappings")
    if not isinstance(entries, list) or not entries:
        errors.append("mappings must be a non-empty list")
        return errors

    seen_identity_rev: dict[tuple[str, int], str] = {}
    seen_active_identity: set[str] = set()
    tombstoned_identities: set[str] = set()
    entry_digests: list[str] = []

    for idx, entry in enumerate(entries):
        where = f"mappings[{idx}]"
        if not isinstance(entry, dict):
            errors.append(f"{where}: must be an object")
            continue
        missing = [field for field in SOURCE_IDENTITY_FIELDS if not isinstance(entry.get(field), str) or not entry[field]]
        if missing:
            errors.append(f"{where}: missing source identity fields {missing}")
            continue
        source_system = entry.get("sourceSystem")
        if not isinstance(source_system, str) or not SOURCE_NAMESPACE_RE.match(source_system):
            errors.append(f"{where}: invalid sourceSystem namespace {source_system!r}")
        rev = entry.get("sourceRecordRevision")
        if not isinstance(rev, int) or isinstance(rev, bool) or rev < 0:
            errors.append(f"{where}: sourceRecordRevision must be a non-negative integer")
            continue
        payload_digest = entry.get("payloadDigest")
        if not isinstance(payload_digest, str) or not DIGEST_RE.match(payload_digest):
            errors.append(f"{where}: invalid payloadDigest")
            continue
        expected_identity_digest = source_identity_digest(entry)
        declared_identity_digest = entry.get("sourceIdentityDigest")
        if declared_identity_digest != expected_identity_digest:
            errors.append(f"{where}: sourceIdentityDigest mismatch (expected {expected_identity_digest})")

        status = entry.get("status", "active")
        key = (expected_identity_digest, rev)
        if key in seen_identity_rev and seen_identity_rev[key] != payload_digest:
            errors.append(f"{where}: conflicting payloadDigest for same source identity and revision {rev}")
        seen_identity_rev[key] = payload_digest

        if status == "tombstoned":
            if not entry.get("tombstoneReason"):
                errors.append(f"{where}: tombstoned mapping requires tombstoneReason")
            tombstoned_identities.add(expected_identity_digest)
        elif status == "active":
            if expected_identity_digest in seen_active_identity:
                errors.append(f"{where}: duplicate active mapping for source identity {expected_identity_digest}")
            seen_active_identity.add(expected_identity_digest)
        entry_digests.append(f"{expected_identity_digest}:{rev}:{payload_digest}")

    reactivated = seen_active_identity & tombstoned_identities
    for ident in sorted(reactivated):
        errors.append(f"tombstoned source identity {ident} cannot also have an active mapping")

    expected_checksum = "sha256:" + hashlib.sha256(
        oracle.canonicalize(json.dumps(sorted(entry_digests), ensure_ascii=False))
    ).hexdigest()
    if document.get("mappingsChecksum") != expected_checksum:
        errors.append(f"mappingsChecksum mismatch (expected {expected_checksum}, got {document.get('mappingsChecksum')!r})")

    return errors


def check_source_mappings(repo_root: Path = ROOT) -> list[str]:
    errors: list[str] = []
    mappings_dir = repo_root / "fixtures/integration/import/schema/mappings"
    positives = sorted(mappings_dir.glob("valid-*.json")) if mappings_dir.is_dir() else []
    negatives = sorted(mappings_dir.glob("negative-*.json")) if mappings_dir.is_dir() else []
    if not positives:
        errors.append("no valid-*.json source mapping fixtures under fixtures/integration/import/schema/mappings/")
    if not negatives:
        errors.append("no negative-*.json source mapping fixtures under fixtures/integration/import/schema/mappings/")

    for path in positives:
        doc = json.loads(path.read_text(encoding="utf-8"))
        for finding in validate_source_mapping_document(doc):
            errors.append(f"{path.name}: {finding}")

    for path in negatives:
        doc = json.loads(path.read_text(encoding="utf-8"))
        findings = validate_source_mapping_document(doc)
        if not findings:
            errors.append(f"{path.name}: negative source mapping fixture unexpectedly passed")

    errors.extend(check_d71_isolation([*positives, *negatives], repo_root))
    return errors


def validate_handoff_document(document: dict[str, Any]) -> list[str]:
    """Validate D63/F06 handoff closure and authority evidence without commit_no freshness claims."""
    errors: list[str] = []

    if document.get("usesCommitNoAsFreshnessProof") is True or document.get("reliesOnLocalActiveFlag") is True:
        errors.append("D63/F06 forbids commit_no freshness/completeness proofs and local active flags")

    freeze = document.get("sourceFreezeCertificate")
    manifest = document.get("migrationManifest")
    activation = document.get("activation")

    if not isinstance(freeze, dict):
        errors.append("missing sourceFreezeCertificate")
    else:
        for field in ("sourceOwnerSession", "freezeGeneration", "sealReceiptRef", "scope", "evidenceDigest"):
            if not freeze.get(field):
                errors.append(f"sourceFreezeCertificate missing {field}")
        if isinstance(freeze.get("evidenceDigest"), str) and not DIGEST_RE.match(freeze["evidenceDigest"]):
            errors.append("sourceFreezeCertificate.evidenceDigest must be a valid sha256 digest")

    if not isinstance(manifest, dict):
        errors.append("missing migrationManifest")
    else:
        for field in (
            "snapshotToken",
            "snapshotDigest",
            "safeFeedCursor",
            "selectionDigest",
            "retentionLeaseRef",
            "retentionLeaseExpiresAt",
            "perSellerCurrencyChecksums",
            "manifestDigest",
        ):
            if not manifest.get(field):
                errors.append(f"migrationManifest missing {field}")
        for digest_field in ("snapshotDigest", "selectionDigest", "manifestDigest"):
            val = manifest.get(digest_field)
            if isinstance(val, str) and not DIGEST_RE.match(val):
                errors.append(f"migrationManifest.{digest_field} must be a valid sha256 digest")
        cursor = manifest.get("safeFeedCursor")
        if not isinstance(cursor, dict) or not cursor.get("txId") or not cursor.get("id"):
            errors.append("migrationManifest.safeFeedCursor requires string txId and id")
        if isinstance(manifest.get("manifestDigest"), str) and DIGEST_RE.match(manifest["manifestDigest"]):
            expected = canonical_digest(manifest, "manifestDigest")
            if manifest["manifestDigest"] != expected:
                errors.append(f"migrationManifest.manifestDigest mismatch (expected {expected})")

    if isinstance(activation, dict) and activation.get("requested") is True:
        if not isinstance(freeze, dict):
            errors.append("activation requested without SourceFreezeCertificate")
        if not isinstance(manifest, dict):
            errors.append("activation requested without MigrationManifest")
        retirement = activation.get("retirementProof")
        cert = activation.get("activationCertificate")
        if not isinstance(retirement, dict) or not retirement.get("proofRef") or not retirement.get("digest"):
            errors.append("activation requested without RetirementProof")
        if not isinstance(cert, dict) or not cert.get("certificateRef") or not cert.get("targetOwnerSession") or not cert.get("digest"):
            errors.append("activation requested without ActivationCertificate")
        if activation.get("tailVerified") is not True or activation.get("reconciliationEqual") is not True:
            errors.append("activation requires tailVerified=true and reconciliationEqual=true")
        if activation.get("unresolvedBlockers"):
            errors.append("activation cannot proceed with unresolvedBlockers")

    return errors


def check_handoff(repo_root: Path = ROOT) -> list[str]:
    errors: list[str] = []
    handoff_dir = repo_root / "fixtures/integration/import/schema/handoff"
    positives = sorted(handoff_dir.glob("valid-*.json")) if handoff_dir.is_dir() else []
    negatives = sorted(handoff_dir.glob("negative-*.json")) if handoff_dir.is_dir() else []
    if not positives:
        errors.append("no valid-*.json handoff fixtures under fixtures/integration/import/schema/handoff/")
    if not negatives:
        errors.append("no negative-*.json handoff fixtures under fixtures/integration/import/schema/handoff/")

    for path in positives:
        doc = json.loads(path.read_text(encoding="utf-8"))
        for finding in validate_handoff_document(doc):
            errors.append(f"{path.name}: {finding}")

    for path in negatives:
        doc = json.loads(path.read_text(encoding="utf-8"))
        findings = validate_handoff_document(doc)
        if not findings:
            errors.append(f"{path.name}: negative handoff fixture unexpectedly passed")

    errors.extend(check_d71_isolation([*positives, *negatives], repo_root))
    return errors


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    group = parser.add_mutually_exclusive_group(required=True)
    group.add_argument("--check", action="store_true", help="validate schemas, type-map, source identity and D71 isolation")
    group.add_argument("--source-mappings", action="store_true", help="validate deterministic mapping namespaces and checksums")
    group.add_argument("--handoff", action="store_true", help="validate handoff closure and authority evidence")
    parser.add_argument("--repo-root", type=Path, default=ROOT, help="repository root directory")
    args = parser.parse_args(argv)

    repo = args.repo_root.resolve()
    if args.check:
        mode = "check"
        errors = check_import_contract(repo)
    elif args.source_mappings:
        mode = "source-mappings"
        errors = check_source_mappings(repo)
    else:
        mode = "handoff"
        errors = check_handoff(repo)

    for line in errors:
        print(f"check-import-contract ({mode}): {line}", file=sys.stderr)
    if errors:
        print(f"check-import-contract ({mode}): {len(errors)} finding(s)", file=sys.stderr)
        return 1
    print(f"check-import-contract ({mode}): OK")
    return 0


if __name__ == "__main__":
    sys.exit(main())
