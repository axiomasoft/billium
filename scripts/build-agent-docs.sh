#!/usr/bin/env bash
# Refresh CLAUDE.md from .ai/guidelines/agent-entrypoint.md.
# Packman owns AGENTS.md via `vendor/bin/packman ai:sync`.
# The maind healthcheck block is preserved.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
source="$root/.ai/guidelines/agent-entrypoint.md"
dest="$root/CLAUDE.md"

[[ -f "$source" ]] || {
    echo "build-agent-docs: missing $source" >&2
    exit 1
}

preserve=""
if [[ -f "$dest" ]]; then
    preserve="$(
        python3 - "$dest" <<'PY'
import pathlib
import sys

text = pathlib.Path(sys.argv[1]).read_text(encoding="utf-8")
start = text.find("<!-- maind:healthcheck")
if start == -1:
    raise SystemExit(0)
sys.stdout.write(text[start:])
if not text[start:].endswith("\n"):
    sys.stdout.write("\n")
PY
    )"
fi

{
    printf '%s\n\n' '<!-- generated from .ai/guidelines/agent-entrypoint.md by scripts/build-agent-docs.sh -->'
    cat "$source"
    if [[ -n "$preserve" ]]; then
        printf '\n%s\n' "$preserve"
    fi
} >"$dest"

echo "build-agent-docs: wrote CLAUDE.md"
