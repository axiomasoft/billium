#!/usr/bin/env bash
# Billium CI gate: the single carrier of the gate composition.
# Run inside the toolchain image:
#   docker compose run --rm toolchain bash scripts/ci.sh [--layer X | --list | --affected RANGE]
# A layer without a subject is red; a missing tool is red, never a skip.
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

LAYERS=(lint derived php dart conformance)
SPEC_REV_DIR=spec/revisions/2026-10

declare -A SUBJECTS=(
    [lint]="normative documents and schemas"
    [derived]="freshness of derived files"
    [php]="PHP packages"
    [dart]="Dart package"
    [conformance]="cross-language vectors, server HTTP, archive compatibility, restore, import, consumer contracts and migration drills"
)

fail() {
    echo "ci: $*" >&2
    exit 1
}

need_path() {
    [[ -e "$1" ]] || fail "subject missing: $1"
}

need_tool() {
    command -v "$1" >/dev/null 2>&1 || [[ -x "$1" ]] || fail "tool missing: $1"
}

layer_lint() {
    need_tool python3
    need_path scripts/ci/tests
    python3 -m unittest discover -s scripts/ci/tests -p 'test_*.py'
    need_path "$SPEC_REV_DIR/schema"
    python3 scripts/spec/lint.py "$SPEC_REV_DIR"
    need_path scripts/spec/lint-vectors.py
    need_path scripts/spec/check-module-compatibility.py
    python3 -m unittest discover -s scripts/spec/tests -p 'test_*.py'
    python3 scripts/spec/lint-vectors.py
    python3 scripts/spec/check-module-compatibility.py --check
    python3 -m unittest discover -s scripts/lint/tests -p 'test_migrations.py'
    python3 scripts/lint/migrations.py
    python3 -m unittest discover -s scripts/lint/tests -p 'test_acceptance_groups.py'
    python3 scripts/lint/acceptance-groups.py --phase P3
    python3 scripts/lint/acceptance-groups.py --phase P5
    python3 -m unittest discover -s scripts/lint/tests -p 'test_product_identities.py'
    need_path scripts/spec/check-import-contract.py
    need_path scripts/spec/check-import-fixtures.py
    need_path scripts/spec/check-integration-readiness.py
    need_path conformance/consumers/bridge/check-report.py
    need_path docs/migrations/source-mapping.json
    need_path artifacts/integration-readiness.json
    python3 scripts/spec/check-import-contract.py --check
    python3 scripts/spec/check-import-contract.py --source-mappings docs/migrations/source-mapping.json
    python3 scripts/spec/check-import-contract.py --handoff
    python3 scripts/spec/check-import-fixtures.py
    python3 -m unittest discover -s conformance/consumers/bridge/tests -p 'test_*.py'
    python3 conformance/consumers/bridge/check-report.py --fixture conformance/consumers/bridge/tests/reports
    python3 scripts/spec/check-integration-readiness.py --check artifacts/integration-readiness.json
}

layer_derived() {
    need_tool python3
    local found=0 script
    for script in scripts/spec/build-rules.py scripts/spec/build-currencies.py \
        scripts/spec/build-codes-index.py scripts/spec/build-golden.py scripts/spec/build-lock.py scripts/spec/identities.py \
        scripts/spec/type-map.py scripts/spec/export-*.py; do
        [[ -f "$script" ]] || continue
        python3 "$script" --check
        found=1
    done
    ((found == 1)) || fail "derived: no generator found under scripts/spec/"
    need_path scripts/docs/source-index.py
    need_path scripts/spec/check-server-api.py
    python3 scripts/docs/source-index.py --check
    python3 scripts/spec/check-server-api.py --check
}

