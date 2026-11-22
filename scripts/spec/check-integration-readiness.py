#!/usr/bin/env python3
"""Validate Billium P9 integration readiness manifest, artifact hashes, evidence boundaries and D71 isolation (P9.8 / V981)."""
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

SCHEMA_ID = "billium.integration.readiness.v1"
DIGEST_RE = re.compile(r"^sha256:[0-9a-f]{64}$")
DECISION_RE = re.compile(r"^D\d+$")

ALLOWED_STATES = {"passed", "pending", "failed", "not-applicable"}
ALLOWED_CATEGORIES = {
    "local-mandatory",
    "migration-activation",
    "remote-execution-activation",
    "composition-disposition",
    "phase-closure",
}
ALLOWED_EVIDENCE_CLASSES = {
    "static-contract",
    "import-codec-conformance",
    "import-behavior-conformance",
    "consumer-contract",
    "migration-drill",
    "external-activation-input",
    "provider-live",
    "composition-decision",
    "independent-phase-review",
}
ALLOWED_BOUNDARIES = {
    "static",
    "codec-slice",
    "sanitized-fixture-postgresql",
    "loopback",
    "postgresql-worker-restore-fixture",
    "double",
    "external-owner-supplied",
    "external-runtime",
    "provider-live",
    "decision-record",
    "independent-review",
}
LOCAL_NON_LIVE_BOUNDARIES = {
    "double",
    "loopback",
    "sanitized-fixture-postgresql",
    "postgresql-worker-restore-fixture",
    "codec-slice",
    "static",
}

REQUIRED_IM_IDS = [f"IM-{i:02d}" for i in range(1, 18)]
REQUIRED_CT_IDS = [f"CT-{i:02d}" for i in range(1, 21)]
REQUIRED_MGATE_IDS = [f"MGATE-{i:02d}" for i in range(1, 12)]
REQUIRED_CATALOG_P9_IDS = ["PB-12", "PB-21", "PF-09", "PF-13", "PF-14", "MG-11", "ACC-CON-16", "ACC-RST-07"]

REQUIRED_TRACKED_PATHS = {
    "spec/revisions/2026-10/revision.lock.json",
    "docs/migrations/source-mapping.json",
    "fixtures/integration/import/golden/golden-matrix.json",
    "fixtures/integration/import/schema/handoff/valid-handoff-evidence.json",
    "artifacts/migration-drill-manifest.json",
    "artifacts/server-readiness.json",
    "docs/operations/integration-readiness.md",
}

REQUIRED_EXTERNAL_INPUT_KEYS = {
    "named_operational_owner",
    "slo_and_escalation_policy",
    "retention_floor_policy",
    "backup_and_pitr_restore_policy",
    "selected_psp_and_driver_qualification",
    "approved_seller_and_currency_scope",
    "owner_approved_source_price_mapping",
    "source_freeze_and_seal_certificate",
    "target_reconciliation_and_cursor_proof",
    "retirement_and_activation_certificates",
    "external_consumer_runtime_report",
}

REQUIRED_LOCAL_GATE_IDS = {
    "GATE-LOCAL-IMPORT-CONTRACT",
    "GATE-LOCAL-IMPORT-FIXTURES",
    "GATE-LOCAL-IMPORT-PHP",
    "GATE-LOCAL-IMPORT-DART-CODEC",
    "GATE-LOCAL-CONSUMER-LOOPBACK",
    "GATE-LOCAL-MIGRATION-DRILLS",
}

FORBIDDEN_D71_TERMS = (
    ("mem" + "ster", "D71 forbids external product names"),
    ("edu" + "fy", "D71 forbids external product names"),
    ("com" + "merz", "D71 forbids external product names"),
    ("leg" + "acy.import.v1", "D71 forbids legacy contract naming"),
    ("leg" + "acy_unanchored", "D71 forbids legacy record class naming"),
    ("Leg" + "acyScenarioTest", "D71 forbids legacy test class naming"),
)


def file_sha256(path: Path) -> str:
    return "sha256:" + hashlib.sha256(path.read_bytes()).hexdigest()


