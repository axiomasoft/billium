#!/usr/bin/env python3
"""Validator for consumer/bridge contract reports and external-runtime activation evidence (P9.5 / V952)."""

from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import re
import sys
from typing import Any

sys.dont_write_bytecode = True
HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[2]
if str(HERE) not in sys.path:
    sys.path.insert(0, str(HERE))

from protocol import (  # noqa: E402
    REPORT_PROTOCOL_ID,
    REQUIRED_CASES,
    canonical_digest,
    check_no_d71_forbidden_terms,
    current_spec_hash,
    load_json,
    schema_validator,
)

DIGEST_RE = re.compile(r"^sha256:[0-9a-f]{64}$")
COMMIT_RE = re.compile(r"^[0-9a-f]{7,64}$")
ALLOWED_EVIDENCE_CLASSES = {"consumer-contract", "transport-contract", "provider-double", "host-double"}
ALLOWED_BOUNDARIES = {"double", "loopback", "external-runtime"}
BUNDLED_REFERENCE_ADAPTER_NAME = "billium-reference-bridge-consumer"


def bundled_reference_package_hash(repo_root: Path = ROOT) -> str | None:
    files = (
        repo_root / "packages/contracts/composer.json",
        repo_root / "conformance/consumers/bridge/BridgeReferenceAdapter.php",
    )
    if not all(f.is_file() for f in files):
        return None
    acc = "".join(hashlib.sha256(f.read_bytes()).hexdigest() for f in files)
    return "sha256:" + hashlib.sha256(acc.encode("ascii")).hexdigest()


