#!/usr/bin/env bash
# Run selected transcript fixtures on fresh test databases and loopback-only sockets.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$root"
umask 077
if command -v php8.4 >/dev/null 2>&1 || [[ -n "${PHP84_BIN:-}" ]]; then
    exec bin/php84 python3 conformance/http/run.py "$@"
fi
php -r 'exit(PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 4 ? 0 : 1);'
exec python3 conformance/http/run.py "$@"