def canonical_digest(payload: dict[str, Any], exclude_key: str | None = None) -> str:
    body = {k: v for k, v in payload.items() if k != exclude_key} if exclude_key else payload
    raw = oracle.canonicalize(json.dumps(body, ensure_ascii=False))
    return "sha256:" + hashlib.sha256(raw).hexdigest()


def scan_d71_text(raw_text: str, label: str) -> list[str]:
    lowered = raw_text.lower()
    hits: list[str] = []
    for term, reason in FORBIDDEN_D71_TERMS:
        if term.lower() in lowered:
            hits.append(f"d71_forbidden_term: {label} matched forbidden term ({reason})")
    return hits


def _non_empty_str(value: Any) -> bool:
    return isinstance(value, str) and bool(value.strip())


def validate_runtime_report_file(report_entry: dict[str, Any], repo_root: Path, require_present: bool) -> list[str]:
    errors: list[str] = []
    rid = report_entry.get("id", "<unknown>")
    rel_path = report_entry.get("path")
    if not _non_empty_str(rel_path):
        return [f"invalid_runtime_report: {rid} missing path"]
    if report_entry.get("paid_psp_transactions") != 0:
        errors.append(f"paid_psp_forbidden: runtime report {rid} must record paid_psp_transactions=0")
    if report_entry.get("exit") != 0:
        errors.append(f"runtime_report_failed: runtime report {rid} must have exit=0")
    for field in ("author", "command", "timestamp"):
        if not _non_empty_str(report_entry.get(field)):
            errors.append(f"missing_report_provenance: runtime report {rid} missing {field}")
    if not isinstance(report_entry.get("runtime"), dict) or not report_entry["runtime"]:
        errors.append(f"missing_report_provenance: runtime report {rid} missing runtime object")
    recorded_sha = report_entry.get("recorded_sha256")
    if not isinstance(recorded_sha, str) or not DIGEST_RE.match(recorded_sha):
        errors.append(f"missing_artifact_hash: runtime report {rid} has missing/invalid recorded_sha256")

    boundary = report_entry.get("boundary")
    ev_class = report_entry.get("evidence_class")
    if ev_class not in ALLOWED_EVIDENCE_CLASSES:
        errors.append(f"invalid_evidence_class: runtime report {rid} has unsupported evidence_class {ev_class!r}")
    if boundary not in ALLOWED_BOUNDARIES:
        errors.append(f"invalid_boundary: runtime report {rid} has unsupported boundary {boundary!r}")
    if boundary in LOCAL_NON_LIVE_BOUNDARIES and ev_class == "provider-live":
        errors.append(f"double_promotion_forbidden: runtime report {rid} promotes {boundary} to provider-live")

    report_path = repo_root / rel_path
    if not report_path.is_file():
        if require_present:
            errors.append(f"missing_runtime_report: {rel_path} not found on disk")
        return errors

    try:
        doc = json.loads(report_path.read_text(encoding="utf-8"))
    except ValueError as exc:
        return errors + [f"invalid_runtime_report_json: {rel_path}: {exc}"]

    if not isinstance(doc, dict):
        return errors + [f"invalid_runtime_report_json: {rel_path} must be a JSON object"]

    if "paidPspTransactions" in doc and doc["paidPspTransactions"] != 0:
        errors.append(f"paid_psp_forbidden: {rel_path} paidPspTransactions != 0")
    if "exit" in doc and doc["exit"] != 0:
        errors.append(f"runtime_report_failed: {rel_path} exit={doc['exit']!r}")
    if "passed" in doc and doc["passed"] is not True:
        errors.append(f"runtime_report_failed: {rel_path} passed={doc['passed']!r}")
    if "violations" in doc and doc["violations"]:
        errors.append(f"runtime_report_failed: {rel_path} has non-empty violations")
    if doc.get("externalReady") is True or doc.get("rolloutGatePassed") is True:
        if doc.get("boundary") in {"double", "loopback"}:
            errors.append(f"double_promotion_forbidden: {rel_path} promotes {doc.get('boundary')} to externalReady/rolloutGatePassed")
    if isinstance(doc.get("activation_inputs"), dict) and doc["activation_inputs"].get("production_qualified") is True:
        errors.append(f"double_promotion_forbidden: {rel_path} claims local drill is production_qualified")
    return errors


