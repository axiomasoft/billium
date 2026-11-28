#!/usr/bin/env bash
# Local Release Candidate validation runner.
# Zero network calls, zero publish/tag/commit side effects.
#
# Usage:
#   bash scripts/quality/release-candidate.sh --local-only \
#     [--profile contract-only|embedded|standalone|remote|remote-live] \
#     [--modules csv] \
#     [--holds-file path.json] \
#     [--acceptance-evidence path.json] \
#     [--deployment-manifest path.json] \
#     [--report path.json]
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

LOCAL_ONLY=0
PROFILE="embedded"
MODULES_CSV=""
HOLDS_FILE=""
ACCEPTANCE_EVIDENCE=""
DEPLOYMENT_MANIFEST=""
REPORT_PATH=""

while [[ $# -gt 0 ]]; do
    case "$1" in
        --local-only)
            LOCAL_ONLY=1
            shift
            ;;
        --profile)
            PROFILE="${2:?--profile requires a value}"
            shift 2
            ;;
        --modules)
            MODULES_CSV="${2:?--modules requires a comma-separated list}"
            shift 2
            ;;
        --holds-file)
            HOLDS_FILE="${2:?--holds-file requires a file path}"
            shift 2
            ;;
        --acceptance-evidence)
            ACCEPTANCE_EVIDENCE="${2:?--acceptance-evidence requires a file path}"
            shift 2
            ;;
        --deployment-manifest)
            DEPLOYMENT_MANIFEST="${2:?--deployment-manifest requires a file path}"
            shift 2
            ;;
        --report)
            REPORT_PATH="${2:?--report requires a file path}"
            shift 2
            ;;
        -h|--help)
            sed -n '2,12p' "$0"
            exit 0
            ;;
        *)
            echo "Unknown option: $1" >&2
            exit 2
            ;;
    esac
done

set +e
OUTPUT="$(python3 - "$ROOT" "$LOCAL_ONLY" "$PROFILE" "$MODULES_CSV" "$HOLDS_FILE" "$ACCEPTANCE_EVIDENCE" "$DEPLOYMENT_MANIFEST" <<'PY'
from __future__ import annotations

import importlib.util
import json
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

root = Path(sys.argv[1])
local_only = sys.argv[2] == "1"
profile = sys.argv[3]
modules_csv = sys.argv[4]
holds_file = sys.argv[5]
acceptance_evidence_arg = sys.argv[6]
deployment_manifest_arg = sys.argv[7]

errors: list[dict[str, Any]] = []


def add_error(code: str, message: str, details: Any = None) -> None:
    entry: dict[str, Any] = {"code": code, "message": message}
    if details is not None:
        entry["details"] = details
    errors.append(entry)


PROFILE_RULES: dict[str, dict[str, Any]] = {
    "contract-only": {
        "default": ["contracts"],
        "required": {"contracts"},
        "allowed": {"contracts"},
        "forbidden": {"platform", "ledger", "usage", "rating", "schedule", "documents", "execution", "collection", "server"},
    },
    "embedded": {
        "default": ["platform", "ledger"],
        "required": {"platform", "ledger"},
        "allowed": {"platform", "ledger", "usage", "rating", "schedule", "documents"},
        "forbidden": {"execution", "collection", "contracts"},
    },
    "standalone": {
        "default": ["platform", "ledger", "usage", "rating", "schedule", "documents", "execution", "collection"],
        "required": {"platform", "ledger"},
        "allowed": {"platform", "ledger", "usage", "rating", "schedule", "documents", "execution", "collection"},
        "forbidden": {"contracts"},
    },
    "remote": {
        "default": ["platform", "ledger", "execution", "server"],
        "required": {"platform", "ledger", "execution"},
        "allowed": {"platform", "ledger", "usage", "rating", "schedule", "documents", "execution", "collection", "server"},
        "forbidden": {"contracts"},
    },
    "remote-live": {
        "default": ["platform", "ledger", "execution", "server"],
        "required": {"platform", "ledger", "execution"},
        "allowed": {"platform", "ledger", "usage", "rating", "schedule", "documents", "execution", "collection", "server"},
        "forbidden": {"contracts"},
    },
}

MODULE_DEPS: dict[str, tuple[str, ...]] = {
    "ledger": ("platform",),
    "usage": ("platform",),
    "rating": ("platform", "ledger"),
    "schedule": ("platform", "ledger", "rating"),
    "documents": ("platform", "ledger"),
    "execution": ("platform",),
    "collection": ("platform", "ledger", "execution"),
    "server": ("platform", "ledger"),
}

