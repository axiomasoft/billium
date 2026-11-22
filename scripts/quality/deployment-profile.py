#!/usr/bin/env python3
"""Validate Billium deployment profile & provider qualification manifests (PLAN1 P10.7)."""
from __future__ import annotations

import argparse
import json
from pathlib import Path
import re
import sys
from typing import Any

sys.dont_write_bytecode = True

ROOT = Path(__file__).resolve().parents[2]
DEFAULT_SCHEMA_PATH = ROOT / "spec" / "deployment-profile.schema.json"

SECRET_PATTERNS = (
    re.compile(r"\bsk_(live|test)_[A-Za-z0-9]{8,}\b"),
    re.compile(r"\bpk_(live|test)_[A-Za-z0-9]{8,}\b"),
    re.compile(r"(?i)\b(api_key|client_secret|password)\s*[:=]\s*\S+"),
)

REQUIRED_LIVE_EVIDENCE_KEYS = (
    "releaseEvidenceRef",
    "retentionPolicyRef",
    "restoreDrillRef",
    "securityGateRef",
    "supplyChainRef",
    "qualificationEvidenceRef",
)


def _matches_type(value: Any, expected: str) -> bool:
    if expected == "null":
        return value is None
    if expected == "boolean":
        return isinstance(value, bool)
    if expected == "integer":
        return isinstance(value, int) and not isinstance(value, bool)
    if expected == "number":
        return isinstance(value, (int, float)) and not isinstance(value, bool)
    if expected == "string":
        return isinstance(value, str)
    if expected == "array":
        return isinstance(value, list)
    if expected == "object":
        return isinstance(value, dict)
    return False


def validate_schema(data: Any, schema: dict[str, Any], path: str = "$") -> list[dict[str, str]]:
    errors: list[dict[str, str]] = []

    if "const" in schema and data != schema["const"]:
        errors.append({
            "code": "schema.const",
            "path": path,
            "message": f"expected constant value {schema['const']!r}",
        })
        return errors

    if "type" in schema:
        expected_types = schema["type"] if isinstance(schema["type"], list) else [schema["type"]]
        if not any(_matches_type(data, t) for t in expected_types):
            errors.append({
                "code": "schema.type",
                "path": path,
                "message": f"expected type {'|'.join(expected_types)}, got {type(data).__name__}",
            })
            return errors

    if "enum" in schema and data not in schema["enum"]:
        errors.append({
            "code": "schema.enum",
            "path": path,
            "message": f"value {data!r} is not in allowed enum {schema['enum']!r}",
        })

    if isinstance(data, str):
        if "minLength" in schema and len(data) < schema["minLength"]:
            errors.append({
                "code": "schema.minLength",
                "path": path,
                "message": f"string length must be >= {schema['minLength']}",
            })
        if "pattern" in schema and re.search(schema["pattern"], data) is None:
            errors.append({
                "code": "schema.pattern",
                "path": path,
                "message": f"string does not match pattern {schema['pattern']}",
            })

    if isinstance(data, (int, float)) and not isinstance(data, bool):
        if "minimum" in schema and data < schema["minimum"]:
            errors.append({
                "code": "schema.minimum",
                "path": path,
                "message": f"value {data} must be >= {schema['minimum']}",
            })
        if "maximum" in schema and data > schema["maximum"]:
            errors.append({
                "code": "schema.maximum",
                "path": path,
                "message": f"value {data} must be <= {schema['maximum']}",
            })

    if isinstance(data, list):
        if "minItems" in schema and len(data) < schema["minItems"]:
            errors.append({
                "code": "schema.minItems",
                "path": path,
                "message": f"array length must be >= {schema['minItems']}",
            })
        item_schema = schema.get("items")
        if isinstance(item_schema, dict):
            for idx, item in enumerate(data):
                errors.extend(validate_schema(item, item_schema, f"{path}[{idx}]"))

    if isinstance(data, dict):
        props = schema.get("properties", {})
        for req in schema.get("required", []):
            if req not in data:
                errors.append({
                    "code": "schema.required",
                    "path": f"{path}.{req}",
                    "message": f"missing required field '{req}'",
                })
        if schema.get("additionalProperties") is False:
            for key in data:
                if key not in props:
                    errors.append({
                        "code": "schema.additionalProperties",
                        "path": f"{path}.{key}",
                        "message": f"unexpected field '{key}'",
                    })
        for key, prop_schema in props.items():
            if key in data and isinstance(prop_schema, dict):
                errors.extend(validate_schema(data[key], prop_schema, f"{path}.{key}"))

    return errors