layer_php() {
    need_tool composer
    need_tool php
    need_tool python3
    need_path vendor/bin/pest
    need_path vendor/bin/packman
    local manifest
    composer validate --strict
    for manifest in packages/*/composer.json packages/core/modules/*/composer.json; do
        [[ -f "$manifest" ]] || fail "php: missing manifest $manifest"
        composer validate --strict "$manifest"
    done
    mkdir -p build/phpstan build/rector .phpunit.cache
    vendor/bin/pint --test
    vendor/bin/phpstan analyse --memory-limit=1G
    vendor/bin/rector process --dry-run
    php -d memory_limit=1G vendor/bin/packman gate
    php -d memory_limit=1G vendor/bin/packman php-api --check
    php -d memory_limit=1G vendor/bin/packman error-codes --check
    php -d memory_limit=1G vendor/bin/packman config-schema --check
    need_path scripts/db/preflight.php
    PACKMAN_TEST_DESTRUCTIVE_OPT_IN=1 php scripts/db/preflight.php
    PACKMAN_TEST_DESTRUCTIVE_OPT_IN=1 php scripts/db/prepare-worker-template.php
    python3 -m unittest discover -s scripts/lint/tests -p test_test_discovery.py
    python3 scripts/lint/test-discovery.py
    vendor/bin/pest --exclude-group=pg
    # One database per worker; pg-serial tests depend on the cluster-wide transaction horizon and run alone.
    PACKMAN_TEST_DESTRUCTIVE_OPT_IN=1 PACKMAN_TEST_DB_PER_PROCESS=1 vendor/bin/pest --group=pg --exclude-group=pg-serial --parallel --processes=8
    PACKMAN_TEST_DESTRUCTIVE_OPT_IN=1 vendor/bin/pest --group=pg-serial
    need_path scripts/smoke/consumer-install.sh
    bash scripts/smoke/consumer-install.sh php
    need_path scripts/smoke/server-install.sh
    bash scripts/smoke/server-install.sh --profile ledger-only
}

layer_dart() {
    need_tool dart
    need_tool python3
    need_path packages/contracts-dart/pubspec.yaml
    (
        cd packages/contracts-dart
        dart pub get
        dart format --output=none --set-exit-if-changed .
        dart analyze --fatal-infos
        dart test
    )
    need_path scripts/lint/no-double.py
    python3 scripts/lint/no-double.py
    need_path scripts/smoke/consumer-install.sh
    bash scripts/smoke/consumer-install.sh dart
}

layer_conformance() {
    need_tool python3
    need_tool php
    need_tool dart
    need_path conformance/runner/run.py
    need_path conformance/adapters.json
    need_path conformance/oracles/jcs/oracle.py
    python3 -m unittest discover -s conformance/tests
    python3 conformance/runner/run.py --check-golden
    PACKMAN_TEST_DESTRUCTIVE_OPT_IN=1 php conformance/adapters/php/bin/with-database-lease.php python3 conformance/runner/run.py --adapter php
    (cd conformance/adapters/dart && dart pub get)
    python3 conformance/runner/run.py --adapter dart
    need_path scripts/test/server-http.sh
    need_path scripts/drills/server-restore.sh
    need_path conformance/compatibility/matrix.json
    need_path scripts/smoke/server-consumers.sh
    need_path artifacts/server-readiness.json
    python3 -m json.tool artifacts/server-readiness.json >/dev/null
    bash scripts/test/server-http.sh --client php --suite required --report build/server-readiness/http-php.json
    bash scripts/test/server-http.sh --client dart --suite required --report build/server-readiness/http-dart.json
    python3 conformance/compatibility/run.py --matrix conformance/compatibility/matrix.json
    bash scripts/smoke/server-consumers.sh
    bash scripts/drills/server-restore.sh
    need_path conformance/import/run.py
    need_path scripts/test/consumer-contract.sh
    need_path scripts/drills/integration-migration.sh
    need_path artifacts/migration-drill-manifest.json
    PACKMAN_TEST_DESTRUCTIVE_OPT_IN=1 python3 conformance/import/run.py --adapter php --suite all --report build/integration-readiness/import-php-all.json
    python3 conformance/import/run.py --adapter dart --suite codec --report build/integration-readiness/import-dart-codec.json
    PACKMAN_TEST_DESTRUCTIVE_OPT_IN=1 bash scripts/test/consumer-contract.sh --boundary loopback --suite all --report build/integration-readiness/consumer-loopback.json
    python3 conformance/consumers/bridge/check-report.py --report build/integration-readiness/consumer-loopback.json
    bash scripts/drills/integration-migration.sh --suite all --report build/integration-readiness/migration-drills.json
}

list_layers() {
    local layer
    for layer in "${LAYERS[@]}"; do
        echo "$layer: ${SUBJECTS[$layer]}"
    done
}

