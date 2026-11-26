#!/usr/bin/env python3
"""Execute HTTP transcript fixtures against isolated Laravel/PostgreSQL loopback hosts."""

from __future__ import annotations

import argparse
from contextlib import contextmanager
import json
import os
from pathlib import Path
import socket
import subprocess
import sys
import tempfile
from time import monotonic, sleep

from jsonschema import Draft202012Validator

sys.dont_write_bytecode = True
HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[1]
FIXTURES = ROOT / "fixtures/integration/http"
SPEC = ROOT / "spec/revisions/2026-10"
sys.path[:0] = [str(ROOT / "conformance/runner"), str(ROOT / "conformance/oracles/jcs")]

from protocol import Schemas, Session  # noqa: E402
from transport import digest, interrupt, json_bytes, relay, sealed  # noqa: E402

REQUIRED = tuple(f"HTTP-{index:02}" for index in range(1, 22))
SCOPE = {"installationRef": "inst-test", "environment": "test", "sellerRef": "seller-a"}
DOUBLE = "provider-double"
HOST_DOUBLE = "host-double"


def load(path):
    return json.loads(path.read_text())


def validator(name):
    schema = load(FIXTURES / "schema" / f"{name}.json")
    Draft202012Validator.check_schema(schema)
    return Draft202012Validator(schema)


def tree_digest(paths):
    members = {}
    for directory in paths:
        files = sorted(directory.rglob("*")) if directory.is_dir() else [directory]
        for path in files:
            if path.is_file() and "__pycache__" not in path.parts and not set(path.parts) & {".dart_tool", ".git"}:
                members[str(path.relative_to(ROOT))] = digest(path.read_bytes())
    return digest(json_bytes(members))


def corpus():
    check = validator("transcript")
    examples = {}
    for path in sorted((FIXTURES / "vectors").rglob("HTTP-*.json")):
        doc = load(path)
        check.validate(doc)
        if doc["id"] in examples:
            raise ValueError(f'Duplicate case {doc["id"]}')
        examples[doc["id"]] = doc
    if set(examples) != set(REQUIRED):
        raise ValueError(f"HTTP fixture inventory mismatch: {set(examples).symmetric_difference(REQUIRED)}")
    return examples


def field(value, path):
    for name in path.split(".") if path else []:
        if name == "#":
            value = len(value)
        else:
            value = value[int(name)] if isinstance(value, list) else value[name]
    return value


def expand(value, data):
    if isinstance(value, dict):
        if set(value) == {"$ref"}:
            return field(data, value["$ref"])
        return {name: expand(item, data) for name, item in value.items()}
    if isinstance(value, list):
        return [expand(item, data) for item in value]
    return value


def verify(actual, expected, label):
    for path, value in expected.items():
        found = field(actual, path)
        if found != value or type(found) is not type(value):
            raise ValueError(f"{label}.{path}: expected {value!r}, got {found!r}")


def reserve_port():
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        return sock.getsockname()[1]


def correlate(directory, inputs):
    """COV-AC-524 host fixture. Persist one grant/receipt pair across a lost host reply."""
    grant = inputs["grant"]
    admission = inputs["admission"]
    outcome = inputs["outcome"]
    allocation = inputs["allocation"]
    result = outcome["result"]
    if (
        grant != {"grantRef": "fixture-grant-a", "sellerRef": SCOPE["sellerRef"], "operationRef": "root-a"}
        or admission["receipt"] != "CommandAccepted"
        or grant["operationRef"] != admission["operationRef"]
        or result["paymentRef"] != grant["operationRef"]
        or result["projectionStatus"] != "current"
        or result["financialHold"]["active"]
        or result["applied"]["allocated"] != {"amount": "1.00", "currency": "RUB"}
        or allocation["receiptRef"] != allocation["body"]["receiptRef"]
        or allocation["body"]["body"]["operation_ref"] != grant["operationRef"]
        or allocation["body"]["sellerRef"] != grant["sellerRef"]
    ):
        return {"status": "refused"}
    path = directory / "host-provisioning.json"
    binding = {
        "grant": grant, "admissionDigest": admission["digest"], "allocationRef": allocation["receiptRef"],
        "reference": digest(json_bytes([grant["grantRef"], allocation["receiptRef"]])),
    }
    if path.exists():
        if load(path) != binding:
            return {"status": "conflict"}
    else:
        with path.open("x") as state:
            state.write(json.dumps(binding))
            state.flush()
            os.fsync(state.fileno())
    return {"status": "lost-response" if inputs.get("loseResponse") else "found", "count": 1, "binding": binding}


def php_command(*args):
    return [os.environ.get("PHP84_BIN", "php"), *map(str, args)]