def _scan_secrets(value: Any, path: str = "$") -> list[dict[str, str]]:
    errors: list[dict[str, str]] = []
    if isinstance(value, str):
        for pat in SECRET_PATTERNS:
            if pat.search(value):
                errors.append({
                    "code": "security.inline_secret",
                    "path": path,
                    "message": "inline secret material is forbidden in deployment manifests",
                })
                break
    elif isinstance(value, list):
        for idx, item in enumerate(value):
            errors.extend(_scan_secrets(item, f"{path}[{idx}]"))
    elif isinstance(value, dict):
        for key, item in value.items():
            errors.extend(_scan_secrets(item, f"{path}.{key}"))
    return errors


def validate_semantics(manifest: dict[str, Any], require_live: bool = False) -> list[dict[str, str]]:
    errors: list[dict[str, str]] = []

    def add(code: str, path: str, message: str) -> None:
        errors.append({"code": code, "path": path, "message": message})

    errors.extend(_scan_secrets(manifest))

    profile_type = manifest.get("profileType")
    activation_mode = manifest.get("activationMode")
    environment = manifest.get("environment")
    modules = manifest.get("modules") or {}
    qualification = manifest.get("qualification") or {}
    execution_enabled = bool(modules.get("executionEnabled"))

    if require_live and activation_mode != "live":
        add("live.required", "$.activationMode", "--require-live requires activationMode='live'")

    if profile_type == "contracts-only":
        if activation_mode != "contracts-only":
            add("contracts_only.activation_mode", "$.activationMode", "contracts-only profile must use activationMode='contracts-only'")
        if modules.get("composition") != "contracts-only":
            add("contracts_only.composition", "$.modules.composition", "contracts-only profile must use composition='contracts-only'")
        if execution_enabled:
            add("contracts_only.execution_forbidden", "$.modules.executionEnabled", "contracts-only profile must keep executionEnabled=false")
        if qualification.get("status") != "not_applicable" or qualification.get("mode") != "none":
            add("contracts_only.qualification", "$.qualification", "contracts-only profile must set qualification status='not_applicable' and mode='none'")
        if "driver" in manifest:
            add("contracts_only.driver_forbidden", "$.driver", "contracts-only profile must not declare a payment driver")
        return errors

    if activation_mode == "contracts-only":
        add("profile.activation_mismatch", "$.activationMode", "activationMode='contracts-only' is valid only for profileType='contracts-only'")

    for section in ("seller", "authority", "mandateAndFiscal", "retention", "slo", "evidenceRefs"):
        if section not in manifest:
            add("profile.section_required", f"$.{section}", f"non-contracts-only profile requires '{section}'")

    if execution_enabled or activation_mode in {"simulated", "live"}:
        if "driver" not in manifest:
            add("execution.driver_required", "$.driver", "driver declaration is required when execution is enabled or active")
        if "execution" not in (modules.get("enabled") or []):
            add("execution.module_required", "$.modules.enabled", "execution module must be listed in modules.enabled when executionEnabled=true")

    mandate_fiscal = manifest.get("mandateAndFiscal") or {}
    driver = manifest.get("driver") or {}
    capabilities = set(driver.get("capabilities") or [])

    if mandate_fiscal.get("recurringMandateEnabled"):
        if not mandate_fiscal.get("mandatePolicyRef"):
            add("mandate.policy_required", "$.mandateAndFiscal.mandatePolicyRef", "mandatePolicyRef is required when recurringMandateEnabled=true")
        if driver and "recurring_consent" not in capabilities:
            add("mandate.capability_missing", "$.driver.capabilities", "recurring_consent capability is required when recurringMandateEnabled=true")

    if mandate_fiscal.get("fiscalMode") not in (None, "none") and not mandate_fiscal.get("fiscalProfileRef"):
        add("fiscal.profile_required", "$.mandateAndFiscal.fiscalProfileRef", "fiscalProfileRef is required when fiscalMode is not 'none'")

    is_live = activation_mode == "live" or (environment == "production" and execution_enabled)
    if is_live:
        if qualification.get("status") != "qualified":
            add("live.provider_unqualified", "$.qualification.status", "live activation is blocked unless qualification.status='qualified'")
        if qualification.get("mode") != "provider_verified":
            add("live.simulated_not_allowed", "$.qualification.mode", "simulated or missing qualification mode cannot authorize live execution")
        if not qualification.get("manifestRef"):
            add("live.manifest_ref_required", "$.qualification.manifestRef", "qualification.manifestRef is required for live activation")
        if not qualification.get("checkedAt"):
            add("live.checked_at_required", "$.qualification.checkedAt", "qualification.checkedAt is required for live activation")
        if not qualification.get("officialSources"):
            add("live.official_sources_required", "$.qualification.officialSources", "officialSources must be non-empty for live activation")
        if not qualification.get("liveEvidenceRefs"):
            add("live.evidence_refs_required", "$.qualification.liveEvidenceRefs", "liveEvidenceRefs must be non-empty for live activation")
        if qualification.get("unresolvedGaps"):
            add("live.unresolved_gaps", "$.qualification.unresolvedGaps", "live activation is blocked while unresolvedGaps is non-empty")

        if driver:
            if not driver.get("historicalLookupSupported"):
                add("live.historical_lookup_required", "$.driver.historicalLookupSupported", "historicalLookupSupported must be true for live activation")
            if not driver.get("historicalRefundSupported"):
                add("live.historical_refund_required", "$.driver.historicalRefundSupported", "historicalRefundSupported must be true for live activation")
            if not ({"lookup_by_provider_id", "lookup_by_reference"} & capabilities):
                add("live.lookup_capability_required", "$.driver.capabilities", "live driver must support lookup_by_provider_id or lookup_by_reference")
            for required_cap in ("webhook_authenticity", "webhook_event_identity"):
                if required_cap not in capabilities:
                    add("live.capability_required", "$.driver.capabilities", f"live driver must declare capability '{required_cap}'")

        authority = manifest.get("authority") or {}
        if authority.get("writerAuthorityKind") == "none":
            add("live.authority_required", "$.authority.writerAuthorityKind", "live activation requires an explicit writer authority kind")
        if not authority.get("egressQuiesceSupported"):
            add("live.egress_quiesce_required", "$.authority.egressQuiesceSupported", "egressQuiesceSupported must be true for live activation")
        if not authority.get("egressQuiesceRunbookRef"):
            add("live.egress_quiesce_runbook_required", "$.authority.egressQuiesceRunbookRef", "egressQuiesceRunbookRef must be non-empty for live activation")
        if not authority.get("fencingEpochRequired"):
            add("live.fencing_required", "$.authority.fencingEpochRequired", "fencingEpochRequired must be true for live activation")

        retention = manifest.get("retention") or {}
        if not retention.get("legalHoldSupported"):
            add("live.legal_hold_required", "$.retention.legalHoldSupported", "legalHoldSupported must be true for live activation")

        evidence_refs = manifest.get("evidenceRefs") or {}
        for key in REQUIRED_LIVE_EVIDENCE_KEYS:
            if not evidence_refs.get(key):
                add("live.evidence_missing", f"$.evidenceRefs.{key}", f"evidenceRefs.{key} is required for live activation")

    return errors


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Validate a Billium deployment profile manifest.")
    parser.add_argument("manifest", help="Path to manifest JSON file, or '-' for stdin")
    parser.add_argument("--schema", default=str(DEFAULT_SCHEMA_PATH), help="Path to JSON Schema file")
    parser.add_argument("--require-live", action="store_true", help="Require manifest to be qualified for live activation")
    parser.add_argument("--json", action="store_true", help="Emit structured JSON validation report")
    args = parser.parse_args(argv)

    try:
        schema = json.loads(Path(args.schema).read_text(encoding="utf-8"))
        raw = sys.stdin.read() if args.manifest == "-" else Path(args.manifest).read_text(encoding="utf-8")
        manifest = json.loads(raw)
    except (OSError, json.JSONDecodeError) as exc:
        if args.json:
            print(json.dumps({"schema": "billium.quality.deployment_profile.v1", "status": "error", "error": str(exc)}, indent=2))
        else:
            print(f"error: {exc}", file=sys.stderr)
        return 2

    errors = validate_schema(manifest, schema)
    if not errors and isinstance(manifest, dict):
        errors.extend(validate_semantics(manifest, require_live=args.require_live))

    status = "valid" if not errors else "invalid"
    report = {
        "schema": "billium.quality.deployment_profile.v1",
        "status": status,
        "profileId": manifest.get("profileId") if isinstance(manifest, dict) else None,
        "profileType": manifest.get("profileType") if isinstance(manifest, dict) else None,
        "activationMode": manifest.get("activationMode") if isinstance(manifest, dict) else None,
        "errorCount": len(errors),
        "errors": errors,
    }

    if args.json:
        print(json.dumps(report, indent=2))
    else:
        if status == "valid":
            print(f"OK: {report['profileId']} ({report['profileType']}/{report['activationMode']})")
        else:
            for err in errors:
                print(f"FAIL [{err['code']}] {err['path']}: {err['message']}", file=sys.stderr)

    return 0 if status == "valid" else 1


if __name__ == "__main__":
    raise SystemExit(main())
