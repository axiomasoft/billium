#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SOURCE="$ROOT/.ai/guidelines/agent-entrypoint.md"
MAX_BYTES=2048
MODE="${1:-write}"

[ -f "$SOURCE" ] || { echo "Missing source: $SOURCE" >&2; exit 1; }
bytes="$(wc -c < "$SOURCE")"
[ "$bytes" -le "$MAX_BYTES" ] || {
    echo "Entrypoint is too large: $bytes > $MAX_BYTES bytes" >&2
    exit 1
}

render() {
    printf '<!-- generated from .ai/guidelines/agent-entrypoint.md by scripts/build-agent-docs.sh -->\n\n'
    cat "$SOURCE"
}

# Other tools own the trailing managed sections. Keep their bytes intact when
# refreshing our entrypoint, and include them in the check's expected output.
managed_suffix() {
    python3 - "$1" <<'PY'
import pathlib, sys
path = pathlib.Path(sys.argv[1])
text = path.read_text() if path.exists() else ''
markers = ('<!-- swissknifeman:hub:start -->', '<!-- maind:healthcheck ')
positions = [text.index(marker) for marker in markers if marker in text]
if positions:
    sys.stdout.write('\n' + text[min(positions):])
PY
}

render_target() {
    render
    managed_suffix "$1"
}

case "$MODE" in
    write)
        for target in "$ROOT/CLAUDE.md" "$ROOT/AGENTS.md"; do
            output="$(mktemp)"
            render_target "$target" > "$output"
            cat "$output" > "$target"
            rm "$output"
        done
        ;;
    --check)
        cmp -s <(render_target "$ROOT/CLAUDE.md") "$ROOT/CLAUDE.md" && cmp -s <(render_target "$ROOT/AGENTS.md") "$ROOT/AGENTS.md" || {
            echo "Agent entrypoints are stale; run bash scripts/build-agent-docs.sh" >&2
            exit 1
        }
        ;;
    *)
        echo "Usage: $0 [--check]" >&2
        exit 2
        ;;
esac