# 1. Profile & module set validation.
selected_modules: list[str] = []
if profile not in PROFILE_RULES:
    add_error("rc.profile_unknown", f"Unsupported release profile '{profile}'", {"allowed": sorted(PROFILE_RULES)})
else:
    rules = PROFILE_RULES[profile]
    if modules_csv.strip():
        selected_modules = [m.strip() for m in modules_csv.split(",") if m.strip()]
    else:
        selected_modules = list(rules["default"])
    mod_set = set(selected_modules)
    missing_req = sorted(rules["required"] - mod_set)
    forbidden_used = sorted(rules["forbidden"] & mod_set)
    unknown_mods = sorted(mod_set - rules["allowed"] - rules["forbidden"])
    if missing_req or forbidden_used or unknown_mods:
        add_error(
            "rc.profile_module_invalid",
            f"Module set is invalid for profile '{profile}'",
            {
                "missingRequired": missing_req,
                "forbidden": forbidden_used,
                "unknown": unknown_mods,
            },
        )
    dep_errors = []
    for mod in selected_modules:
        for dep in MODULE_DEPS.get(mod, ()):
            if dep not in mod_set:
                dep_errors.append(f"{mod} requires {dep}")
    if dep_errors:
        add_error("rc.module_dependency_missing", "Module dependency DAG check failed", dep_errors)

profile_check = {
    "passed": not any(e["code"].startswith(("rc.profile_", "rc.module_")) for e in errors),
    "profile": profile,
    "modules": selected_modules,
}

# 2. Supply-chain & unresolved holds check (reuses gate).
sc_cmd = ["bash", str(root / "scripts" / "quality" / "release-supply-chain.sh"), "--profile", profile]
if holds_file:
    sc_cmd.extend(["--holds-file", holds_file])
sc_proc = subprocess.run(sc_cmd, cwd=root, text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=False)
sc_report: dict[str, Any] = {}
try:
    sc_report = json.loads(sc_proc.stdout)
except json.JSONDecodeError:
    add_error("rc.supply_chain_error", "release-supply-chain.sh returned non-JSON output", sc_proc.stderr.strip())

unresolved_holds = (sc_report.get("holds") or {}).get("unresolved", [])
if unresolved_holds:
    add_error("rc.unresolved_holds", f"Release blocked by {len(unresolved_holds)} unresolved hold(s)", unresolved_holds)
if sc_proc.returncode != 0 and not unresolved_holds:
    add_error("rc.supply_chain_failed", "Supply-chain policy check failed", sc_report.get("composer"))

holds_check = {
    "passed": sc_proc.returncode == 0 and len(unresolved_holds) == 0,
    "unresolvedCount": len(unresolved_holds),
    "unresolved": unresolved_holds,
}

# 3. Optional deployment manifest check (reuses validator).
manifest_check: dict[str, Any] = {"checked": False, "passed": True}
if deployment_manifest_arg:
    manifest_check["checked"] = True
    dp_cmd = ["python3", str(root / "scripts" / "quality" / "deployment-profile.py"), deployment_manifest_arg, "--json"]
    if profile == "remote-live":
        dp_cmd.append("--require-live")
    dp_proc = subprocess.run(dp_cmd, cwd=root, text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=False)
    try:
        dp_report = json.loads(dp_proc.stdout)
    except json.JSONDecodeError:
        dp_report = {"status": "error", "stderr": dp_proc.stderr.strip()}
    manifest_check["passed"] = dp_proc.returncode == 0
    manifest_check["report"] = dp_report
    if dp_proc.returncode != 0:
        add_error("rc.deployment_manifest_invalid", "Deployment profile manifest validation failed", dp_report.get("errors", dp_report))

# 4. Negative-filter self-test proof & non-empty discovery.
mech02_check: dict[str, Any] = {
    "passed": False,
    "emptyFilterRejected": False,
    "discoveredTests": 0,
}
discovery_path = root / "scripts" / "lint" / "test-discovery.py"
try:
    spec = importlib.util.spec_from_file_location("billium_test_discovery", discovery_path)
    assert spec and spec.loader
    discovery = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(discovery)
    discovery.prove_empty_filter_is_red()
    mech02_check["emptyFilterRejected"] = True
    discovered_count = int(discovery.discover(["--filter=ReleaseCandidate"]))
    mech02_check["discoveredTests"] = discovered_count
    mech02_check["passed"] = discovered_count > 0
    if discovered_count <= 0:
        add_error("rc.mech02_zero_tests", "Discovery returned 0 tests")
