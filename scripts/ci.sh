#!/usr/bin/env bash
# Billium CI gate (M-33): the single carrier of the gate composition.
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
    [conformance]="cross-language vectors and golden"
)

fail() {
    echo "ci: $*" >&2
    return 1
}

need_path() {
    [[ -e "$1" ]] || fail "subject missing: $1"
}

need_tool() {
    command -v "$1" >/dev/null 2>&1 || [[ -x "$1" ]] || fail "tool missing: $1"
}

layer_lint() {
    need_tool python3
    need_path "$SPEC_REV_DIR/schema"
    python3 scripts/spec/lint.py "$SPEC_REV_DIR"
    need_path scripts/spec/lint-vectors.py
    python3 -m unittest discover -s scripts/spec/tests -p test_lint_vectors.py
    python3 scripts/spec/lint-vectors.py
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
}

layer_php() {
    need_tool composer
    need_path vendor/bin/pest
    local manifest
    composer validate --strict
    for manifest in packages/*/composer.json; do
        [[ -f "$manifest" ]] || fail "php: no packages/*/composer.json"
        composer validate --strict "$manifest"
    done
    vendor/bin/pint --test
    vendor/bin/phpstan analyse --memory-limit=1G
    vendor/bin/rector process --dry-run
    need_path scripts/db/preflight.php
    php scripts/db/preflight.php
    python3 -m unittest discover -s scripts/lint/tests -p test_test_discovery.py
    python3 scripts/lint/test-discovery.py
    vendor/bin/pest
    need_path scripts/smoke/consumer-install.sh
    bash scripts/smoke/consumer-install.sh php
}

layer_dart() {
    need_tool dart
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
    need_path conformance/runner/run.py
    need_path conformance/adapters.json
    need_path conformance/oracles/jcs/oracle.py
    python3 -m unittest discover -s conformance/tests
    python3 conformance/runner/run.py --check-golden
    python3 conformance/runner/run.py --adapter php
    python3 conformance/runner/run.py --adapter dart
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
    changed="$(git diff --name-only "$range")"
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

main() {
    local mode=all arg=""
    while (($# > 0)); do
        case "$1" in
            --layer) mode=layer; arg="${2:-}"; shift 2 ;;
            --list) mode=list; shift ;;
            --affected) mode=affected; arg="${2:-}"; shift 2 ;;
            *) fail "unknown argument: $1" ;;
        esac
    done

    case "$mode" in
        list) list_layers; return 0 ;;
        affected) affected_layers "$arg"; return 0 ;;
    esac

    local selected=("${LAYERS[@]}") layer known
    if [[ "$mode" == layer ]]; then
        known=0
        for layer in "${LAYERS[@]}"; do
            [[ "$layer" == "$arg" ]] && known=1
        done
        ((known == 1)) || fail "unknown layer: ${arg:-<empty>}"
        selected=("$arg")
    fi

    local status=0 rc
    local -a summary=()
    for layer in "${selected[@]}"; do
        # errexit is ignored inside an `if`/`||` context, so the layer runs as a plain statement.
        set +e
        (
            set -e
            run_layer "$layer"
        )
        rc=$?
        set -e
        if ((rc == 0)); then
            summary+=("$layer: PASS")
        else
            summary+=("$layer: FAIL")
            status=1
            break
        fi
    done

    echo "== summary =="
    printf '%s\n' "${summary[@]}"
    return "$status"
}

main "$@"