class Stand:
    def __init__(self, directory, log):
        self.directory = directory
        self.log = log

    def command(self, action, arguments=None, *cli):
        cmd = php_command(HERE / "host.php", action, self.directory, *cli)
        result = subprocess.run(cmd, cwd=ROOT, input=json_bytes(arguments), stdout=subprocess.PIPE,
                                stderr=self.log, timeout=40, check=True)
        return json.loads(result.stdout) if action != "drop" else None

    @contextmanager
    def listen(self, profile):
        version = self.command("create", None, profile)
        try:
            port = reserve_port()
            environment = {**os.environ, "BILLIUM_HTTP_WORK": str(self.directory)}
            environment.pop("PHP_CLI_SERVER_WORKERS", None)
            server = subprocess.Popen(php_command("-S", f"127.0.0.1:{port}", HERE / "host.php"),
                                      cwd=self.directory, env=environment, stdout=self.log, stderr=self.log)
            try:
                deadline = monotonic() + 5
                while monotonic() < deadline and server.poll() is None:
                    try:
                        with socket.create_connection(("127.0.0.1", port), timeout=0.1):
                            break
                    except OSError:
                        sleep(0.02)
                else:
                    raise RuntimeError("PHP loopback listener did not start")
                yield port, version
            finally:
                server.terminate()
                try:
                    server.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    server.kill()
                    server.wait()
        finally:
            self.command("drop")


class Transcript:
    def __init__(self, stand, codec, client, port):
        self.stand = stand
        self.codec = codec
        self.client = client
        self.port = port
        self.prepared = {}
        self.data = {"scope": SCOPE, "specHash": digest((SPEC / "revision.lock.json").read_bytes())}
        self.entries = []

    def send(self, step):
        name = step["name"]
        if "use" in step:
            method, path, body, key = self.prepared[step["use"]]
        else:
            path = step["path"]
            method = "POST" if "body" in step else "GET"
            body = step.get("body")
            if step.get("scoped"):
                body = {**SCOPE, **body}
            if "seal" in step:
                body = sealed(body, step["seal"])
            key = body.get("commandKey") if isinstance(body, dict) else None
            body = b"" if method == "GET" else json_bytes(body)
        if not path.startswith("/billium/") or any(char in path for char in "\r\n"):
            raise ValueError("Transcript request paths must be Billium loopback paths")
        self.prepared[name] = method, path, body, key
        headers = {"Accept": "application/json", "Content-Type": "application/json",
                   "Authorization": "Bearer " + step.get("actor", "owner")}
        if key:
            headers["Idempotency-Key"] = key
        if "delivery" in step:
            headers["X-Fixture-Delivery"] = step["delivery"]

        fault = step.get("fault", "forward")
        with relay(self.port, fault, step.get("mutation", {})) as (proxy, trace):
            if fault == "interrupt":
                interrupt(proxy, path, headers, body)
                response = {"transport": "client-interrupted"}
            else:
                command = php_command(HERE / "client.php") if self.client == "php" else ["dart", str(HERE / "client.dart")]
                request = {"method": method, "path": path, "port": proxy, "headers": headers,
                           "body": body.decode(), "timeoutMs": step.get("timeoutMs", 15_000)}
                result = subprocess.run(command, cwd=ROOT, input=json_bytes(request), stdout=subprocess.PIPE,
                                        stderr=self.stand.log, timeout=25, check=True)
                response = json.loads(result.stdout)

        verify(trace, {"requestDigest": digest(body), "commandKey": key}, name)
        result = {"wire": trace, **response}
        if "status" in response:
            wire = response["body"]
            result["responseDigest"] = digest(wire.encode())
            if "json" in response["headers"].get("content-type", ""):
                try:
                    result["body"] = json.loads(wire)
                except ValueError:
                    result.pop("body")
                operation = "envelope.roundtrip" if response["status"] < 300 else "problem.decode"
                if "schema" in result.get("body", {}) or "code" in result.get("body", {}) or fault == "malformed":
                    result["decode"] = self.run_codec(name, operation, {"json_text": wire})
                    if "ok" in result["decode"] and "text" in result["decode"]["ok"]:
                        verify({"body": json.loads(result["decode"]["ok"]["text"])}, {"body": result["body"]}, name)
                        if step.get("verifyDigest"):
                            digest_result = self.run_codec(name, "canon.digest", {"json_text": wire})
                            if digest_result.get("ok", {}).get("digest") != result["body"].get("digest"):
                                result["decode"] = {"error": {"code": "billium.validation.invalid", "detail": "full-envelope digest mismatch"}}
                            else:
                                result["fullDigestVerified"] = True
                    if "error" in result["decode"] and not any(k.startswith("decode.error") for k in step["expect"]):
                        raise ValueError(f"{name}: client rejected response {result['decode']['error']}")
            else:
                result.pop("body")
        return result

    def run_codec(self, name, op, arguments):
        outputs = self.codec.run(name, {}, [{"op": op, "args": arguments}])
        if len(outputs) != 1:
            raise ValueError(f"Codec returned {len(outputs)} results for one step")
        return outputs[0]

    def execute(self, steps):
        for spec in steps:
            name = spec["name"]
            if name in self.data:
                raise ValueError(f"Duplicate step {name}")
            step = expand(spec, self.data)
            action = step["op"]
            if action == "send":
                result = self.send(step)
            elif action == "codec":
                result = self.run_codec(name, step["codec"], step["input"])
            elif action == "archive":
                subprocess.run(["bash", str(ROOT / "scripts/smoke/consumer-install.sh"), self.client],
                               cwd=ROOT, env={**os.environ, "DB_CONNECTION": "pgsql", "TMPDIR": str(self.stand.directory)},
                               stdout=self.stand.log, stderr=self.stand.log, timeout=360, check=True)
                result = {"installed": True}
            elif action == "wait":
                sleep(step["milliseconds"] / 1000)
                result = {"waitedMs": step["milliseconds"]}
            elif action == "compare":
                result = {"different": step["input"]["left"] != step["input"]["right"]}
            elif action == "correlate":
                result = correlate(self.stand.directory, step["input"])
            else:
                result = self.stand.command(action, step.get("input"))

            evidence_class = HOST_DOUBLE if action == "correlate" else DOUBLE if action in {"provider", "worker", "late_evidence"} else (
                "transport-contract" if action in {"codec", "archive", "wait", "compare"} or
                step.get("fault") in {"malformed", "mutate", "retry-after"} else "server-runtime")
            self.entries.append({"step": name, "action": action, "boundary": "double" if evidence_class in {DOUBLE, HOST_DOUBLE} else "loopback",
                                 "evidence_class": evidence_class, "observed": result})
            verify(result, step["expect"], name)
            self.data[name] = result


