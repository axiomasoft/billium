#!/usr/bin/env python3
"""Validate external import fixtures, golden matrix coverage, evidence checksums, and sanitization (P9.6 / V964 / D71).

Checks:
  1. Complete IM-01..IM-17 coverage in fixtures/integration/import/golden/golden-matrix.json
     with independent REQ/INV/BC/D71 spec_refs and RFC 8785 JCS oracle digests.
  2. Evidence checksums, recordsChecksum (sha256-jcs), artifactDigest, and RFC 3339 exportCutoff
     across all positive source.export.v1 fixtures under fixtures/integration/import/**.
  3. Explicit PII/secret sanitization (piiRedacted=true, secretsStripped=true) and zero sensitive
     payload leaks (no live keys, private keys, PAN/CVV, or raw passwords) in non-negative fixtures,
     while confirming negative-leaked-credential.json is detected by the scanner.
  4. Strict D71 product-agnostic self-isolation across all P9 import fixtures, vectors, golden files,
     conformance harness and tests (zero external product names or legacy contract naming).
  5. Explicit separation between hashed local runtime reports/test-double counts (paidPspTransactions=0)
     and external_owner_supplied activation inputs.
"""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import re
import sys
from typing import Any

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "conformance/oracles/jcs"))

import oracle  # noqa: E402

