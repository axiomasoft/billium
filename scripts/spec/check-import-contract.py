#!/usr/bin/env python3
"""External import contract, source mapping and handoff evidence checker (P9.1 / D71 / MECH-03).

CLI modes:
  --check            Validate external.import.v1 schemas, enrollment, type-map, identities,
                     D71 product-agnostic isolation, platform schema ownership, shared codec vectors
                     and positive/negative schema fixtures.
  --source-mappings  Validate deterministic source mapping namespaces, seller/environment-scoped
                     source identities, immutable checksums and negative conflict/batch-identity fixtures.
  --handoff Validate handoff closure and authority evidence fixtures and reject
                     activation without freeze/retirement/activation certificates or commit_no freshness claims.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import re
import sys
from datetime import datetime
from pathlib import Path
from typing import Any

from jsonschema import Draft202012Validator, FormatChecker
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

# Strict product-agnostic self-isolation guards (built dynamically to keep this file clean of literal leaks).
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


def migration_php_targets(repo_root: Path = ROOT) -> list[Path]:
    targets: list[Path] = []
    for base in (
        repo_root / "packages/core/modules/platform/src",
        repo_root / "packages/core/modules/platform/tests",
    ):
        if not base.is_dir():
            continue
        for mig_dir in sorted(base.rglob("Migration")):
            if mig_dir.is_dir():
                targets.extend(sorted(mig_dir.rglob("*.php")))
    return targets


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

    # 7. D71 isolation scan across all P9.1–P9.4 surfaces.
    migration_schema_dir = rev / "schema/migration"
    isolation_targets: list[Path] = [
        doc_path,
        *sorted(schema_dir.glob("*.json")),
        *sorted(migration_schema_dir.glob("*.json")),
        *sorted((repo_root / "fixtures/integration/import").rglob("*.json")),
        *vectors,
        *(sorted((repo_root / "docs/migrations").rglob("*")) if (repo_root / "docs/migrations").is_dir() else []),
        repo_root / "scripts/import/billium-import.php",
        *sorted((repo_root / "packages/core/modules/platform/src/Contracts/Import").rglob("*.php")),
        *sorted((repo_root / "packages/core/modules/platform/src/Data/Import").rglob("*.php")),
        *sorted((repo_root / "packages/core/modules/platform/src/Internal/Import").rglob("*.php")),
        *sorted((repo_root / "packages/core/modules/platform/tests/Import").rglob("*.php")),
        *sorted((repo_root / "packages/core/modules/ledger/src/Internal/Import").rglob("*.php")),
        *migration_php_targets(repo_root),
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


REQUIRED_MISSING_EVIDENCE_CONDITIONS = (
    "missing_price_or_currency",
    "missing_funding_or_provider_refs",
    "mutable_subscription_without_frozen_occurrences",
    "current_plan_only_without_frozen_price",
    "nonmonetary_credit_claimed_as_money",
    "noncurrency_reward_quantity",
    "missing_historical_invoice_number",
    "annual_funding_with_periodic_grants",
    "unconfirmed_legal_ownership",
    "exported_source_credential_detected",
)
REQUIRED_PARTY_ROLES = (
    "subjectRef",
    "payerRef",
    "actorRef",
    "customerRef",
    "sellerRef",
)
VALID_DECISION_TARGET_KINDS = {
    "proven_obligation",
    "proven_invoice_history",
    "proven_payment_evidence",
    "proven_refund_evidence",
    "proven_allocation",
    "nonfinancial_agreement_reference",
    "unanchored_evidence",
    "reject_artifact",
}


def validate_source_mapping_manifest(document: dict[str, Any], repo_root: Path = ROOT) -> list[str]:
    """Validate versioned source export mapping manifest (P9.2 / V922 / D71)."""
    errors: list[str] = []

    if document.get("schema") != "external.import.source-mapping.v1":
        errors.append("schema must equal 'external.import.source-mapping.v1'")
    if not isinstance(document.get("mappingVersion"), str) or not document["mappingVersion"].strip():
        errors.append("mappingVersion must be a nonempty string")
    if document.get("contractTarget") != "external.import.v1":
        errors.append("contractTarget must equal 'external.import.v1'")

    # 1. Core deterministic source mapping invariants & checksums.
    errors.extend(validate_source_mapping_document(document))

    # 2. Artifact integrity rules (cutoff, checksum, sanitization, no direct external DB, no credential-as-authority).
    integrity = document.get("artifactIntegrity")
    if not isinstance(integrity, dict):
        errors.append("missing artifactIntegrity section")
    else:
        for flag in (
            "requireExportCutoff",
            "requireRecordsChecksum",
            "requireSanitizationPolicy",
            "forbidDirectExternalDbAccess",
            "forbidExportedCredentialsAsAuthority",
        ):
            if integrity.get(flag) is not True:
                errors.append(f"artifactIntegrity.{flag} must be true")
        if integrity.get("cutoffFormat") != "rfc3339-utc":
            errors.append("artifactIntegrity.cutoffFormat must be 'rfc3339-utc'")
        if integrity.get("checksumAlgorithm") != "sha256-jcs":
            errors.append("artifactIntegrity.checksumAlgorithm must be 'sha256-jcs'")

    # 3. Provenance & explicit separation of local runtime evidence vs external activation inputs.
    provenance = document.get("provenance")
    if not isinstance(provenance, dict):
        errors.append("missing provenance section")
    else:
        if provenance.get("sourceExporterResponsibility") != "external_host_process":
            errors.append("provenance.sourceExporterResponsibility must be 'external_host_process'")
        if provenance.get("billiumReaderBoundary") != "immutable_artifact_or_stream_only":
            errors.append("provenance.billiumReaderBoundary must be 'immutable_artifact_or_stream_only'")
        if provenance.get("externalActivationInputsSeparated") is not True:
            errors.append("provenance.externalActivationInputsSeparated must be true")
        ext_inputs = provenance.get("externalActivationInputs")
        if not isinstance(ext_inputs, list) or not ext_inputs:
            errors.append("provenance.externalActivationInputs must declare deferred external activation inputs")
        else:
            for idx, item in enumerate(ext_inputs):
                if not isinstance(item, dict) or not item.get("inputId") or item.get("status") != "external_owner_supplied":
                    errors.append(f"provenance.externalActivationInputs[{idx}] must have inputId and status='external_owner_supplied'")

    # 4. Versioned product-agnostic profiles, required/optional fields, forbidden fabrications, missing-evidence decisions.
    profiles = document.get("profiles")
    if not isinstance(profiles, list) or not profiles:
        errors.append("profiles must be a non-empty list")
    else:
        seen_profile_ids: set[str] = set()
        seen_conditions: set[str] = set()
        for idx, profile in enumerate(profiles):
            where = f"profiles[{idx}]"
            if not isinstance(profile, dict):
                errors.append(f"{where}: must be an object")
                continue
            pid = profile.get("profileId")
            if not isinstance(pid, str) or not SOURCE_NAMESPACE_RE.match(pid):
                errors.append(f"{where}: invalid profileId {pid!r}")
            else:
                seen_profile_ids.add(pid)
            if not isinstance(profile.get("profileVersion"), str) or not profile["profileVersion"].strip():
                errors.append(f"{where}: missing profileVersion")
            ns = profile.get("sourceSystemNamespace")
            if not isinstance(ns, str) or not SOURCE_NAMESPACE_RE.match(ns):
                errors.append(f"{where}: invalid sourceSystemNamespace {ns!r}")

            req_fields = profile.get("requiredFields")
            opt_fields = profile.get("optionalFields")
            if not isinstance(req_fields, list) or not req_fields or not all(isinstance(f, str) and f for f in req_fields):
                errors.append(f"{where}: requiredFields must be a non-empty list of strings")
            if not isinstance(opt_fields, list) or not opt_fields or not all(isinstance(f, str) and f for f in opt_fields):
                errors.append(f"{where}: optionalFields must be a non-empty list of strings")
            if isinstance(req_fields, list) and isinstance(opt_fields, list):
                overlap = set(req_fields) & set(opt_fields)
                if overlap:
                    errors.append(f"{where}: fields cannot be both required and optional: {sorted(overlap)}")

            party_roles = profile.get("partyRoleSeparation")
            if not isinstance(party_roles, list) or not set(REQUIRED_PARTY_ROLES).issubset(set(party_roles)):
                errors.append(f"{where}: partyRoleSeparation must include all of {list(REQUIRED_PARTY_ROLES)}")

            forbidden = profile.get("forbiddenFabrications")
            if not isinstance(forbidden, list) or len(forbidden) < 6:
                errors.append(f"{where}: forbiddenFabrications must explicitly enumerate prohibited synthetic defaults")

            decisions = profile.get("missingEvidenceDecisions")
            if not isinstance(decisions, list) or not decisions:
                errors.append(f"{where}: missingEvidenceDecisions must be a non-empty list")
            else:
                for d_idx, dec in enumerate(decisions):
                    d_where = f"{where}.missingEvidenceDecisions[{d_idx}]"
                    if not isinstance(dec, dict):
                        errors.append(f"{d_where}: must be an object")
                        continue
                    cond = dec.get("condition")
                    if not isinstance(cond, str) or not cond:
                        errors.append(f"{d_where}: missing condition")
                    else:
                        seen_conditions.add(cond)
                    target = dec.get("targetRecordKind")
                    if target not in VALID_DECISION_TARGET_KINDS:
                        errors.append(f"{d_where}: invalid targetRecordKind {target!r}")
                    if dec.get("collectionAllowed") is not False:
                        errors.append(f"{d_where}: collectionAllowed must be false for missing/incomplete evidence")
                    if dec.get("syntheticObligationsAllowed") is not False:
                        errors.append(f"{d_where}: syntheticObligationsAllowed must be false")
                    if target == "unanchored_evidence":
                        rc = dec.get("reasonCodes")
                        me = dec.get("missingEvidence")
                        if not isinstance(rc, list) or not rc:
                            errors.append(f"{d_where}: unanchored_evidence decision requires non-empty reasonCodes")
                        if not isinstance(me, list) or not me:
                            errors.append(f"{d_where}: unanchored_evidence decision requires non-empty missingEvidence")

            fixture_rel = profile.get("fixturePath")
            if fixture_rel is not None:
                if not isinstance(fixture_rel, str) or not fixture_rel:
                    errors.append(f"{where}: fixturePath must be a nonempty relative string")
                else:
                    fixture_path = repo_root / fixture_rel
                    if not fixture_path.is_file():
                        errors.append(f"{where}: referenced fixture does not exist: {fixture_rel}")
                    else:
                        fixture_doc = json.loads(fixture_path.read_text(encoding="utf-8"))
                        records = fixture_doc.get("records")
                        if not isinstance(records, list):
                            errors.append(f"{where}: fixture {fixture_rel} missing records list")
                        else:
                            if fixture_doc.get("recordCount") != len(records):
                                errors.append(f"{where}: fixture {fixture_rel} recordCount mismatch")
                            expected_rec_cs = "sha256:" + hashlib.sha256(
                                oracle.canonicalize(json.dumps(records, ensure_ascii=False))
                            ).hexdigest()
                            if fixture_doc.get("recordsChecksum") != expected_rec_cs:
                                errors.append(f"{where}: fixture {fixture_rel} recordsChecksum mismatch")
                            if isinstance(fixture_doc.get("artifactDigest"), str):
                                expected_art = canonical_digest(fixture_doc, "artifactDigest")
                                if fixture_doc["artifactDigest"] != expected_art:
                                    errors.append(f"{where}: fixture {fixture_rel} artifactDigest mismatch")

        for required_profile in ("generic", "reference-recurring", "reference-credits"):
            if required_profile not in seen_profile_ids:
                errors.append(f"profiles missing required product-agnostic profile '{required_profile}'")
        missing_conditions = [c for c in REQUIRED_MISSING_EVIDENCE_CONDITIONS if c not in seen_conditions]
        if missing_conditions:
            errors.append(f"missingEvidenceDecisions missing explicit coverage for: {missing_conditions}")

    # 5. Manifest canonical digest verification.
    declared_digest = document.get("manifestDigest")
    if not isinstance(declared_digest, str) or not DIGEST_RE.match(declared_digest):
        errors.append("manifestDigest must be a valid sha256 digest")
    else:
        expected_digest = canonical_digest(document, "manifestDigest")
        if declared_digest != expected_digest:
            errors.append(f"manifestDigest mismatch (expected {expected_digest}, got {declared_digest})")

    return errors


def check_source_mappings(repo_root: Path = ROOT, manifest_path: Path | None = None) -> list[str]:
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

    isolation_targets: list[Path] = [*positives, *negatives]

    if manifest_path is not None:
        resolved = manifest_path if manifest_path.is_absolute() else (repo_root / manifest_path)
        if not resolved.is_file():
            errors.append(f"source mapping manifest not found: {manifest_path}")
        else:
            doc = json.loads(resolved.read_text(encoding="utf-8"))
            for finding in validate_source_mapping_manifest(doc, repo_root):
                errors.append(f"{resolved.relative_to(repo_root) if resolved.is_relative_to(repo_root) else resolved}: {finding}")
            isolation_targets.append(resolved)
            contract_doc = repo_root / "docs/migrations/source-export-contract.md"
            if contract_doc.is_file():
                isolation_targets.append(contract_doc)
            for ref_dir in (
                repo_root / "fixtures/integration/import/reference-recurring",
                repo_root / "fixtures/integration/import/reference-credits",
            ):
                if ref_dir.is_dir():
                    isolation_targets.extend(sorted(ref_dir.rglob("*.json")))

    errors.extend(check_d71_isolation(isolation_targets, repo_root))
    return errors


def validate_handoff_document(
    document: dict[str, Any], validator: Draft202012Validator | None = None
) -> list[str]:
    """Check local fixture evidence shape and binding (D54/D63/F06). Live authentication is a host gate."""
    if not isinstance(document, dict):
        return ["Handoff evidence must be a JSON object"]
    if validator is None:
        validator = handoff_validator(REV)
    errors = [
        f"{error.json_path}: {error.message}"
        for error in validator.iter_errors(document)
    ]
    if document.get("usesCommitNoAsFreshnessProof") is True or document.get("reliesOnLocalActiveFlag") is True:
        errors.append("D63/F06 forbids commit_no freshness/completeness proofs and copied local active flags")
    if errors:
        return errors

    freeze = document["sourceFreezeCertificate"]
    manifest = document["migrationManifest"]
    tail = document["sealedTail"]
    target_receipt = document["targetReceipt"]
    retirement = document["activation"]["retirementProof"]
    certificate = document["activation"]["activationCertificate"]
    plan = manifest["plan"]
    source, target = plan["source"], plan["target"]
    source_feed, target_feed = source["feed"], target["feed"]

    if document["digest"] != canonical_digest(document):
        errors.append("Handoff evidence digest mismatch")
    if freeze != manifest["sourceFreezeCertificate"] or freeze["planDigest"] != canonical_digest(plan, None):
        errors.append("sourceFreezeCertificate scope or seal mismatch")
    if ((source["owner"]["installationRef"] == target["owner"]["installationRef"]
         and source["owner"]["authorityEpoch"] >= target["owner"]["authorityEpoch"])
        or source_feed["installationRef"] != source["owner"]["installationRef"]
        or target_feed["installationRef"] != target["owner"]["installationRef"]
        or any(source_feed[field] != target_feed[field] for field in ("sellerRef", "environment"))
        or any(scope[field] != sorted(scope[field]) for scope in (source, target) for field in ("books", "entityRefs"))):
        errors.append("Handoff source/target owner or scope mismatch")

    selection = {key: manifest[key] for key in ("eventContractRevision", "envelopeSchemas", "eventTypes")}
    if (any(selection[key] != sorted(selection[key]) for key in ("envelopeSchemas", "eventTypes"))
        or manifest["selectionDigest"] != canonical_digest({"scope": source_feed, **selection}, None)):
        errors.append("migrationManifest.selectionDigest scope or catalog mismatch")
    manifest_digest = canonical_digest(manifest, "manifestDigest")
    if manifest["manifestDigest"] != manifest_digest:
        errors.append("migrationManifest.manifestDigest mismatch")
    if tail["manifestDigest"] != manifest_digest or target_receipt["manifestDigest"] != manifest_digest:
        errors.append("Snapshot token, freeze, selection or retention evidence differs from the verified transfer")
    if cursor_position(tail["terminalCursor"]) < cursor_position(manifest["safeFeedCursor"]):
        errors.append("Sealed tail cursor regresses below the D52 checkpoint")
    if target_receipt["tailDigest"] != canonical_digest(tail, None) or target_receipt["adoptedCursor"] != tail["terminalCursor"]:
        errors.append("Target receipt tail digest or adopted cursor mismatch")

    checksums = manifest["perSellerCurrencyChecksums"]
    currencies = [summary["currency"] for summary in checksums]
    if (any(summary["sellerRef"] != source_feed["sellerRef"] for summary in checksums)
        or currencies != sorted(set(currencies))
        or checksums != target_receipt["perSellerCurrencyChecksums"]):
        errors.append("Source/target per-seller currency counts, totals or checksum mismatch")

    dates = (document["verifiedAt"], manifest["retentionLeaseExpiresAt"], retirement["issuedAt"], certificate["expiresAt"])
    try:
        verified_at, lease_expires_at, retired_at, certificate_expires_at = map(datetime.fromisoformat, dates)
        if any(value.tzinfo is None for value in (verified_at, lease_expires_at, retired_at, certificate_expires_at)):
            raise ValueError("missing UTC offset")
    except ValueError:
        return [*errors, "Invalid timezone-qualified handoff evidence timestamp"]
    if lease_expires_at <= verified_at:
        errors.append("D52 source retention lease expired at verification")
    if (retirement["oldOwner"] != source["owner"] or certificate["newOwner"] != target["owner"]
        or certificate["retirementProofDigest"] != retirement["digest"]
        or certificate["backupLineageRef"] != source_feed["lineage"]
        or retired_at > verified_at
        or certificate_expires_at <= verified_at
        or certificate["manifestDigest"] != manifest_digest
        or certificate["targetReceiptDigest"] != canonical_digest(target_receipt, None)):
        errors.append("RetirementProof or ActivationCertificate context/lineage mismatch")
    return errors


def cursor_position(cursor: dict[str, str]) -> tuple[int, int]:
    return int(cursor["txId"]), int(cursor["id"])


def handoff_validator(revision: Path) -> Draft202012Validator:
    registry, documents = build_registry(revision)
    return Draft202012Validator(documents["migration/handoff-evidence.v1.json"], registry=registry, format_checker=FormatChecker())


def check_handoff(repo_root: Path = ROOT) -> list[str]:
    errors: list[str] = []
    handoff_dir = repo_root / "fixtures/integration/import/schema/handoff"
    positives = sorted(handoff_dir.glob("valid-*.json")) if handoff_dir.is_dir() else []
    negatives = sorted(handoff_dir.glob("negative-*.json")) if handoff_dir.is_dir() else []
    if not positives:
        errors.append("no valid-*.json handoff fixtures under fixtures/integration/import/schema/handoff/")
    if not negatives:
        errors.append("no negative-*.json handoff fixtures under fixtures/integration/import/schema/handoff/")

    validator = handoff_validator(repo_root / "spec/revisions/2026-10")
    for path in positives:
        doc = json.loads(path.read_text(encoding="utf-8"))
        for finding in validate_handoff_document(doc, validator):
            errors.append(f"{path.name}: {finding}")

    for path in negatives:
        doc = json.loads(path.read_text(encoding="utf-8"))
        findings = validate_handoff_document(doc, validator)
        if not findings:
            errors.append(f"{path.name}: negative handoff fixture unexpectedly passed")

    isolation_targets: list[Path] = [
        *positives,
        *negatives,
        *sorted((repo_root / "spec/revisions/2026-10/schema/migration").glob("*.json")),
        *migration_php_targets(repo_root),
    ]
    errors.extend(check_d71_isolation(isolation_targets, repo_root))
    return errors


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    group = parser.add_mutually_exclusive_group(required=True)
    group.add_argument("--check", action="store_true", help="validate schemas, type-map, source identity and D71 isolation")
    group.add_argument(
        "--source-mappings",
        nargs="?",
        const="__default__",
        default=None,
        metavar="PATH",
        help="validate deterministic mapping namespaces, checksums and optional versioned source-mapping manifest",
    )
    group.add_argument("--handoff", action="store_true", help="validate handoff closure and authority evidence")
    parser.add_argument("--repo-root", type=Path, default=ROOT, help="repository root directory")
    args = parser.parse_args(argv)

    repo = args.repo_root.resolve()
    if args.check:
        mode = "check"
        errors = check_import_contract(repo)
    elif args.source_mappings is not None:
        mode = "source-mappings"
        manifest_path = None if args.source_mappings == "__default__" else Path(args.source_mappings)
        errors = check_source_mappings(repo, manifest_path=manifest_path)
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