except Exception as exc:
    add_error("rc.mech02_failed", f"Empty-filter proof failed: {exc}")

# 5. Acceptance evidence validation (reject zero-test evidence).
acceptance_check: dict[str, Any] = {
    "passed": False,
    "source": acceptance_evidence_arg or "local-discovery",
    "testsExecuted": 0,
}
if acceptance_evidence_arg:
    ev_path = Path(acceptance_evidence_arg)
    if not ev_path.is_file():
        add_error("rc.acceptance_evidence_missing", f"Acceptance evidence file not found: {acceptance_evidence_arg}")
    else:
        try:
            ev = json.loads(ev_path.read_text(encoding="utf-8"))
        except json.JSONDecodeError as exc:
            ev = None
            add_error("rc.acceptance_evidence_invalid", f"Invalid JSON in acceptance evidence: {exc}")

        if isinstance(ev, dict):
            suites = ev.get("suites")
            total_tests = 0
            zero_suites = []
            failed_suites = []
            if isinstance(suites, list):
                if len(suites) == 0:
                    add_error("rc.zero_test_evidence", "Acceptance evidence contains zero suites")
                for idx, suite in enumerate(suites):
                    sname = str(suite.get("name", f"suite[{idx}]"))
                    executed = int(suite.get("testsExecuted", 0) or 0)
                    failed = int(suite.get("failed", 0) or 0)
                    exit_code = int(suite.get("exitCode", 0) or 0)
                    total_tests += executed
                    if executed <= 0:
                        zero_suites.append(sname)
                    if failed > 0 or exit_code != 0:
                        failed_suites.append(sname)
            else:
                total_tests = int(ev.get("testsExecuted", 0) or 0)

            acceptance_check["testsExecuted"] = total_tests
            if total_tests <= 0 or zero_suites:
                add_error(
                    "rc.zero_test_evidence",
                    "Acceptance evidence must execute at least one test per suite (zero-test evidence rejected)",
                    {"totalTests": total_tests, "zeroTestSuites": zero_suites},
                )
            if failed_suites:
                add_error("rc.acceptance_suite_failed", "Acceptance evidence contains failing suite(s)", failed_suites)
            acceptance_check["passed"] = (total_tests > 0 and not zero_suites and not failed_suites)
else:
    catalog_exists = (root / "docs" / "acceptance-catalog.md").is_file()
    acceptance_check["testsExecuted"] = mech02_check["discoveredTests"]
    acceptance_check["catalogPresent"] = catalog_exists
    if not catalog_exists:
        add_error("rc.acceptance_catalog_missing", "docs/acceptance-catalog.md is missing")
    elif mech02_check["discoveredTests"] <= 0:
        add_error("rc.zero_test_evidence", "Zero executable acceptance tests discovered")
    else:
        acceptance_check["passed"] = True

passed = len(errors) == 0
report = {
    "schema": "billium.quality.release_candidate.v1",
    "item": "PLAN1.P10.8",
    "mode": "local-only" if local_only else "standard",
    "timestamp": datetime.now(timezone.utc).isoformat(),
    "status": "passed" if passed else "failed",
    "exitCode": 0 if passed else 1,
    "sideEffects": {
        "networkCalls": 0,
        "publishExecuted": False,
        "gitMutations": False,
    },
    "profile": profile,
    "modules": selected_modules,
    "checks": {
        "profileModules": profile_check,
        "supplyChainAndHolds": holds_check,
        "deploymentManifest": manifest_check,
        "mech02": mech02_check,
        "acceptanceEvidence": acceptance_check,
    },
    "errorCount": len(errors),
    "errors": errors,
}

print(json.dumps(report, indent=2))
sys.exit(0 if passed else 1)
PY
)"
EXIT_CODE=$?
set -e

if [[ -n "$REPORT_PATH" ]]; then
    mkdir -p "$(dirname "$REPORT_PATH")"
    printf '%s\n' "$OUTPUT" >"$REPORT_PATH"
fi

printf '%s\n' "$OUTPUT"
exit "$EXIT_CODE"
