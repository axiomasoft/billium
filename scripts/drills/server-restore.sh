#!/usr/bin/env bash
# D54/D58/D59/D62. Run on the role-separated PostgreSQL test stand.
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.."
umask 077
report=build/server-readiness/drill.json
scenario=stale-snapshot-clone

usage() {
    echo 'usage: scripts/drills/server-restore.sh [--scenario stale-snapshot-clone] [--report PATH]' >&2
    exit 2
}

while (($#)); do
    [[ $# -ge 2 && -n "$2" ]] || usage
    case "$1" in
        --scenario) scenario="$2" ;;
        --report) report="$2" ;;
        *) usage ;;
    esac
    shift 2
done
[[ "$scenario" == stale-snapshot-clone ]] || usage

php_runner=()
if command -v php8.4 >/dev/null 2>&1 || [[ -n "${PHP84_BIN:-}" ]]; then
    php_runner=(bin/php84)
fi
export PACKMAN_TEST_DESTRUCTIVE_OPT_IN=1
exec "${php_runner[@]}" python3 - "$report" "$scenario" <<'PY'
from datetime import datetime, timezone
from hashlib import sha256
import json
import os
from pathlib import Path
import pwd
import shlex
import subprocess
import sys
import tempfile


def utc_now():
    return datetime.now(timezone.utc).isoformat()


def digest(path):
    with Path(path).open("rb") as stream:
        return sha256(stream.read()).hexdigest()


def evidence_author():
    if author := os.environ.get("BILLIUM_EVIDENCE_AUTHOR"):
        return author
    uid = os.getuid()
    try:
        return pwd.getpwuid(uid).pw_name
    except KeyError:
        return f"uid:{uid}"


report = Path(sys.argv[1]).resolve()
report.parent.mkdir(parents=True, exist_ok=True)
if report.suffix != ".json":
    sys.exit("server-restore: --report requires a .json path")

run = Path(tempfile.mkdtemp(prefix="server-restore-", dir=report.parent))
php = subprocess.check_output(["php", "-r", "echo PHP_VERSION;"], text=True)
if not php.startswith("8.4."):
    sys.exit("server-restore: expected PHP 8.4")

started = utc_now()
steps = []
for name, boundary, classes, command in [
    ("restore", "external-runtime", ["server-runtime", "host-double", "provider-double"],
     ["php", "scripts/drills/execution-restore.php"]),
    ("readiness", "external-runtime", ["server-runtime", "host-double"],
     ["php", "vendor/bin/pest", "packages/server/tests/Integration/ReadinessTest.php",
      "packages/server/tests/ServerHostTest.php"]),
    ("lost-response", "loopback", ["server-runtime", "transport-contract", "provider-double"],
     ["bash", "scripts/test/server-http.sh", "--client", "php", "--suite", "HTTP-04",
      "--report", str(run / "lost-response.json")]),
]:
    log = run / f"{name}.output.log"
    with log.open("wb") as stream:
        timestamp = utc_now()
        exit_code = subprocess.run(command, stdout=stream, stderr=subprocess.STDOUT).returncode
    steps.append({"id": name, "boundary": boundary, "evidence_classes": classes,
                  "command": shlex.join(command), "timestamp": timestamp, "exit": exit_code,
                  "output": str(log.relative_to(report.parent)), "output_sha256": digest(log)})
    print(f"server-restore: {name} exit={exit_code} ({log})", flush=True)

transcript = run / "lost-response.json"
proofs = ([{"path": str(transcript.relative_to(report.parent)), "sha256": digest(transcript)}]
          if transcript.is_file() else [])
document = {
    "item": "PLAN1.P8.8",
    "scenario": sys.argv[2],
    "author": evidence_author(),
    "timestamp": started,
    "runtime": {"php": php, "postgres_client": subprocess.check_output(["pg_dump", "--version"], text=True).strip()},
    "source_head": subprocess.check_output(["git", "-c", f"safe.directory={Path.cwd()}", "rev-parse", "HEAD"], text=True).strip(),
    "toolchain_sha256": digest("docker/toolchain/Dockerfile"),
    "toolchain_input_sha256": {path: digest(path) for path in ("composer.lock", "docker/toolchain/requirements.txt")},
    "spec_lock_sha256": digest("spec/revisions/2026-10/revision.lock.json"),
    "migration_sha256": {str(path): digest(path) for module in ("platform", "ledger", "execution")
                         for path in sorted(Path(f"packages/core/database/migrations/{module}").glob("*.php"))},
    "package_artifact_sha256": None,
    "passed": all(step["exit"] == 0 for step in steps),
    "production_execution_qualified": False,
    "evidence": [
        {"boundary": "external-runtime", "evidence_class": "server-runtime", "steps": ["restore", "readiness"]},
        {"boundary": "loopback", "evidence_class": "transport-contract", "steps": ["lost-response"]},
        {"boundary": "double", "evidence_class": "host-double", "steps": ["restore", "readiness", "lost-response"]},
        {"boundary": "double", "evidence_class": "provider-double", "steps": ["restore", "lost-response"]},
    ],
    "steps": steps,
    "transcripts": proofs,
}
document["exit"] = 0 if document["passed"] else 1
temporary = run / "drill.json.tmp"
temporary.write_text(json.dumps(document, indent=2) + "\n", encoding="utf-8")
temporary.replace(report)
print(f"server-restore: report {report} sha256:{digest(report)}", flush=True)
sys.exit(document["exit"])
PY