def validate_report(
    report: dict[str, Any],
    *,
    repo_root: Path = ROOT,
    verify_workspace_spec: bool = True,
) -> list[str]:
    """Return all findings for a consumer contract report. Empty list means valid."""
    errors: list[str] = []
    if not isinstance(report, dict):
        return ["report_not_object: report must be a JSON object"]

    raw_text = json.dumps(report, ensure_ascii=False)
    errors.extend(check_no_d71_forbidden_terms(raw_text, "report"))

    # 1. Explicit evidence_class and protocol checks before full schema validation for clear diagnostics.
    if report.get("protocol") != REPORT_PROTOCOL_ID:
        errors.append(f"invalid_protocol: expected {REPORT_PROTOCOL_ID!r}, got {report.get('protocol')!r}")
    if report.get("evidence_class") != "consumer-contract":
        errors.append(
            f"wrong_evidence_class: top-level evidence_class must be 'consumer-contract', got {report.get('evidence_class')!r}"
        )
    boundary = report.get("boundary")
    if boundary not in ALLOWED_BOUNDARIES:
        errors.append(f"invalid_boundary: unsupported boundary {boundary!r}")

    if report.get("paidPspTransactions") != 0:
        errors.append(f"paid_psp_forbidden: paidPspTransactions must be 0, got {report.get('paidPspTransactions')!r}")

    # 2. JSON Schema validation.
    validator = schema_validator("report")
    for err in sorted(validator.iter_errors(report), key=lambda item: list(item.absolute_path)):
        where = "/".join(str(part) for part in err.absolute_path) or "<root>"
        errors.append(f"schema_error@{where}: {err.message}")

    # 3. Producer & consumer commit/package/spec hash checks.
    expected_spec = current_spec_hash(repo_root) if verify_workspace_spec else None
    producer = report.get("producer") if isinstance(report.get("producer"), dict) else {}
    consumer = report.get("consumer") if isinstance(report.get("consumer"), dict) else {}
    hashes = report.get("hashes") if isinstance(report.get("hashes"), dict) else {}

    for side_name, side, allow_null in (("producer", producer, False), ("consumer", consumer, True)):
        available = True if side_name == "producer" else bool(side.get("available"))
        if not available and allow_null:
            if report.get("status") != "pending" or boundary != "external-runtime":
                errors.append("invalid_unavailable_consumer: consumer.available=false is only valid when boundary=external-runtime and status=pending")
            continue
        commit_val = side.get("commitHash")
        pkg_val = side.get("packageHash")
        spec_val = side.get("specHash")
        if not isinstance(commit_val, str) or not COMMIT_RE.match(commit_val):
            errors.append(f"invalid_hash: {side_name}.commitHash is missing or malformed")
        if not isinstance(pkg_val, str) or not DIGEST_RE.match(pkg_val):
            errors.append(f"invalid_hash: {side_name}.packageHash is missing or malformed")
        if not isinstance(spec_val, str) or not DIGEST_RE.match(spec_val):
            errors.append(f"invalid_hash: {side_name}.specHash is missing or malformed")
        elif expected_spec is not None and spec_val != expected_spec:
            errors.append(f"wrong_spec_hash: {side_name}.specHash {spec_val} != workspace spec {expected_spec}")

    if isinstance(hashes.get("spec"), str):
        if expected_spec is not None and hashes["spec"] != expected_spec:
            errors.append(f"wrong_spec_hash: hashes.spec {hashes['spec']} != workspace spec {expected_spec}")
        if isinstance(producer.get("specHash"), str) and hashes["spec"] != producer["specHash"]:
            errors.append("wrong_spec_hash: hashes.spec differs from producer.specHash")

    for h_key in ("spec", "fixtures", "harness", "packages", "dependencies"):
        val = hashes.get(h_key)
        if not isinstance(val, str) or not DIGEST_RE.match(val):
            errors.append(f"invalid_hash: hashes.{h_key} missing or malformed")

    # 4. Case inventory & unknown/duplicate case rejection.
    if report.get("required") != list(REQUIRED_CASES):
        errors.append("invalid_required_inventory: report.required must equal CT-01..CT-20")

    results = report.get("results")
    seen_ids: list[str] = []
    if not isinstance(results, list):
        errors.append("invalid_results: results must be a list")
        results = []

    for idx, entry in enumerate(results):
        if not isinstance(entry, dict):
            errors.append(f"invalid_result_item: results[{idx}] must be an object")
            continue
        cid = entry.get("id")
        if not isinstance(cid, str) or cid not in REQUIRED_CASES:
            errors.append(f"unknown_case: results[{idx}].id {cid!r} is not in CT-01..CT-20")
            continue
        if cid in seen_ids:
            errors.append(f"duplicate_case: {cid} appears more than once")
        seen_ids.append(cid)

        # Authority source/profile must stay distinct from package delivery.
        if entry.get("authoritySource") == entry.get("packageDelivery") or entry.get("executionProfile") == entry.get("packageDelivery"):
            errors.append(f"conflated_authority_delivery: {cid} conflates authoritySource/executionProfile with packageDelivery")

        for step_entry in entry.get("transcript", []):
            if not isinstance(step_entry, dict):
                continue
            ev_cls = step_entry.get("evidence_class")
            ev_bnd = step_entry.get("boundary")
            if ev_cls not in ALLOWED_EVIDENCE_CLASSES:
                errors.append(f"wrong_evidence_class: {cid} step has invalid evidence_class {ev_cls!r}")
            if ev_cls in {"provider-double", "host-double"} and ev_bnd != "double":
                errors.append(f"wrong_evidence_boundary: {cid} double step cannot claim boundary {ev_bnd!r}")
            if boundary == "double" and ev_bnd != "double":
                errors.append(f"double_promotion_forbidden: {cid} step in boundary=double report claimed {ev_bnd!r}")

    # 5. Evidence summary checks.
    evidence_summary = report.get("evidence") if isinstance(report.get("evidence"), list) else []
    has_consumer_contract_steps = False
    for ev in evidence_summary:
        if not isinstance(ev, dict):
            continue
        cls = ev.get("evidence_class")
        bnd = ev.get("boundary")
        steps = ev.get("steps") if isinstance(ev.get("steps"), list) else []
        if cls not in ALLOWED_EVIDENCE_CLASSES:
            errors.append(f"wrong_evidence_class: evidence summary contains {cls!r}")
        if cls in {"provider-double", "host-double"} and bnd != "double":
            errors.append(f"double_promotion_forbidden: {cls} cannot claim boundary {bnd!r}")
        if cls == "consumer-contract" and steps:
            has_consumer_contract_steps = True

    # 6. Status, boundary, external readiness and rollout gate invariants (D59, D71).
    status = report.get("status")
    passed = report.get("passed")
    local_complete = report.get("localRequiredSuiteComplete")
    external_ready = report.get("externalReady")
    rollout_passed = report.get("rolloutGatePassed")

    all_required_present = set(seen_ids) == set(REQUIRED_CASES) and len(seen_ids) == len(REQUIRED_CASES)
    all_results_pass = bool(results) and all(isinstance(r, dict) and r.get("status") == "pass" for r in results)

    if status == "pending":
        if boundary != "external-runtime":
            errors.append("invalid_pending_boundary: only boundary='external-runtime' may report status='pending'")
        if passed is not False:
            errors.append("pending_cannot_pass: pending external report must set passed=false")
        if external_ready is not False or rollout_passed is not False:
            errors.append("pending_rollout_must_be_false: absent external environment must set externalReady=false and rolloutGatePassed=false")
        if not isinstance(report.get("pendingReason"), str) or not report["pendingReason"].strip():
            errors.append("missing_pending_reason: pending external report requires non-empty pendingReason")
    elif status == "passed":
        if passed is not True or not all_results_pass:
            errors.append("inconsistent_passed_state: status='passed' requires passed=true and all executed cases status='pass'")
        if not has_consumer_contract_steps:
            errors.append("missing_consumer_contract_evidence: passed report has zero consumer-contract steps")
    elif status == "failed":
        if passed is not False:
            errors.append("inconsistent_failed_state: status='failed' requires passed=false")
        if external_ready is True or rollout_passed is True or local_complete is True:
            errors.append("failed_cannot_claim_readiness: failed report cannot set readiness or completion flags true")

    if local_complete is True:
        if boundary not in {"loopback", "external-runtime"}:
            errors.append("double_promotion_forbidden: boundary='double' cannot satisfy localRequiredSuiteComplete")
        if not (status == "passed" and all_required_present and all_results_pass):
            errors.append("incomplete_required_suite: localRequiredSuiteComplete=true requires all CT-01..20 passed")

    if boundary in {"double", "loopback"}:
        if external_ready is True or rollout_passed is True:
            errors.append(
                f"double_or_loopback_promotion_forbidden: boundary={boundary!r} cannot set externalReady=true or rolloutGatePassed=true"
            )

    if boundary == "external-runtime" and (external_ready is True or rollout_passed is True):
        if status != "passed" or not consumer.get("available") or not all_required_present or not all_results_pass:
            errors.append("external_ready_without_complete_pass: externalReady/rolloutGatePassed requires available external consumer and CT-01..20 passed")
        if consumer.get("deliveryProfile") != "external-http":
            errors.append("external_ready_wrong_delivery_profile: external-runtime readiness requires deliveryProfile='external-http'")
        if consumer.get("packageHash") == producer.get("packageHash"):
            errors.append("external_ready_self_loopback: external-runtime consumer packageHash must come from an independent external consumer")
        bundled_pkg_hash = bundled_reference_package_hash(repo_root)
        if (
            consumer.get("isBundledReference") is True
            or consumer.get("name") == BUNDLED_REFERENCE_ADAPTER_NAME
            or (bundled_pkg_hash is not None and consumer.get("packageHash") == bundled_pkg_hash)
        ):
            errors.append(
                "external_ready_bundled_reference_forbidden: bundled reference adapter cannot set externalReady=true or rolloutGatePassed=true"
            )

    # 7. Canonical JCS reportDigest check.
    declared_digest = report.get("reportDigest")
    if not isinstance(declared_digest, str) or not DIGEST_RE.match(declared_digest):
        errors.append("invalid_report_digest: reportDigest missing or malformed")
    else:
        expected_digest = canonical_digest(report, "reportDigest")
        if declared_digest != expected_digest:
            errors.append(f"wrong_report_digest: expected {expected_digest}, got {declared_digest}")

    return errors