affected_layers() {
    local range="$1" zones changed
    need_tool python3
    need_path tests/zones.json
    changed="$(git -c safe.directory='*' diff --name-only "$range")"
    ZONES_CHANGED="$changed" python3 - <<'PY'
import fnmatch
import json
import os

with open("tests/zones.json", encoding="utf-8") as handle:
    zones = json.load(handle)["zones"]

changed = [line for line in os.environ["ZONES_CHANGED"].splitlines() if line]
order = ["lint", "derived", "php", "dart", "conformance"]
hit = set()
for zone in zones:
    patterns = zone["paths"]
    if any(fnmatch.fnmatch(path, pattern) for path in changed for pattern in patterns):
        hit.update(zone["layers"])
for layer in order:
    if layer in hit:
        print(layer)
PY
}

run_layer() {
    local layer="$1"
    echo "== $layer =="
    "layer_$layer"
}

# lint, derived and dart do not take the PostgreSQL suite lease. php and conformance do,
# and pg-serial stays inside php after the parallel pg workers.
declare -gA LAYER_RESULT=()
GATE_STATUS=0

record_layer() {
    local layer="$1" rc="$2"
    if ((rc == 0)); then
        LAYER_RESULT[$layer]=PASS
    else
        LAYER_RESULT[$layer]=FAIL
    fi
}

run_layer_captured() {
    local layer="$1" logfile="$2" rc
    # errexit is ignored inside an `if`/`||` context, so the subshell runs as a plain statement.
    set +e
    (
        set -e
        run_layer "$layer"
    ) </dev/null >"$logfile" 2>&1
    rc=$?
    set -e
    cat "$logfile"
    record_layer "$layer" "$rc"
    ((rc == 0)) || GATE_STATUS=1
}

run_parallel() {
    local logdir="$1"
    shift
    local layer rc i
    local -a layers=("$@") pids=()
    for layer in "${layers[@]}"; do
        (
            set -e
            run_layer "$layer"
        ) </dev/null >"$logdir/$layer.log" 2>&1 &
        pids+=("$!")
    done
    for i in "${!layers[@]}"; do
        layer="${layers[$i]}"
        set +e
        wait "${pids[$i]}"
        rc=$?
        set -e
        cat "$logdir/$layer.log"
        record_layer "$layer" "$rc"
        ((rc == 0)) || GATE_STATUS=1
    done
}

print_summary() {
    local layer
    echo "== summary =="
    for layer in "${LAYERS[@]}"; do
        if [[ -v "LAYER_RESULT[$layer]" ]]; then
            echo "$layer: ${LAYER_RESULT[$layer]}"
        fi
    done
}

main() {
    local mode=all arg=""
    while (($# > 0)); do
        case "$1" in
            --layer)
                (($# >= 2)) || fail "unknown layer: <empty>"
                mode=layer
                arg="$2"
                shift 2
                ;;
            --list) mode=list; shift ;;
            --affected)
                (($# >= 2)) || fail "missing range for --affected"
                mode=affected
                arg="$2"
                shift 2
                ;;
            *) fail "unknown argument: $1" ;;
        esac
    done

    case "$mode" in
        list) list_layers; return 0 ;;
        affected) affected_layers "$arg"; return 0 ;;
    esac

    GATE_STATUS=0
    local logdir
    if [[ "$mode" == layer ]]; then
        local known=0 layer
        for layer in "${LAYERS[@]}"; do
            [[ "$layer" == "$arg" ]] && known=1
        done
        ((known == 1)) || fail "unknown layer: ${arg:-<empty>}"
        logdir="$(mktemp -d)"
        run_layer_captured "$arg" "$logdir/$arg.log"
        rm -rf "$logdir"
        print_summary
        return "$GATE_STATUS"
    fi

    logdir="$(mktemp -d)"
    run_parallel "$logdir" lint derived dart
    if ((GATE_STATUS == 0)); then
        run_layer_captured php "$logdir/php.log"
    fi
    if ((GATE_STATUS == 0)); then
        run_layer_captured conformance "$logdir/conformance.log"
    fi
    rm -rf "$logdir"
    print_summary
    return "$GATE_STATUS"
}

main "$@"