def validate_manifest(
    manifest: dict[str, Any],
    *,
    repo_root: Path = ROOT,
    require_runtime_reports: bool = False,
    check_manifest_digest: bool = True,
) -> list[str]:
    errors: list[str] = []
    if not isinstance(manifest, dict):
        return ["manifest_not_object: manifest must be a JSON object"]

    # 1. D71 self-isolation check on manifest and key P9.8 files.
    errors.extend(scan_d71_text(json.dumps(manifest, ensure_ascii=False), "integration-readiness.json"))
    for rel in (
        "docs/operations/integration-readiness.md",
        "scripts/spec/check-integration-readiness.py",
        "artifacts/migration-drill-manifest.json",
    ):
        target = repo_root / rel
        if target.is_file():
            errors.extend(scan_d71_text(target.read_text(encoding="utf-8"), rel))

    # 2. Top-level identity & spec lock hash.
    if manifest.get("schema") != SCHEMA_ID:
        errors.append(f"invalid_schema: expected {SCHEMA_ID!r}, got {manifest.get('schema')!r}")
    if manifest.get("item") != "PLAN1.P9.8":
        errors.append(f"invalid_item: expected 'PLAN1.P9.8', got {manifest.get('item')!r}")

    spec_lock_rel = manifest.get("spec_lock_path")
    spec_lock_sha = manifest.get("spec_lock_sha256")
    if not _non_empty_str(spec_lock_rel) or not isinstance(spec_lock_sha, str) or not DIGEST_RE.match(spec_lock_sha):
        errors.append("missing_artifact_hash: spec_lock_path or spec_lock_sha256 missing/malformed")
    else:
        lock_file = repo_root / spec_lock_rel
        if not lock_file.is_file():
            errors.append(f"missing_artifact: spec lock {spec_lock_rel} not found")
        else:
            actual_lock_sha = file_sha256(lock_file)
            if actual_lock_sha != spec_lock_sha:
                errors.append(f"artifact_hash_mismatch: {spec_lock_rel} expected {spec_lock_sha}, actual {actual_lock_sha}")

    # 3. Policy invariants.
    policy = manifest.get("policy")
    if not isinstance(policy, dict):
        errors.append("missing_policy: top-level policy object required")
    else:
        for flag in (
            "self_isolated_product_agnostic",
            "php_sole_writer",
            "dart_codec_slice_only",
            "direct_external_db_access_forbidden",
            "double_promotion_forbidden",
            "automatic_fallback_after_target_write_forbidden",
            "diagnostic_commit_counter_authority_forbidden",
            "minimal_embedded_release_permitted_when_remote_pending",
        ):
            if policy.get(flag) is not True:
                errors.append(f"invalid_policy_flag: policy.{flag} must be true")
        if policy.get("paid_psp_transactions_in_local_evidence") != 0:
            errors.append("paid_psp_forbidden: policy.paid_psp_transactions_in_local_evidence must be 0")

    # 4. Tracked artifacts hash verification.
    tracked = manifest.get("tracked_artifacts")
    tracked_by_path: dict[str, str] = {}
    if not isinstance(tracked, list) or not tracked:
        errors.append("missing_artifact_hash: tracked_artifacts must be a non-empty list")
    else:
        for idx, item in enumerate(tracked):
            if not isinstance(item, dict):
                errors.append(f"invalid_tracked_artifact: tracked_artifacts[{idx}] must be an object")
                continue
            rel = item.get("path")
            sha = item.get("sha256")
            if not _non_empty_str(rel):
                errors.append(f"invalid_tracked_artifact: tracked_artifacts[{idx}] missing path")
                continue
            if not isinstance(sha, str) or not DIGEST_RE.match(sha):
                errors.append(f"missing_artifact_hash: tracked_artifacts[{rel}] missing or malformed sha256")
                continue
            full = repo_root / rel
            if not full.is_file():
                errors.append(f"missing_artifact: tracked artifact {rel} does not exist")
                continue
            actual = file_sha256(full)
            if actual != sha:
                errors.append(f"artifact_hash_mismatch: {rel} declared {sha}, actual {actual}")
            tracked_by_path[rel] = sha
        missing_required = sorted(REQUIRED_TRACKED_PATHS - set(tracked_by_path))
        if missing_required:
            errors.append(f"missing_required_tracked_artifacts: {missing_required}")

    # 5. Local runtime reports.
    runtime_reports = manifest.get("local_runtime_reports")
    report_ids: set[str] = set()
    if not isinstance(runtime_reports, list) or len(runtime_reports) < 4:
        errors.append("missing_runtime_reports: local_runtime_reports must record all 4 P9 local runtime suites")
    else:
        for entry in runtime_reports:
            if not isinstance(entry, dict) or not _non_empty_str(entry.get("id")):
                errors.append("invalid_runtime_report: each entry requires a non-empty id")
                continue
            report_ids.add(entry["id"])
            errors.extend(validate_runtime_report_file(entry, repo_root, require_runtime_reports))

    # 6. Mandatory acceptance ID coverage (IM-01..17, CT-01..20, MGATE-01..11, P9 catalog IDs).
    coverage = manifest.get("acceptance_coverage")
    if not isinstance(coverage, dict):
        errors.append("missing_acceptance_coverage: acceptance_coverage object required")
    else:
        for key, expected_ids in (
            ("import_matrix", REQUIRED_IM_IDS),
            ("consumer_contract", REQUIRED_CT_IDS),
            ("migration_gates", REQUIRED_MGATE_IDS),
            ("catalog_p9_scenarios", REQUIRED_CATALOG_P9_IDS),
        ):
            block = coverage.get(key)
            if not isinstance(block, dict):
                errors.append(f"missing_acceptance_coverage: acceptance_coverage.{key} missing")
                continue
            declared_ids = block.get("ids")
            if declared_ids != expected_ids:
                errors.append(f"incomplete_acceptance_coverage: acceptance_coverage.{key}.ids does not match required inventory")
            if block.get("state") != "passed":
                errors.append(f"local_acceptance_not_passed: acceptance_coverage.{key}.state must be 'passed'")
            if block.get("evidence_class") not in ALLOWED_EVIDENCE_CLASSES:
                errors.append(f"invalid_evidence_class: acceptance_coverage.{key} has invalid evidence_class")
            if block.get("boundary") not in ALLOWED_BOUNDARIES:
                errors.append(f"invalid_boundary: acceptance_coverage.{key} has invalid boundary")
            if block.get("boundary") in LOCAL_NON_LIVE_BOUNDARIES and block.get("evidence_class") == "provider-live":
                errors.append(f"double_promotion_forbidden: acceptance_coverage.{key} promotes local boundary to provider-live")

    # 7. Gates matrix validation.
    gates = manifest.get("gates")
    gates_by_id: dict[str, dict[str, Any]] = {}
    if not isinstance(gates, list) or not gates:
        errors.append("missing_gates: gates must be a non-empty list")
    else:
        for idx, gate in enumerate(gates):
            if not isinstance(gate, dict):
                errors.append(f"invalid_gate: gates[{idx}] must be an object")
                continue
            gid = gate.get("id")
            if not _non_empty_str(gid):
                errors.append(f"invalid_gate: gates[{idx}] missing id")
                continue
            if gid in gates_by_id:
                errors.append(f"duplicate_gate: {gid} appears more than once")
                continue
            gates_by_id[gid] = gate

            cat = gate.get("category")
            state = gate.get("state")
            active = gate.get("active")
            owner = gate.get("owner")
            scope = gate.get("scope")
            reason = gate.get("reason")
            ev_class = gate.get("evidence_class")
            boundary = gate.get("boundary")
            ev_refs = gate.get("evidence_refs")

            if cat not in ALLOWED_CATEGORIES:
                errors.append(f"invalid_gate_category: {gid} category {cat!r}")
            if state not in ALLOWED_STATES:
                errors.append(f"invalid_gate_state: {gid} state {state!r}")
            if not isinstance(active, bool):
                errors.append(f"invalid_gate_active: {gid} active must be boolean")
            if not _non_empty_str(owner) or owner.lower() in {"tbd", "unknown", "none", "undefined", "n/a"}:
                errors.append(f"undefined_owner: gate {gid} has undefined/missing owner {owner!r}")
            if not _non_empty_str(scope):
                errors.append(f"missing_scope: gate {gid} missing scope")
            if not _non_empty_str(reason):
                errors.append(f"missing_reason: gate {gid} missing reason")
            if ev_class not in ALLOWED_EVIDENCE_CLASSES:
                errors.append(f"invalid_evidence_class: gate {gid} evidence_class {ev_class!r}")
            if boundary not in ALLOWED_BOUNDARIES:
                errors.append(f"invalid_boundary: gate {gid} boundary {boundary!r}")

            # Anti-promotion: double/loopback/fixture cannot claim provider-live.
            if boundary in LOCAL_NON_LIVE_BOUNDARIES and ev_class == "provider-live":
                errors.append(f"double_promotion_forbidden: gate {gid} claims provider-live with boundary={boundary!r}")
            if ev_class == "provider-live" and boundary != "provider-live":
                errors.append(f"double_promotion_forbidden: gate {gid} provider-live requires boundary='provider-live'")

            # Pending/failed/not-applicable gates can NEVER be marked active.
            if state in {"pending", "failed", "not-applicable"} and active is True:
                errors.append(f"pending_gate_marked_active: gate {gid} has state={state!r} with active=true")

            # Local mandatory gates must be passed and cannot be marked not-applicable/pending/failed.
            if cat == "local-mandatory" and state != "passed":
                errors.append(f"local_mandatory_gate_not_passed: {gid} must be 'passed', got {state!r}")

            # not-applicable requires an accepted composition/consumer decision ID.
            if state == "not-applicable":
                dec = gate.get("decision_ref")
                if not isinstance(dec, str) or not DECISION_RE.match(dec):
                    errors.append(f"not_applicable_without_decision: gate {gid} requires accepted decision_ref (e.g. D61)")

            # Passed gates must cite hashed evidence_refs.
            if not isinstance(ev_refs, list):
                errors.append(f"invalid_evidence_refs: gate {gid} evidence_refs must be a list")
            elif state == "passed":
                if not ev_refs:
                    errors.append(f"missing_artifact_hash: passed gate {gid} has empty evidence_refs")
                for eidx, ref in enumerate(ev_refs):
                    if not isinstance(ref, dict) or not _non_empty_str(ref.get("path")):
                        errors.append(f"missing_artifact_hash: gate {gid} evidence_refs[{eidx}] missing path")
                        continue
                    rsha = ref.get("sha256")
                    if not isinstance(rsha, str) or not DIGEST_RE.match(rsha):
                        errors.append(f"missing_artifact_hash: gate {gid} evidence_refs[{eidx}] missing/malformed sha256")
                    elif ref["path"] in tracked_by_path and tracked_by_path[ref["path"]] != rsha:
                        errors.append(
                            f"artifact_hash_mismatch: gate {gid} ref {ref['path']} hash differs from tracked_artifacts"
                        )

        missing_local_gates = sorted(REQUIRED_LOCAL_GATE_IDS - set(gates_by_id))
        if missing_local_gates:
            errors.append(f"missing_local_gates: {missing_local_gates}")

    # 8. Activation status consistency with pending external gates (D59).
    activation = manifest.get("activation_status")
    if not isinstance(activation, dict):
        errors.append("missing_activation_status: activation_status object required")
    else:
        mig_gates = [g for g in gates_by_id.values() if g.get("category") == "migration-activation"]
        rem_gates = [g for g in gates_by_id.values() if g.get("category") == "remote-execution-activation"]
        if not mig_gates:
            errors.append("missing_migration_gates: at least one migration-activation gate required")
        if not rem_gates:
            errors.append("missing_remote_gates: at least one remote-execution-activation gate required")

        if any(g.get("state") != "passed" for g in mig_gates) and activation.get("live_migration_cutover_active") is True:
            errors.append("pending_gate_marked_active: live_migration_cutover_active=true while migration gates are pending/failed")
        if any(g.get("state") != "passed" for g in rem_gates) and activation.get("remote_execution_active") is True:
            errors.append("pending_gate_marked_active: remote_execution_active=true while remote execution gates are pending/failed")

        psp_gate = gates_by_id.get("GATE-EXT-PSP-QUALIFICATION")
        if (not psp_gate or psp_gate.get("state") != "passed") and activation.get("production_provider_qualified") is True:
            errors.append("double_promotion_forbidden: production_provider_qualified=true without passed live PSP gate")

        ext_consumer_gate = gates_by_id.get("GATE-EXT-CONSUMER-RUNTIME")
        if (not ext_consumer_gate or ext_consumer_gate.get("state") != "passed") and activation.get("external_consumer_ready") is True:
            errors.append("double_promotion_forbidden: external_consumer_ready=true without passed external-runtime consumer gate")

        if activation.get("minimal_embedded_ledger_releasable") is True and any(g.get("state") != "passed" for g in rem_gates):
            for field in ("execution_enabled", "outgoing_default", "remote_routes_effect_capable"):
                if activation.get(field) is not False:
                    errors.append(
                        f"d59_embedded_isolation_violated: minimal_embedded_ledger_releasable=true with pending remote gates requires activation_status.{field}=false"
                    )

    # 9. External owner-supplied inputs & retention/owner completeness.
    ext_inputs = manifest.get("external_inputs")
    if not isinstance(ext_inputs, dict):
        errors.append("undefined_owner_or_retention: external_inputs object required")
    else:
        missing_inputs = sorted(REQUIRED_EXTERNAL_INPUT_KEYS - set(ext_inputs))
        if missing_inputs:
            errors.append(f"undefined_owner_or_retention: missing external_inputs keys {missing_inputs}")
        for key, item in ext_inputs.items():
            if not isinstance(item, dict):
                errors.append(f"invalid_external_input: external_inputs.{key} must be an object")
                continue
            owner = item.get("owner")
            status = item.get("status")
            if not _non_empty_str(owner) or owner.lower() in {"tbd", "unknown", "none", "undefined"}:
                errors.append(f"undefined_owner: external_inputs.{key} has undefined owner {owner!r}")
            if status not in {"pending_owner_input", "supplied"}:
                errors.append(f"invalid_external_input_status: external_inputs.{key} has invalid status {status!r}")
            if status == "pending_owner_input" and item.get("value") is not None:
                errors.append(f"fabricated_owner_input: pending external_inputs.{key} must have value=null")

    # 10. Generic external consumer actual evidence import contract.
    ext_contract = manifest.get("external_consumer_evidence_contract")
    if not isinstance(ext_contract, dict):
        errors.append("missing_consumer_import_contract: external_consumer_evidence_contract required")
    else:
        if ext_contract.get("protocol") != "billium.consumer.contract.report.v1":
            errors.append("invalid_consumer_import_contract: wrong protocol")
        if ext_contract.get("required_boundary") != "external-runtime":
            errors.append("invalid_consumer_import_contract: required_boundary must be 'external-runtime'")
        if ext_contract.get("required_evidence_class") != "consumer-contract":
            errors.append("invalid_consumer_import_contract: required_evidence_class must be 'consumer-contract'")
        if ext_contract.get("required_paid_psp_transactions") != 0:
            errors.append("paid_psp_forbidden: consumer import contract requires paidPspTransactions=0")
        if ext_contract.get("required_cases") != REQUIRED_CT_IDS:
            errors.append("invalid_consumer_import_contract: required_cases must equal CT-01..CT-20")
        if ext_contract.get("forbid_double_or_loopback_promotion") is not True:
            errors.append("invalid_consumer_import_contract: forbid_double_or_loopback_promotion must be true")

    # 11. Hard limitations: unanchored_evidence, no automatic fallback after target write, retention separation.
    limitations = manifest.get("limitations")
    if not isinstance(limitations, dict):
        errors.append("missing_limitations: limitations object required")
    else:
        unanchored = limitations.get("unanchored_evidence")
        if not isinstance(unanchored, dict):
            errors.append("missing_limitations: limitations.unanchored_evidence required")
        else:
            for flag in (
                "can_create_paid_price_version",
                "can_cover_occurrence",
                "can_create_payment_fact",
                "can_create_synthetic_obligation",
                "collection_allowed",
                "partial_refund_without_proven_original_owner_allowed",
            ):
                if unanchored.get(flag) is not False:
                    errors.append(f"unanchored_limitation_violated: unanchored_evidence.{flag} must be false")

        rollback = limitations.get("rollback_and_fallback")
        if not isinstance(rollback, dict):
            errors.append("missing_limitations: limitations.rollback_and_fallback required")
        else:
            if rollback.get("automatic_fallback_after_target_write_allowed") is not False:
                errors.append("fallback_rule_violated: automatic_fallback_after_target_write_allowed must be false")
            if rollback.get("original_execution_bindings_mutable") is not False:
                errors.append("binding_immutability_violated: original_execution_bindings_mutable must be false")
            if rollback.get("pre_target_write_resume_requires_target_fence_and_new_epoch") is not True:
                errors.append("fallback_rule_violated: pre_target_write_resume_requires_target_fence_and_new_epoch must be true")

        retention = limitations.get("retention")
        if not isinstance(retention, dict):
            errors.append("missing_limitations: limitations.retention required")
        else:
            if retention.get("broad_truncation_on_import_complete_or_sdk_disconnect_allowed") is not False:
                errors.append("retention_rule_violated: broad truncation must be false")
            if retention.get("schema_rollback_authorizes_fact_deletion_or_replay") is not False:
                errors.append("retention_rule_violated: schema rollback deletion/replay must be false")
            preserved = retention.get("preserve_separate_from_outbox_queue")
            required_preserved = {
                "source_identity_mappings",
                "tombstones",
                "import_receipts",
                "operation_revisions",
                "provider_evidence",
                "original_execution_bindings",
            }
            if not isinstance(preserved, list) or not required_preserved.issubset(set(preserved)):
                errors.append("retention_rule_violated: preserve_separate_from_outbox_queue missing required records")

    # 12. Canonical JCS manifestDigest check.
    if check_manifest_digest:
        declared = manifest.get("manifestDigest")
        if not isinstance(declared, str) or not DIGEST_RE.match(declared):
            errors.append("missing_artifact_hash: manifestDigest missing or malformed")
        else:
            expected = canonical_digest(manifest, "manifestDigest")
            if declared != expected:
                errors.append(f"artifact_hash_mismatch: manifestDigest expected {expected}, got {declared}")

    return errors


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--check", type=Path, required=True, help="Path to integration-readiness.json manifest")
    parser.add_argument("--repo-root", type=Path, default=ROOT, help="Repository root path")
    parser.add_argument(
        "--require-runtime-reports",
        action="store_true",
        help="Fail if local runtime report files under conformance/reports/ are absent on disk",
    )
    args = parser.parse_args(argv)

    repo_root = args.repo_root.resolve()
    manifest_path = args.check if args.check.is_absolute() else (repo_root / args.check)
    if not manifest_path.is_file():
        print(f"check-integration-readiness: missing manifest {manifest_path}", file=sys.stderr)
        return 1

    try:
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    except ValueError as exc:
        print(f"check-integration-readiness: invalid JSON in {manifest_path}: {exc}", file=sys.stderr)
        return 1

    errors = validate_manifest(
        manifest,
        repo_root=repo_root,
        require_runtime_reports=args.require_runtime_reports,
    )
    for line in errors:
        print(f"check-integration-readiness: {line}", file=sys.stderr)
    if errors:
        print(f"check-integration-readiness: FAILED ({len(errors)} finding(s))", file=sys.stderr)
        return 1

    print(
        "check-integration-readiness: OK "
        f"({len(manifest['gates'])} gates, {len(manifest['tracked_artifacts'])} hashed artifacts, "
        "exact evidence classes, D71 isolated)"
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