def check_fixture_directory(fixture_dir: Path, repo_root: Path = ROOT) -> list[str]:
    errors: list[str] = []
    if not fixture_dir.is_dir():
        return [f"fixture directory does not exist: {fixture_dir}"]

    positives = sorted(fixture_dir.glob("valid-*.json"))
    negatives = sorted(fixture_dir.glob("negative-*.json"))
    if not positives:
        errors.append(f"no valid-*.json report fixtures found in {fixture_dir}")
    if not negatives:
        errors.append(f"no negative-*.json report fixtures found in {fixture_dir}")

    for path in positives:
        doc = load_json(path)
        findings = validate_report(doc, repo_root=repo_root, verify_workspace_spec=True)
        for item in findings:
            errors.append(f"{path.name}: unexpected validation failure: {item}")

    for path in negatives:
        wrapper = load_json(path)
        expected_code = wrapper.get("expectedErrorPrefix")
        report_doc = wrapper.get("report")
        if not isinstance(expected_code, str) or not expected_code or not isinstance(report_doc, dict):
            errors.append(f"{path.name}: negative fixture must contain 'expectedErrorPrefix' and object 'report'")
            continue
        findings = validate_report(report_doc, repo_root=repo_root, verify_workspace_spec=True)
        if not findings:
            errors.append(f"{path.name}: negative report fixture unexpectedly PASSED validation")
        elif not any(finding.startswith(expected_code) for finding in findings):
            errors.append(
                f"{path.name}: expected finding starting with {expected_code!r}, got {findings!r}"
            )

    return errors


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    group = parser.add_mutually_exclusive_group(required=True)
    group.add_argument("--report", type=Path, help="Validate a single consumer contract report JSON file")
    group.add_argument("--fixture", type=Path, help="Validate positive and negative report fixtures in a directory")
    parser.add_argument("--require-external-ready", action="store_true", help="Require externalReady=true and rolloutGatePassed=true")
    parser.add_argument("--repo-root", type=Path, default=ROOT)
    args = parser.parse_args(argv)

    repo_root = args.repo_root.resolve()
    if args.fixture is not None:
        fixture_dir = args.fixture if args.fixture.is_absolute() else (repo_root / args.fixture)
        findings = check_fixture_directory(fixture_dir, repo_root=repo_root)
    else:
        report_path = args.report if args.report.is_absolute() else (repo_root / args.report)
        if not report_path.is_file():
            print(f"check-report: missing report file {report_path}", file=sys.stderr)
            return 1
        report_doc = load_json(report_path)
        findings = validate_report(report_doc, repo_root=repo_root, verify_workspace_spec=True)
        if args.require_external_ready and (
            report_doc.get("externalReady") is not True or report_doc.get("rolloutGatePassed") is not True
        ):
            findings.append("external_not_ready: report does not satisfy external activation readiness")

    for line in findings:
        print(f"check-report: {line}", file=sys.stderr)
    if findings:
        print(f"check-report: FAILED ({len(findings)} finding(s))", file=sys.stderr)
        return 1
    print("check-report: OK")
    return 0


if __name__ == "__main__":
    sys.exit(main())
