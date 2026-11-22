#!/usr/bin/env bash
# PLAN1.P9.7 / D59. Rehearse with sanitized fixtures on the disposable PostgreSQL stand.
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.."
umask 077

php_runner=()
if command -v php8.4 >/dev/null 2>&1 || [[ -n "${PHP84_BIN:-}" ]]; then
    php_runner=(bin/php84)
fi

exec "${php_runner[@]}" python3 - "$@" <<'PY'
import argparse
from datetime import datetime, timezone
from getpass import getuser
from hashlib import sha256
import json
import os
from pathlib import Path
import re
import shlex
import subprocess
import sys
import tempfile
import xml.etree.ElementTree as xml


MANIFEST = Path("artifacts/migration-drill-manifest.json")
GATES = [f"MGATE-{number:02}" for number in range(1, 12)]


def utc_now():
    return datetime.now(timezone.utc).isoformat()


def digest(path):
    return sha256(Path(path).read_bytes()).hexdigest()


def record_file(path, report_dir):
    return {"path": str(path.relative_to(report_dir)), "sha256": digest(path)}


def run_step(step_id, command, directory, report_dir, environment):
    log = directory / f"{step_id}.log"
    started = utc_now()
    with log.open("wb") as output:
        result = subprocess.run(command, env=environment, stdout=output, stderr=subprocess.STDOUT, check=False)
    print(f"integration-migration: {step_id} exit={result.returncode} ({log})", flush=True)
    return {
        "id": step_id,
        "command": shlex.join(command),
        "timestamp": started,
        "exit": result.returncode,
        "output": record_file(log, report_dir),
    }


def expected_cases(tests):
    return [
        f'{method} with data set "{variant}"' if variant is not None else method
        for method, case in tests.items()
        for variant in case.get("variants", [None])
    ]


def check_junit(junit, expected):
    if not junit.is_file():
        return "JUnit output missing"
    try:
        results = xml.parse(junit).getroot().iter("testcase")
        observed = [
            case.get("name") for case in results
            if all(case.find(tag) is None for tag in ("error", "failure", "skipped"))
            and int(case.get("assertions", "0")) > 0
        ]
    except (xml.ParseError, ValueError) as error:
        return f"Invalid JUnit evidence: {error}"
    if sorted(expected) != sorted(observed):
        return f"Expected successful cases {expected!r}, observed {observed!r}"
    return None


manifest = json.loads(MANIFEST.read_text(encoding="utf-8"))
parser = argparse.ArgumentParser(prog="scripts/drills/integration-migration.sh")
parser.add_argument("--scenario", "--suite", default="all", choices=["all", *GATES])
parser.add_argument("--report", type=Path, default=Path(manifest["default_report"]))
args = parser.parse_args()

declared = {gate for step in manifest["steps"] for test in step["tests"].values() for gate in test["gates"]}
if declared != set(GATES):
    parser.error("the manifest must cover MGATE-01 through MGATE-11")
chosen = GATES if args.scenario == "all" else [args.scenario]
report = args.report.resolve()
if report.suffix != ".json":
    parser.error("--report requires a .json path")
report.parent.mkdir(parents=True, exist_ok=True)
directory = Path(tempfile.mkdtemp(prefix="integration-migration-", dir=report.parent))

# Keep the stand's PHPUnit defaults and allow its usual explicit environment overrides.
environment = os.environ.copy()
for entry in xml.parse("phpunit.xml").findall("./php/env"):
    environment.setdefault(entry.attrib["name"], entry.attrib["value"])
environment["DB_CONNECTION"] = "pgsql"
environment["PACKMAN_TEST_DESTRUCTIVE_OPT_IN"] = "1"

started = utc_now()
probe = [
    "php", "-r",
    'require "tests/bootstrap.php"; '
    'if (PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== 4) { '
    'fwrite(STDERR, "PHP 8.4 required\n"); exit(1); } '
    'Billium\\Core\\Tests\\TestEnvironment::preflight(); '
    '$db = Billium\\Core\\Tests\\TestEnvironment::pdo(); '
    'echo json_encode(["php" => PHP_VERSION, "postgresql" => $db->getAttribute(PDO::ATTR_SERVER_VERSION)], JSON_THROW_ON_ERROR), PHP_EOL;',
]
preflight = run_step("preflight", probe, directory, report.parent, environment)
runtime = {}
steps = []
if preflight["exit"] == 0:
    runtime = json.loads((directory / "preflight.log").read_text(encoding="utf-8"))
    for entry in manifest["steps"]:
        selected = {method: test for method, test in entry["tests"].items() if set(test["gates"]).intersection(chosen)}
        if not selected:
            continue
        step_id = entry["id"]
        junit = directory / f"{step_id}.junit.xml"
        selector = "/(?:::|^)(?:" + "|".join(re.escape(method) for method in selected) + r")(?: with data set |$)/"
        command = ["php", "vendor/bin/phpunit", entry["file"], "--filter", selector, "--log-junit", str(junit), "--colors=never"]
        step = run_step(step_id, command, directory, report.parent, environment)
        expected = expected_cases(selected)
        error = check_junit(junit, expected)
        steps.append({
            **step,
            "boundary": entry["boundary"],
            "gates": sorted({gate for test in selected.values() for gate in test["gates"] if gate in chosen}),
            "required_cases": expected,
            "junit": record_file(junit, report.parent) if junit.is_file() else None,
            "case_verification_error": error,
            "passed": step["exit"] == 0 and error is None,
        })

gate_results = {}
for gate in chosen:
    required = [step for step in steps if gate in step["gates"]]
    gate_results[gate] = {
        "status": "passed" if required and all(step["passed"] for step in required) else "failed",
        "steps": [step["id"] for step in required],
    }
exit_code = 0 if all(result["status"] == "passed" for result in gate_results.values()) else 1
inputs = [MANIFEST, Path(manifest["runner"]), Path("composer.lock"), Path("phpunit.xml")]
inputs.extend(sorted({Path(step["file"]) for step in manifest["steps"]}))
document = {
    "schema": "billium.migration.drill_report.v1",
    "item": manifest["item"],
    "scenario": args.scenario,
    "author": os.environ.get("BILLIUM_EVIDENCE_AUTHOR") or os.environ.get("USER") or (f"uid:{os.getuid()}" if hasattr(os, "getuid") else "unknown"),
    "command": shlex.join([manifest["runner"], *sys.argv[1:]]),
    "timestamp": started,
    "runtime": runtime,
    "source_head": subprocess.check_output(["git", "-c", f"safe.directory={Path.cwd()}", "rev-parse", "HEAD"], text=True).strip(),
    "inputs_sha256": {str(path): digest(path) for path in inputs},
    "preflight": preflight,
    "steps": steps,
    "gates": gate_results,
    "activation_inputs": manifest["activation_inputs"],
    "exit": exit_code,
}
temporary = directory / "report.json.tmp"
temporary.write_text(json.dumps(document, indent=2) + "\n", encoding="utf-8")
temporary.replace(report)
print(f"integration-migration: {report} sha256:{digest(report)}", flush=True)
sys.exit(exit_code)
PY
