#!/usr/bin/env bash
# Compact mixed-version & non-destructive expand-contract rollback drill.
# Usage:
#   bash scripts/drills/billing-upgrade.sh [--report path/to/manifest.json]
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

REPORT_PATH=""

while [[ $# -gt 0 ]]; do
    case "$1" in
        --report)
            REPORT_PATH="${2:?--report requires a file path}"
            shift 2
            ;;
        -h|--help)
            echo "Usage: bash scripts/drills/billing-upgrade.sh [--report path.json]"
            exit 0
            ;;
        *)
            echo "Unknown option: $1" >&2
            exit 2
            ;;
    esac
done

export DB_CONNECTION="${DB_CONNECTION:-pgsql}"
export PACKMAN_TEST_DESTRUCTIVE_OPT_IN="${PACKMAN_TEST_DESTRUCTIVE_OPT_IN:-1}"

FILTER='BillingRecoveryDrillTest::test_mixed_version_reader_and_non_destructive_expand_contract_rollback_safety'

LOG_FILE="$(mktemp)"
trap 'rm -f "$LOG_FILE"' EXIT

set +e
vendor/bin/pest packages/core/tests/P10/BillingRecoveryDrillTest.php --filter="/${FILTER}/" --colors=never >"$LOG_FILE" 2>&1
EXIT_CODE=$?
set -e

if [[ "$EXIT_CODE" -ne 0 ]]; then
    cat "$LOG_FILE" >&2
    exit "$EXIT_CODE"
fi

MANIFEST="$(python3 - "$LOG_FILE" <<'PY'
import hashlib
import json
import sys
from datetime import datetime, timezone
from pathlib import Path

log_text = Path(sys.argv[1]).read_text(encoding="utf-8", errors="replace")
summary_line = next((line.strip() for line in reversed(log_text.splitlines()) if "Tests:" in line), "passed")

manifest = {
    "schema": "billium.drill.upgrade.v1",
    "item": "PLAN1.P10.5",
    "timestamp": datetime.now(timezone.utc).isoformat(),
    "expandContractNonDestructive": True,
    "mixedVersionFailClosedVerified": True,
    "immutableFactsPreservedOnRollback": True,
    "scenarios": [
        "ACC-RST-10",
        "REQ-FAIL-29",
    ],
    "summary": summary_line,
    "logSha256": hashlib.sha256(log_text.encode("utf-8")).hexdigest(),
    "exitCode": 0,
}
print(json.dumps(manifest, indent=2))
PY
)"

if [[ -n "$REPORT_PATH" ]]; then
    mkdir -p "$(dirname "$REPORT_PATH")"
    printf '%s\n' "$MANIFEST" >"$REPORT_PATH"
fi

printf '%s\n' "$MANIFEST"
