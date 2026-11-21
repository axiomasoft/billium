#!/usr/bin/env python3
"""N/N−1 archive matrix for the PHP and Dart test consumers (D9, D59, D61, D63)."""

from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path
import subprocess
import tempfile
import zipfile

ROOT = Path(__file__).resolve().parents[2]
HERE = Path(__file__).resolve().parent
SCOPE = {"installationRef": "inst-test", "environment": "test", "sellerRef": "seller-a"}
ZIP_TIME = (2026, 11, 21, 0, 0, 0)
DART_PUBSPEC = """name: billium_client_conformance
description: Test-only Billium HTTP consumer. Not a production SDK and not a money writer (D9, D61).
version: 0.1.0
publish_to: none
environment:
  sdk: ^3.13.0
dependencies:
  billium_contracts:
    path: ../billium_contracts
"""


def load(path: Path):
    return json.loads(path.read_text(encoding="utf-8"))


def digest(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def policy_errors(matrix: dict) -> list[str]:
    errors = []
    if matrix.get("forwardCompatibility") is not False:
        errors.append("forward compatibility is not claimed")
    prior = matrix.get("prior")
    if not isinstance(prior, dict):
        return errors + ["prior generation is missing"]
    if prior.get("publishedRelease") is not False:
        errors.append("prior snapshot must not be a published release")
    denotes = prior.get("denotes")
    if not isinstance(denotes, str) or "P2" not in denotes:
        errors.append("prior snapshot must denote the P2 artifact")
    if not isinstance(matrix.get("currentOnlySchemas"), list) or not matrix["currentOnlySchemas"]:
        errors.append("current-only schemas must be declared")
    return errors


def archive_errors(matrix: dict, base: Path) -> list[str]:
    errors = policy_errors(matrix)
    archives = matrix.get("prior", {}).get("archives", {})
    if not isinstance(archives, dict) or not archives:
        return errors + ["prior archives are missing"]
    for name, entry in archives.items():
        if not isinstance(entry, dict):
            errors.append(f"{name} archive entry is not an object")
            continue
        path = base / str(entry.get("path", ""))
        recorded = entry.get("sha256")
        if path.is_symlink():
            errors.append(f"{name} archive is a symlink")
            continue
        if not path.is_file():
            errors.append(f"{name} archive is missing")
            continue
        if not isinstance(recorded, str) or recorded == "unpinned":
            errors.append(f"{name} archive hash is not pinned")
            continue
        actual = digest(path.read_bytes())
        if actual != recorded:
            errors.append(f"{name} archive hash mismatch")
    return errors


def write_zip(path: Path, members: list[tuple[str, bytes]]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(path, "w") as archive:
        for name, data in sorted(members):
            info = zipfile.ZipInfo(name)
            info.date_time = ZIP_TIME
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = 0o644 << 16
            archive.writestr(info, data)


def tree_members(directory: Path, skip: set[str]) -> list[tuple[str, bytes]]:
    members = []
    for path in sorted(directory.rglob("*")):
        if not path.is_file() or path.is_symlink():
            continue
        relative = path.relative_to(directory).as_posix()
        if any(part in skip for part in Path(relative).parts):
            continue
        members.append((relative, path.read_bytes()))
    return members


def pin(matrix_path: Path) -> int:
    matrix = load(matrix_path)
    errors = policy_errors(matrix)
    if errors:
        print("\n".join(errors), flush=True)
        return 1
    archives = HERE / "archives"
    contracts = tree_members(ROOT / "packages/contracts", {"tests"})
    composer = json.loads(next(data for name, data in contracts if name == "composer.json"))
    composer["version"] = "0.1.0"
    contracts = [(name, data) for name, data in contracts if name != "composer.json"]
    contracts.append(("composer.json", (json.dumps(composer, indent=4) + "\n").encode()))
    dart_contracts = tree_members(ROOT / "packages/contracts-dart", {"test", ".dart_tool"})
    php_client = tree_members(ROOT / "conformance/clients/php", {"tests"})
    dart_client = [
        (name, data)
        for name, data in tree_members(ROOT / "conformance/clients/dart", {"test", ".dart_tool"})
        if name != "pubspec.yaml"
    ]
    dart_client.append(("pubspec.yaml", DART_PUBSPEC.encode()))
    files = {
        "php-contracts": archives / "p2-php-contracts.zip",
        "php-client": archives / "p2-php-client.zip",
        "dart-contracts": archives / "p2-dart-contracts.zip",
        "dart-client": archives / "p2-dart-client.zip",
    }
    payloads = {
        "php-contracts": contracts,
        "php-client": php_client,
        "dart-contracts": dart_contracts,
        "dart-client": dart_client,
    }
    for name, path in files.items():
        write_zip(path, payloads[name])
        matrix["prior"]["archives"][name]["sha256"] = digest(path.read_bytes())
        matrix["prior"]["archives"][name]["path"] = f"archives/{path.name}"
    matrix_path.write_text(json.dumps(matrix, indent=2) + "\n", encoding="utf-8")
    print("pinned prior snapshot", flush=True)
    return 0


def pointer(value, path: str):
    for part in path.split("."):
        value = value[int(part)] if part.isdigit() else value[part]
    return value


def field(value, path: str):
    for name in path.split(".") if path else []:
        if name == "#":
            value = len(value)
        else:
            value = value[int(name)] if isinstance(value, list) else value[name]
    return value


def check(actual, expected: dict, label: str) -> str | None:
    for path, value in expected.items():
        try:
            found = field(actual, path)
        except (KeyError, IndexError, TypeError):
            return f"{label}.{path}: missing, expected {value!r}"
        if found != value or type(found) is not type(value):
            return f"{label}.{path}: expected {value!r}, got {found!r}"
    return None


def materialize(doc: dict) -> dict:
    case = {
        key: value
        for key, value in doc.items()
        if key not in {"expect", "priorExpect", "sources", "title", "protocol", "golden", "shared", "fixture", "fixturePointer"}
    }
    if "fixture" in doc:
        case["jsonText"] = pointer(load(ROOT / doc["fixture"]), doc["fixturePointer"])
    return case


def client_vectors() -> list[dict]:
    found = []
    root = ROOT / "fixtures/integration/http/vectors"
    for path in sorted(root.rglob("*.json")):
        if path.name.startswith("HTTP-"):
            continue
        doc = load(path)
        if isinstance(doc, dict) and doc.get("protocol") == "billium.client.prepared.v1":
            found.append(doc)
    return found


def corpus_cases() -> list[dict]:
    cases = []
    for path in sorted((ROOT / "fixtures/integration/http/vectors").rglob("HTTP-*.json")):
        doc = load(path)
        for step in doc["steps"]:
            if step.get("op") != "send" or "body" not in step:
                continue
            body = step["body"]
            if isinstance(body, dict):
                merged = {**SCOPE, **body} if step.get("scoped") else body
                text = json.dumps(merged, separators=(",", ":"), ensure_ascii=False)
            else:
                text = json.dumps(body, separators=(",", ":"), ensure_ascii=False)
            case = {"id": f"{doc['id']}/{step['name']}", "op": "prepare", "jsonText": text}
            if "seal" in step:
                case["seal"] = step["seal"]
            cases.append(case)
    return cases


def schema_of(text: str) -> str | None:
    try:
        value = json.loads(text)
    except json.JSONDecodeError:
        return None
    if isinstance(value, dict) and isinstance(value.get("schema"), str):
        return value["schema"]
    return None


def audit(result: dict, label: str) -> str | None:
    prepared = result.get("prepared") or {}
    for attempt in result.get("attempts", []):
        if "/cancel" in attempt["path"] or "payment.cancel.v1" in attempt["body"]:
            return f"{label} invoked cancel"
        if prepared and attempt["body"] != prepared.get("bytes"):
            return f"{label} changed prepared bytes"
        if prepared.get("commandKey") and attempt.get("commandKey") != prepared["commandKey"]:
            return f"{label} minted a command key"
    if result.get("cancelInvoked") or result.get("newKey") or result.get("rebuilt"):
        return f"{label} reported a retry mutation"
    return None


def signature(result: dict) -> dict:
    error = result.get("error") or {}
    prepared = result.get("prepared") or {}
    return {
        "outcome": result.get("outcome"),
        "code": error.get("code"),
        "receipt": error.get("receipt"),
        "bytes": prepared.get("bytes"),
        "digest": prepared.get("digest"),
        "commandKey": prepared.get("commandKey"),
        "attempts": len(result.get("attempts", [])),
        "authorizations": result.get("authorizations"),
        "waitedMs": result.get("waitedMs"),
        "cancelInvoked": result.get("cancelInvoked"),
        "newKey": result.get("newKey"),
        "rebuilt": result.get("rebuilt"),
        "totals": result.get("totals"),
        "cursor": result.get("cursor"),
        "persisted": result.get("persisted"),
        "eventCount": result.get("eventCount"),
        "preservedUnknown": result.get("preservedUnknown"),
        "fullDigest": result.get("fullDigest"),
        "whitelistDigest": result.get("whitelistDigest"),
        "digestCoversUnknown": result.get("digestCoversUnknown"),
        "routingEpoch": result.get("routingEpoch"),
        "recovery": result.get("recovery"),
        "isolated": result.get("isolated"),
        "authorities": result.get("authorities"),
    }


def php_command(*args: str) -> list[str]:
    return [os.environ.get("PHP84_BIN", "php"), *args]


def invoke(command: list[str], document: dict, cwd: Path, env: dict | None = None) -> dict:
    result = subprocess.run(
        command,
        input=json.dumps(document).encode(),
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        cwd=cwd,
        env=env,
        check=False,
    )
    if result.returncode != 0:
        raise RuntimeError(result.stderr.decode()[-4000:] or f"{command[0]} exited {result.returncode}")
    return json.loads(result.stdout)


def ensure_dart(package: Path) -> None:
    if subprocess.run(["dart", "pub", "get", "--offline"], cwd=package, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode:
        subprocess.run(["dart", "pub", "get"], cwd=package, check=True)


def extract(archive: Path, directory: Path) -> None:
    directory.mkdir(parents=True)
    with zipfile.ZipFile(archive) as zip_file:
        zip_file.extractall(directory)


def archive_bootstrap(root: Path, monorepo: Path) -> None:
    (root / "bootstrap.php").write_text(
        """<?php
declare(strict_types=1);
$root = %s;
require %s;
spl_autoload_register(static function (string $class) use ($root): void {
    $map = [
        'Billium\\\\ClientConformance\\\\' => $root.'/php-client/src/',
        'Billium\\\\Contracts\\\\' => $root.'/php-contracts/src/',
    ];
    foreach ($map as $prefix => $dir) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }
        $path = $dir.str_replace('\\\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($path)) {
            require $path;
        }
        return;
    }
}, true, true);
$file = (new ReflectionClass(Billium\\Contracts\\Contracts\\Codec::class))->getFileName();
if (!is_string($file) || !str_starts_with((string) realpath($file), $root.'/')) {
    throw new RuntimeException('codec resolved outside the pinned archive');
}
"""
        % (json.dumps(str(root)), json.dumps(str(monorepo / "vendor/autoload.php"))),
        encoding="utf-8",
    )


def supported_schemas(archive: Path, current_only: set[str]) -> list[str]:
    with zipfile.ZipFile(archive) as zip_file:
        envelopes = json.loads(zip_file.read("resources/spec/envelopes.json"))
    return sorted(set(envelopes) - current_only)


def run_clients(cases: list[dict], supported: list[str] | None, php: list[str], dart: list[str], cwd_php: Path, cwd_dart: Path, env: dict | None) -> tuple[dict, dict]:
    document = {"op": "batch", "cases": cases}
    if supported is not None:
        document["supportedSchemas"] = supported
    php_out = invoke(php, document, cwd_php, env)
    dart_out = invoke(dart, document, cwd_dart, env)
    return (
        {item["id"]: item for item in php_out["results"]},
        {item["id"]: item for item in dart_out["results"]},
    )


def compare_pair(left: dict, right: dict, label: str) -> str | None:
    if signature(left) != signature(right):
        return f"{label} php/dart mismatch: {signature(left)} != {signature(right)}"
    return None


def execute(matrix_path: Path) -> int:
    matrix = load(matrix_path)
    errors = archive_errors(matrix, HERE)
    if errors:
        print("\n".join(errors), flush=True)
        return 1
    if not (ROOT / "vendor/autoload.php").is_file():
        print("vendor/autoload.php is missing", flush=True)
        return 1
    current_only = set(matrix["currentOnlySchemas"])
    vectors = client_vectors()
    if not vectors:
        print("client vectors are missing", flush=True)
        return 1
    cases = [materialize(doc) for doc in vectors]
    corpus = corpus_cases()
    if len(corpus) < 21:
        print("shared HTTP command corpus is incomplete", flush=True)
        return 1
    batch = cases + corpus
    current_php = php_command(str(ROOT / "conformance/clients/php/bin/client.php"))
    ensure_dart(ROOT / "conformance/clients/dart")
    current_dart = ["dart", "run", "bin/client.dart"]
    problems = []
    with tempfile.TemporaryDirectory(prefix="billium-prior-") as temporary:
        root = Path(temporary)
        prior = matrix["prior"]["archives"]
        extract(HERE / prior["php-contracts"]["path"], root / "php-contracts")
        extract(HERE / prior["php-client"]["path"], root / "php-client")
        extract(HERE / prior["dart-contracts"]["path"], root / "billium_contracts")
        extract(HERE / prior["dart-client"]["path"], root / "billium_client_conformance")
        archive_bootstrap(root, ROOT)
        ensure_dart(root / "billium_client_conformance")
        pin_set = supported_schemas(HERE / prior["php-contracts"]["path"], current_only)
        if current_only & set(pin_set):
            problems.append("prior pin still negotiates a current-only schema")
        env = {**os.environ, "BILLIUM_ARCHIVE_ROOT": str(root)}
        try:
            current = run_clients(batch, None, current_php, current_dart, ROOT, ROOT / "conformance/clients/dart", None)
            prior_runs = run_clients(
                batch,
                pin_set,
                php_command(str(root / "php-client/bin/client.php")),
                ["dart", "run", "bin/client.dart"],
                root,
                root / "billium_client_conformance",
                env,
            )
        except (RuntimeError, subprocess.CalledProcessError, json.JSONDecodeError, KeyError) as error:
            print(str(error), flush=True)
            return 1
        by_id = {doc["id"]: doc for doc in vectors}
        transport = 0
        archive_steps = 0
        for case in cases:
            name = case["id"]
            doc = by_id[name]
            for label, result in (("current-php", current[0][name]), ("current-dart", current[1][name])):
                problem = audit(result, f"{name}/{label}")
                if problem:
                    problems.append(problem)
                expected = doc.get("expect")
                if expected:
                    failure = check(result, expected, f"{name}/{label}")
                    if failure:
                        problems.append(failure)
                golden = doc.get("golden")
                if golden:
                    wanted = (ROOT / golden).read_text(encoding="utf-8").strip("\n")
                    if (result.get("prepared") or {}).get("bytes") != wanted:
                        problems.append(f"{name}/{label} bytes differ from the P2 golden")
            failure = compare_pair(current[0][name], current[1][name], name)
            if failure:
                problems.append(failure)
            prior_expect = doc.get("priorExpect")
            shared = doc.get("shared", True)
            for label, result in (("prior-php", prior_runs[0][name]), ("prior-dart", prior_runs[1][name])):
                problem = audit(result, f"{name}/{label}")
                if problem:
                    problems.append(problem)
                if prior_expect:
                    failure = check(result, prior_expect, f"{name}/{label}")
                    if failure:
                        problems.append(failure)
                elif shared:
                    failure = check(result, doc.get("expect", {}), f"{name}/{label}")
                    if failure:
                        problems.append(failure)
            failure = compare_pair(prior_runs[0][name], prior_runs[1][name], f"{name}/prior")
            if failure:
                problems.append(failure)
            if shared and not prior_expect:
                failure = compare_pair(current[0][name], prior_runs[0][name], f"{name}/generation")
                if failure:
                    problems.append(failure)
            transport += 1
            archive_steps += 1
        for case in corpus:
            name = case["id"]
            schema = schema_of(case["jsonText"])
            for label, result in (
                ("current-php", current[0][name]),
                ("current-dart", current[1][name]),
                ("prior-php", prior_runs[0][name]),
                ("prior-dart", prior_runs[1][name]),
            ):
                problem = audit(result, f"{name}/{label}")
                if problem:
                    problems.append(problem)
            failure = compare_pair(current[0][name], current[1][name], name)
            if failure:
                problems.append(failure)
            if schema in current_only:
                for label, result in (("prior-php", prior_runs[0][name]), ("prior-dart", prior_runs[1][name])):
                    code = (result.get("error") or {}).get("code")
                    if code != "billium.schema.unsupported" or result.get("attempts"):
                        problems.append(f"{name}/{label} delivered a current-only schema: {code}")
                archive_steps += 1
            else:
                failure = compare_pair(prior_runs[0][name], prior_runs[1][name], f"{name}/prior")
                if failure:
                    problems.append(failure)
                failure = compare_pair(current[0][name], prior_runs[0][name], f"{name}/generation")
                if failure:
                    problems.append(failure)
            transport += 1
    print(f"transport-contract steps={transport}", flush=True)
    print(f"compatibility-archive steps={archive_steps}", flush=True)
    if problems:
        print("\n".join(problems), flush=True)
        return 1
    print("passed", flush=True)
    return 0


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--matrix", required=True, type=Path)
    parser.add_argument("--pin", action="store_true")
    args = parser.parse_args(argv)
    if args.pin:
        return pin(args.matrix)
    return execute(args.matrix)


if __name__ == "__main__":
    raise SystemExit(main())