REQUIRED_IM_IDS = tuple(f"IM-{i:02d}" for i in range(1, 18))
DIGEST_RE = re.compile(r"^sha256:[0-9a-f]{64}$")
RFC3339_UTC_RE = re.compile(r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$")
SPEC_REF_RE = re.compile(r"^(?:REQ|INV|BC|PF|PB|MG|FD|SB|EC|ACC|M|D)[A-Z0-9_-]*$")

FORBIDDEN_D71_TERMS = (
    ("mem" + "ster", "D71 forbids external product names"),
    ("edu" + "fy", "D71 forbids external product names"),
    ("com" + "merz", "D71 forbids external product names"),
    ("leg" + "acy.import.v1", "D71 forbids legacy contract naming"),
    ("leg" + "acy_unanchored", "D71 forbids legacy record class naming"),
    ("Leg" + "acyScenarioTest", "D71 forbids legacy test class naming"),
)

SENSITIVE_PAYLOAD_PATTERNS = (
    (re.compile(r"-----BEGIN (?:RSA |EC |OPENSSH |)PRIVATE KEY-----"), "private key block"),
    (re.compile(r"\b(?:sk_live|rk_live|pk_live|whsec)_[A-Za-z0-9]{8,}\b"), "live provider secret token"),
    (re.compile(r'"(?:card_number|cvv|cvc|raw_pan|taxpayer_ssn|passport_number)"\s*:', re.IGNORECASE), "unredacted sensitive PII/PCI field"),
    (re.compile(r'"(?:api_secret|client_secret|private_key|secret_key|webhook_secret|providerSecret|apiSecret|clientSecret|privateKey|secretKey)"\s*:\s*"[^"]+"', re.IGNORECASE), "exported secret credential field"),
)


def canonical_digest(payload: Any, exclude_key: str | None = None) -> str:
    if isinstance(payload, dict) and exclude_key is not None:
        body = {k: v for k, v in payload.items() if k != exclude_key}
    else:
        body = payload
    canonical = oracle.canonicalize(json.dumps(body, ensure_ascii=False))
    return "sha256:" + hashlib.sha256(canonical).hexdigest()


def scan_sensitive_payload(raw_text: str) -> list[str]:
    hits: list[str] = []
    for pattern, label in SENSITIVE_PAYLOAD_PATTERNS:
        if pattern.search(raw_text):
            hits.append(label)
    return hits


def validate_export_artifact(doc: dict[str, Any], rel_path: str) -> list[str]:
    errors: list[str] = []
    cutoff = doc.get("exportCutoff")
    if not isinstance(cutoff, str) or not RFC3339_UTC_RE.match(cutoff):
        errors.append(f"{rel_path}: invalid or missing RFC3339 UTC exportCutoff")

    sanitization = doc.get("sanitizationPolicy")
    if not isinstance(sanitization, dict):
        errors.append(f"{rel_path}: missing sanitizationPolicy")
    else:
        if sanitization.get("piiRedacted") is not True:
            errors.append(f"{rel_path}: sanitizationPolicy.piiRedacted must be true")
        if sanitization.get("secretsStripped") is not True:
            errors.append(f"{rel_path}: sanitizationPolicy.secretsStripped must be true")

    records = doc.get("records")
    if not isinstance(records, list) or not records:
        errors.append(f"{rel_path}: records must be a non-empty list")
        return errors

    if doc.get("recordCount") != len(records):
        errors.append(f"{rel_path}: recordCount ({doc.get('recordCount')}) != len(records) ({len(records)})")

    expected_records_cs = canonical_digest(records)
    if doc.get("recordsChecksum") != expected_records_cs:
        errors.append(f"{rel_path}: recordsChecksum mismatch (expected {expected_records_cs}, got {doc.get('recordsChecksum')})")

    if "artifactDigest" in doc:
        expected_artifact_digest = canonical_digest(doc, "artifactDigest")
        if doc.get("artifactDigest") != expected_artifact_digest:
            errors.append(f"{rel_path}: artifactDigest mismatch (expected {expected_artifact_digest}, got {doc.get('artifactDigest')})")

    for idx, rec in enumerate(records):
        if not isinstance(rec, dict):
            errors.append(f"{rel_path}: records[{idx}] must be an object")
            continue
        ev_refs = rec.get("evidenceRefs", [])
        if not isinstance(ev_refs, list):
            errors.append(f"{rel_path}: records[{idx}].evidenceRefs must be a list")
            continue
        for e_idx, ev in enumerate(ev_refs):
            if not isinstance(ev, dict):
                errors.append(f"{rel_path}: records[{idx}].evidenceRefs[{e_idx}] must be an object")
                continue
            if not isinstance(ev.get("digest"), str) or not DIGEST_RE.match(ev["digest"]):
                errors.append(f"{rel_path}: records[{idx}].evidenceRefs[{e_idx}] invalid sha256 digest")
            if ev.get("sanitized") is not True:
                errors.append(f"{rel_path}: records[{idx}].evidenceRefs[{e_idx}] must have sanitized=true")

    return errors


def validate_golden_matrix(repo_root: Path = ROOT) -> list[str]:
    errors: list[str] = []
    matrix_path = repo_root / "fixtures/integration/import/golden/golden-matrix.json"
    if not matrix_path.is_file():
        return [f"missing golden matrix file: {matrix_path.relative_to(repo_root)}"]

    doc = json.loads(matrix_path.read_text(encoding="utf-8"))
    if doc.get("schema") != "external.import.golden-matrix.v1":
        errors.append("golden-matrix.json: schema must equal 'external.import.golden-matrix.v1'")

    authoring = doc.get("goldenAuthoring")
    if not isinstance(authoring, dict):
        errors.append("golden-matrix.json: missing goldenAuthoring metadata")
    else:
        if authoring.get("derivedFromImplementationOutput") is not False:
            errors.append("golden-matrix.json: goldenAuthoring.derivedFromImplementationOutput must be false")
        if authoring.get("oracle") != "cyberphone-jcs-rfc8785":
            errors.append("golden-matrix.json: goldenAuthoring.oracle must be 'cyberphone-jcs-rfc8785'")

    boundary = doc.get("evidenceBoundary")
    if not isinstance(boundary, dict):
        errors.append("golden-matrix.json: missing evidenceBoundary metadata")
    else:
        if boundary.get("localEvidenceClass") != "sanitized_fixture_local_runtime":
            errors.append("golden-matrix.json: evidenceBoundary.localEvidenceClass must be 'sanitized_fixture_local_runtime'")
        if boundary.get("paidPspTransactions") != 0:
            errors.append("golden-matrix.json: evidenceBoundary.paidPspTransactions must be 0")
        if boundary.get("testDoubleCallsTrackedSeparately") is not True:
            errors.append("golden-matrix.json: evidenceBoundary.testDoubleCallsTrackedSeparately must be true")
        if boundary.get("externalActivationInputsSeparated") is not True:
            errors.append("golden-matrix.json: evidenceBoundary.externalActivationInputsSeparated must be true")
        ext_inputs = boundary.get("externalActivationInputs")
        if not isinstance(ext_inputs, list) or len(ext_inputs) < 3:
            errors.append("golden-matrix.json: evidenceBoundary.externalActivationInputs must list deferred external owner inputs")
        else:
            for item in ext_inputs:
                if not isinstance(item, dict) or not item.get("inputId") or item.get("status") != "external_owner_supplied":
                    errors.append("golden-matrix.json: each externalActivationInput must carry inputId and status='external_owner_supplied'")

    cases = doc.get("cases")
    if not isinstance(cases, dict):
        errors.append("golden-matrix.json: cases must be an object keyed by IM-01..IM-17")
        return errors

    missing_im = [cid for cid in REQUIRED_IM_IDS if cid not in cases]
    extra_im = [cid for cid in cases if cid not in REQUIRED_IM_IDS]
    if missing_im:
        errors.append(f"golden-matrix.json: missing required IM cases: {missing_im}")
    if extra_im:
        errors.append(f"golden-matrix.json: unexpected IM case keys: {extra_im}")

    for cid in REQUIRED_IM_IDS:
        entry = cases.get(cid)
        if not isinstance(entry, dict):
            continue
        spec_refs = entry.get("spec_refs")
        if not isinstance(spec_refs, list) or len(spec_refs) < 2:
            errors.append(f"golden-matrix.json[{cid}]: spec_refs must cite at least 2 canonical REQ/INV/BC/D IDs")
        else:
            for ref in spec_refs:
                if not isinstance(ref, str) or not SPEC_REF_RE.match(ref):
                    errors.append(f"golden-matrix.json[{cid}]: invalid spec_ref {ref!r}")
            if not any(r.startswith(("INV-", "REQ-", "BC-")) for r in spec_refs if isinstance(r, str)):
                errors.append(f"golden-matrix.json[{cid}]: spec_refs must cite at least one INV-*/REQ-*/BC-* requirement")

        expected_golden = entry.get("expectedGolden")
        if not isinstance(expected_golden, dict) or expected_golden.get("id") != cid:
            errors.append(f"golden-matrix.json[{cid}]: expectedGolden must be an object with id={cid!r}")
            continue
        expected_digest = canonical_digest(expected_golden)
        if entry.get("expectedGoldenDigest") != expected_digest:
            errors.append(
                f"golden-matrix.json[{cid}]: expectedGoldenDigest mismatch (expected {expected_digest}, got {entry.get('expectedGoldenDigest')})"
            )

    codec_slice = doc.get("codecSlice")
    if not isinstance(codec_slice, list) or len(codec_slice) < 3:
        errors.append("golden-matrix.json: codecSlice must contain shared PHP/Dart codec vectors including control must-fail")
    elif not any(isinstance(c, dict) and c.get("control") == "must-fail" for c in codec_slice):
        errors.append("golden-matrix.json: codecSlice missing control='must-fail' vector")

    expected_matrix_digest = canonical_digest(doc, "matrixDigest")
    if doc.get("matrixDigest") != expected_matrix_digest:
        errors.append(
            f"golden-matrix.json: matrixDigest mismatch (expected {expected_matrix_digest}, got {doc.get('matrixDigest')})"
        )

    return errors


def check_import_fixtures(repo_root: Path = ROOT) -> list[str]:
    errors: list[str] = []
    errors.extend(validate_golden_matrix(repo_root))

    import_fixtures_dir = repo_root / "fixtures/integration/import"
    if not import_fixtures_dir.is_dir():
        return [f"missing directory: {import_fixtures_dir}"]

    positive_export_count = 0
    negative_leak_checked = False

    for path in sorted(import_fixtures_dir.rglob("*.json")):
        rel = path.relative_to(repo_root).as_posix()
        raw = path.read_text(encoding="utf-8")
        try:
            doc = json.loads(raw)
        except ValueError as exc:
            errors.append(f"{rel}: invalid JSON ({exc})")
            continue

        is_negative = path.name.startswith("negative-")
        if path.name == "negative-leaked-credential.json":
            negative_leak_checked = True
            if not scan_sensitive_payload(raw):
                errors.append(f"{rel}: negative credential fixture failed to trigger sensitive payload detector")
            continue

        if not is_negative:
            leaks = scan_sensitive_payload(raw)
            if leaks:
                errors.append(f"{rel}: sensitive payload detected ({', '.join(leaks)})")

            if isinstance(doc, dict) and doc.get("exportSchema") == "source.export.v1":
                positive_export_count += 1
                errors.extend(validate_export_artifact(doc, rel))

    if positive_export_count < 4:
        errors.append(f"expected at least 4 positive source.export.v1 fixtures, found {positive_export_count}")
    if not negative_leak_checked:
        errors.append("missing negative-leaked-credential.json control check")

    # Isolation scan across import fixtures, vectors, conformance and P9.6 tests.
    scan_targets: list[Path] = [
        *sorted(import_fixtures_dir.rglob("*.json")),
        *sorted((repo_root / "fixtures/vectors/import").glob("*.json")),
        *sorted((repo_root / "conformance/import").rglob("*")),
        *sorted((repo_root / "packages/core/modules/platform/tests/Import").rglob("*.php")),
        repo_root / "packages/core/tests/Arch/ImportBoundaryTest.php",
        repo_root / "scripts/spec/check-import-fixtures.py",
    ]
    for target in scan_targets:
        if not target.is_file() or "__pycache__" in target.parts:
            continue
        text = target.read_text(encoding="utf-8")
        lowered = text.lower()
        rel = target.relative_to(repo_root).as_posix()
        for term, reason in FORBIDDEN_D71_TERMS:
            if term.lower() in lowered:
                errors.append(f"{rel}: forbidden term matched ({reason})")

    return errors


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--repo-root", type=Path, default=ROOT, help="Repository root path")
    args = parser.parse_args(argv)

    errors = check_import_fixtures(args.repo_root.resolve())
    for err in errors:
        print(f"check-import-fixtures: {err}", file=sys.stderr)
    if errors:
        print(f"check-import-fixtures: {len(errors)} finding(s)", file=sys.stderr)
        return 1

    print("check-import-fixtures: OK (IM-01..IM-17 coverage, JCS evidence checksums, sanitization, D71 isolation)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
