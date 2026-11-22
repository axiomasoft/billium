#!/usr/bin/env bash
# PLAN1.P10.6 — Compact supply-chain, license policy, and release hold sanity gate.
# Usage:
#   bash scripts/quality/release-supply-chain.sh [--profile baseline|contract-only|embedded|standalone|remote|remote-live] [--holds-file path.json] [--report path.json]
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

PROFILE="baseline"
HOLDS_FILE=""
REPORT_PATH=""

while [[ $# -gt 0 ]]; do
    case "$1" in
        --profile)
            PROFILE="${2:?--profile requires a value}"
            shift 2
            ;;
        --holds-file)
            HOLDS_FILE="${2:?--holds-file requires a file path}"
            shift 2
            ;;
        --report)
            REPORT_PATH="${2:?--report requires a file path}"
            shift 2
            ;;
        -h|--help)
            echo "Usage: bash scripts/quality/release-supply-chain.sh [--profile baseline|contract-only|embedded|standalone|remote|remote-live] [--holds-file path.json] [--report path.json]"
            exit 0
            ;;
        *)
            echo "Unknown option: $1" >&2
            exit 2
            ;;
    esac
done

set +e
OUTPUT="$(python3 - "$ROOT" "$PROFILE" "$HOLDS_FILE" <<'PY'
import json
import re
import sys
from datetime import datetime, timezone
from pathlib import Path

root = Path(sys.argv[1])
profile = sys.argv[2]
holds_file_arg = sys.argv[3]

PERMISSIVE_LICENSES = {
    "MIT",
    "BSD-2-Clause",
    "BSD-3-Clause",
    "Apache-2.0",
    "ISC",
    "0BSD",
    "MPL-2.0",
    "Unlicense",
    "CC0-1.0",
    "PSF-2.0",
    "Zlib",
}
FIRST_PARTY_PREFIXES = ("axiomasoft/billium-",)
DISALLOWED_PATTERNS = (
    re.compile(r"\bA?GPL\b", re.IGNORECASE),
    re.compile(r"^A?GPL[-_0-9.]", re.IGNORECASE),
)


def is_disallowed_license(lic: str) -> bool:
    return any(p.search(lic) for p in DISALLOWED_PATTERNS)


composer_lock = root / "composer.lock"
composer_summary = {
    "present": composer_lock.is_file(),
    "packagesInspected": 0,
    "firstPartyPackages": [],
    "licenseCounts": {},
    "dualLicensedResolved": [],
    "disallowedPackages": [],
    "unknownLicensePackages": [],
}

if composer_lock.is_file():
    data = json.loads(composer_lock.read_text(encoding="utf-8"))
    all_pkgs = []
    for section, is_dev in (("packages", False), ("packages-dev", True)):
        for pkg in data.get(section, []):
            all_pkgs.append((pkg, is_dev))
    composer_summary["packagesInspected"] = len(all_pkgs)

    for pkg, is_dev in all_pkgs:
        name = str(pkg.get("name", ""))
        version = str(pkg.get("version", ""))
        licenses = [str(x).strip() for x in (pkg.get("license") or []) if str(x).strip()]
        scope = "dev" if is_dev else "prod"

        if not licenses:
            composer_summary["unknownLicensePackages"].append({"name": name, "version": version, "scope": scope})
            continue

        key = " OR ".join(licenses)
        composer_summary["licenseCounts"][key] = composer_summary["licenseCounts"].get(key, 0) + 1

        if any(name.startswith(prefix) for prefix in FIRST_PARTY_PREFIXES) and licenses == ["proprietary"]:
            composer_summary["firstPartyPackages"].append({"name": name, "version": version, "scope": scope, "license": "proprietary"})
            continue

        permissive = [l for l in licenses if l in PERMISSIVE_LICENSES]
        disallowed = [l for l in licenses if is_disallowed_license(l)]

        if disallowed and permissive:
            composer_summary["dualLicensedResolved"].append({
                "name": name,
                "version": version,
                "scope": scope,
                "declared": licenses,
                "selected": permissive[0],
            })
        elif disallowed and not permissive:
            composer_summary["disallowedPackages"].append({
                "name": name,
                "version": version,
                "scope": scope,
                "declared": licenses,
            })
        elif not permissive:
            composer_summary["unknownLicensePackages"].append({
                "name": name,
                "version": version,
                "scope": scope,
                "declared": licenses,
            })

pubspec_candidates = [
    root / "pubspec.lock",
    root / "packages" / "contracts-dart" / "pubspec.lock",
]
dart_lockfiles = []
for candidate in pubspec_candidates:
    if candidate.is_file():
        text = candidate.read_text(encoding="utf-8")
        pkg_names = re.findall(r"^  ([a-zA-Z0-9_]+):\s*$", text, flags=re.MULTILINE)
        direct_main = re.findall(r'dependency:\s*"?direct main"?', text)
        dart_lockfiles.append({
            "path": str(candidate.relative_to(root)),
            "packagesLocked": len(pkg_names),
            "directMainDependencies": len(direct_main),
        })

unresolved_holds = []

if holds_file_arg:
    holds_path = Path(holds_file_arg)
    if not holds_path.is_file():
        unresolved_holds.append({"source": holds_file_arg, "profile": profile, "reason": "holds_file_missing"})
    else:
        hdata = json.loads(holds_path.read_text(encoding="utf-8"))
        for item in hdata.get("holds", []):
            item_profile = str(item.get("profile", "*"))
            status = str(item.get("status", "unresolved"))
            if item_profile in ("*", profile) and status not in ("resolved", "waived", "not-applicable"):
                unresolved_holds.append({
                    "id": item.get("id", "unknown"),
                    "profile": item_profile,
                    "status": status,
                    "reason": item.get("reason", "unresolved hold"),
                })

if profile == "remote-live":
    server_readiness = root / "artifacts" / "server-readiness.json"
    if server_readiness.is_file():
        sdata = json.loads(server_readiness.read_text(encoding="utf-8"))
        gate = sdata.get("activation_gate", {})
        if not gate.get("production_execution_qualified", False):
            unresolved_holds.append({
                "id": "server.activation_gate.production_execution_qualified",
                "profile": "remote-live",
                "status": "unresolved",
                "source": "artifacts/server-readiness.json",
            })
        for field in (
            "named_operational_owner",
            "slo_and_escalation_policy",
            "retention_and_backup_policy",
            "selected_driver_qualification",
        ):
            if gate.get(field) is None:
                unresolved_holds.append({
                    "id": f"server.activation_gate.{field}",
                    "profile": "remote-live",
                    "status": "unresolved",
                    "source": "artifacts/server-readiness.json",
                })

passed = (
    len(composer_summary["disallowedPackages"]) == 0
    and len(composer_summary["unknownLicensePackages"]) == 0
    and len(unresolved_holds) == 0
)

report = {
    "schema": "billium.quality.supply_chain.v1",
    "item": "PLAN1.P10.6",
    "profile": profile,
    "timestamp": datetime.now(timezone.utc).isoformat(),
    "status": "passed" if passed else "failed",
    "exitCode": 0 if passed else 1,
    "composer": composer_summary,
    "dart": {
        "lockfilesInspected": len(dart_lockfiles),
        "lockfiles": dart_lockfiles,
    },
    "holds": {
        "profile": profile,
        "unresolvedCount": len(unresolved_holds),
        "unresolved": unresolved_holds,
    },
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