def run(args):
    examples = corpus()
    selected = REQUIRED if args.suite in {"all", "required"} else (args.suite,)
    if not set(selected) <= set(examples):
        raise ValueError(f"Unknown suite {args.suite}")
    report_path = Path(args.report).resolve()
    report_path.parent.mkdir(parents=True, exist_ok=True)
    log_path = report_path.with_suffix(".log")
    if report_path == log_path:
        raise ValueError("The report path must differ from the runtime log path")
    adapters = load(ROOT / "conformance/adapters.json")
    adapter = adapters[args.client]["cmd"]
    if args.client == "php":
        adapter = php_command(*adapter[1:])
    else:
        dart_package = ROOT / "conformance/adapters/dart"
        if subprocess.run(["dart", "pub", "get", "--offline"], cwd=dart_package, stdout=subprocess.DEVNULL).returncode:
            subprocess.run(["dart", "pub", "get"], cwd=dart_package, check=True)
    codec = Session(adapter, ROOT, args.client, Schemas(SPEC / "schema"), 20)
    results = []
    runtime = {}
    with log_path.open("w") as log:
        codec.start()
        try:
            for case_id in selected:
                case = examples[case_id]
                result = {"id": case_id, "sources": case["sources"], "profile": case["profile"],
                          "status": "fail", "transcript": []}
                results.append(result)
                try:
                    with tempfile.TemporaryDirectory(prefix="host-", dir=report_path.parent) as temporary:
                        stand = Stand(Path(temporary), log)
                        with stand.listen(case["profile"]) as (port, runtime):
                            transcript = Transcript(stand, codec, args.client, port)
                            result["transcript"] = transcript.entries
                            transcript.execute(case["steps"])
                    result["status"] = "pass"
                except (ValueError, RuntimeError, KeyError, IndexError, subprocess.SubprocessError) as error:
                    result["error"] = str(error)
                print(f"{case_id} {result['status']}" + (f": {result['error']}" if "error" in result else ""), flush=True)
        finally:
            codec_error = codec.stop()
    modes = {"server-runtime": "loopback", "transport-contract": "loopback", DOUBLE: "double", HOST_DOUBLE: "double"}
    evidence = [{"evidence_class": cls, "boundary": boundary, "steps": [
        f"{case['id']}/{entry['step']}" for case in results for entry in case["transcript"] if entry["evidence_class"] == cls
    ]} for cls, boundary in modes.items()]
    passed = codec_error is None and all(case["status"] == "pass" for case in results)
    report = {
        "protocol": "billium.http.transcript.v1", "client": args.client, "suite": args.suite,
        "required": list(REQUIRED), "runtime": runtime, "results": results, "evidence": evidence,
        "hashes": {
            "spec": digest((SPEC / "revision.lock.json").read_bytes()),
            "fixtures": tree_digest([FIXTURES]), "harness": tree_digest([HERE]),
            "packages": tree_digest([ROOT / "packages" / part for part in ("core", "contracts", "contracts-dart", "server", "testkit")]),
            "dependencies": digest((ROOT / "composer.lock").read_bytes()),
        },
        "passed": passed, "requiredSuitePassed": passed and selected == REQUIRED,
    }
    if codec_error:
        report["error"] = codec_error
    validator("report").validate(report)
    report_path.write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n")
    return 0 if report["passed"] else 1


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--client", choices=["php", "dart"], default="php")
    parser.add_argument("--suite", default="all", help="all, required, or one HTTP-XX fixture")
    parser.add_argument("--report", default=str(ROOT / "build/http/report.json"))
    args = parser.parse_args()
    return run(args)


if __name__ == "__main__":
    sys.exit(main())
