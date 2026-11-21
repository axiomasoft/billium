#!/usr/bin/env bash
# Install the server and its runtime dependency archives into a clean Laravel host.
set -euo pipefail

if [[ "${1:-}" == "--profile" ]]; then
    [[ "${2:-}" == "ledger-only" && "$#" == 2 ]] || exit 2
elif (( $# != 0 )); then
    exit 2
fi

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
work="$(mktemp -d)"
trap 'find "$work" -delete' EXIT

cache="$work/composer-cache"
shared_cache="${COMPOSER_CACHE_DIR:-${COMPOSER_HOME:-/tmp/composer}/cache}"
mkdir -p "$cache"
if [[ -r "$shared_cache/files" && -x "$shared_cache/files" ]]; then
    (
        cd "$shared_cache"
        # Reuse third-party downloads. The local packages must come from this run's archives.
        while IFS= read -r -d '' archive; do
            install -D -m 600 -- "$archive" "$cache/$archive" || continue
        done < <(find files -path files/axiomasoft -prune -o -type f -readable -print0 2>/dev/null)
    )
fi

python3 "$root/tests/minimal-host/server/prepare-archive.py" "$root" "$work"
cd "$work/host"
COMPOSER_CACHE_DIR="$cache" composer update --no-dev --prefer-dist --no-interaction --no-progress --no-audit
php smoke.php
