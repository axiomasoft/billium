#!/usr/bin/env bash
# PLAN1.P10.5 — Compact restore, fencing, activation, and retention drill.
# Usage:
#   bash scripts/drills/billing-restore.sh --fake-provider [--report path/to/manifest.json]
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

FAKE_PROVIDER=0
REPORT_PATH=""

while [[ $# -gt 0 ]]; do
    case "$1" in
        --fake-provider)
            FAKE_PROVIDER=1
            shift
            ;;
        --report)
            REPORT_PATH="${2:?--report requires a file path}"
            shift 2
            ;;
        -h|--help)
            echo "Usage: bash scripts/drills/billing-restore.sh --fake-provider [--report path.json]"
            exit 0
            ;;
        *)
            echo "Unknown option: $1" >&2
            exit 2
            ;;
    esac
done

if [[ "$FAKE_PROVIDER" -ne 1 ]]; then
    echo "billing-restore.sh requires --fake-provider for deterministic non-production rehearsal" >&2
    exit 2
fi

export DB_CONNECTION="${DB_CONNECTION:-pgsql}"
export PACKMAN_TEST_DESTRUCTIVE_OPT_IN="${PACKMAN_TEST_DESTRUCTIVE_OPT_IN:-1}"

FILTER='BillingRecoveryDrillTest::(test_restored_snapshot_starts_in_recovery_with_zero_auto_dispatch_and_old_worker_fencing|test_explicit_activation_required_and_equal_commit_max_never_auto_activates|test_retention_prune_preserves_legal_holds_unknown_evidence_and_dedupe_floors)'

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
    "schema": "billium.drill.restore.v1",
    "item": "PLAN1.P10.5",
    "providerMode": "fake-provider",
    "timestamp": datetime.now(timezone.utc).isoformat(),
    "zeroSideEffectsBeforeActivation": True,
    "oldWorkerFencingVerified": True,
    "equalCommitMaxNoAutoActivateVerified": True,
    "retentionFloorsPreserved": True,
    "scenarios": [
        "ACC-RST-03",
        "ACC-RST-04",
        "ACC-RST-06",
        "ACC-RST-08",
        "ACC-RST-13",
        "PB-13",
        "PB-20",
        "PF-07",
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
