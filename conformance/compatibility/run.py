#!/usr/bin/env python3
"""N/N−1 archive matrix for the PHP and Dart test consumers (D9, D59, D61, D63)."""

from __future__ import annotations

import argparse
import hashlib
import io
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import zipfile

ROOT = Path(__file__).resolve().parents[2]
HERE = Path(__file__).resolve().parent
SCOPE = {"installationRef": "inst-test", "environment": "test", "sellerRef": "seller-a"}
P2_COMMIT = "a2e52060fa75d57e5149755f20e12796a3a3f94d"
BASELINE_2026_10_COMMIT = "b7a165b045a053dde17373ffb1246e2490f808a8"
CONTRACT_PACKAGES = {
    "php-contracts": "packages/contracts",
    "dart-contracts": "packages/contracts-dart",
}
ENVELOPES = "resources/spec/envelopes.json"


def load(path: Path):
    return json.loads(path.read_text(encoding="utf-8"))


def digest(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def prior_generations(matrix: dict) -> list[dict]:
    generations = []
    if isinstance(matrix.get("prior"), dict):
        generations.append(matrix["prior"])
    for entry in matrix.get("priors", []):
        if isinstance(entry, dict):
            generations.append(entry)
    return generations


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
    if prior.get("commit") != P2_COMMIT:
        errors.append("prior snapshot must pin the P2 closure commit")
    if not isinstance(matrix.get("currentOnlySchemas"), list) or not matrix["currentOnlySchemas"]:
        errors.append("current-only schemas must be declared")
    for idx, entry in enumerate(matrix.get("priors", [])):
        if not isinstance(entry, dict):
            errors.append(f"priors[{idx}] must be an object")
            continue
        if not entry.get("id") or not entry.get("commit") or not entry.get("specRevision"):
            errors.append(f"priors[{idx}] must declare id, commit, and specRevision")
    return errors


def archive_errors(matrix: dict, base: Path) -> list[str]:
    errors = policy_errors(matrix)
    if errors:
        return errors
    for gen in prior_generations(matrix):
        gen_id = gen.get("id", "prior")
        archives = gen.get("archives", {})
        if not isinstance(archives, dict) or archives.keys() != CONTRACT_PACKAGES.keys():
            errors.append(f"{gen_id} archives must contain the PHP and Dart contracts packages")
            continue
        for name, entry in archives.items():
            if not isinstance(entry, dict):
                errors.append(f"{gen_id}/{name} archive entry is not an object")
                continue
            path = base / str(entry.get("path", ""))
            recorded = entry.get("sha256")
            if path.is_symlink():
                errors.append(f"{gen_id}/{name} archive is a symlink")
                continue
            if not path.is_file():
                errors.append(f"{gen_id}/{name} archive is missing")
                continue
            if not isinstance(recorded, str) or recorded == "unpinned":
                errors.append(f"{gen_id}/{name} archive hash is not pinned")
                continue
            actual = digest(path.read_bytes())
            if actual != recorded:
                errors.append(f"{gen_id}/{name} archive hash mismatch")
    return errors


def pin(matrix_path: Path) -> int:
    matrix = load(matrix_path)
    errors = policy_errors(matrix)
    if errors:
        print("\n".join(errors), flush=True)
        return 1
    payloads = {
        name: package_archive(source)
        for name, source in CONTRACT_PACKAGES.items()
    }
    archives = HERE / "archives"
    archives.mkdir(parents=True, exist_ok=True)
    matrix["prior"]["archives"] = {}
    for name, data in payloads.items():
        path = archives / f"p2-{name}.zip"
        path.write_bytes(data)
        matrix["prior"]["archives"][name] = {"path": f"archives/{path.name}", "sha256": digest(data)}
    matrix_path.write_text(json.dumps(matrix, indent=2) + "\n", encoding="utf-8")
    print("pinned prior snapshot", flush=True)
    return 0


def package_archive(source: str, commit: str = P2_COMMIT) -> bytes:
    data = subprocess.check_output(["git", "archive", "--format=zip", commit, source], cwd=ROOT)
    payload = io.BytesIO()
    with zipfile.ZipFile(io.BytesIO(data)) as tree, zipfile.ZipFile(payload, "w") as package:
        for entry in tree.infolist():
            if not entry.is_dir():
                path = entry.filename.removeprefix(source + "/")
                package.writestr(zipfile.ZipInfo(path), tree.read(entry), compress_type=zipfile.ZIP_DEFLATED)
    return payload.getvalue()


def pin_baseline(matrix_path: Path, gen_id: str, commit: str, spec_revision: str) -> int:
    matrix = load(matrix_path)
    archives = HERE / "archives"
    archives.mkdir(parents=True, exist_ok=True)
    entry_archives = {}
    for name, source in CONTRACT_PACKAGES.items():
        data = package_archive(source, commit)
        path = archives / f"{gen_id}-{name}.zip"
        path.write_bytes(data)
        entry_archives[name] = {"path": f"archives/{path.name}", "sha256": digest(data)}
    priors = [p for p in matrix.get("priors", []) if p.get("id") != gen_id]
    priors.append({
        "id": gen_id,
        "commit": commit,
        "publishedRelease": False,
        "denotes": f"Baseline {spec_revision} contracts artifact before 2026-12",
        "specRevision": spec_revision,
        "currentOnlySchemas": [],
        "archives": entry_archives,
    })
    matrix["priors"] = priors
    matrix_path.write_text(json.dumps(matrix, indent=2) + "\n", encoding="utf-8")
    print(f"pinned {gen_id} snapshot", flush=True)
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


def schema_errors(archive: Path, current_only: set[str]) -> list[str]:
    with zipfile.ZipFile(archive) as zip_file:
        prior = set(json.loads(zip_file.read(ENVELOPES)))
    current = set(load(ROOT / CONTRACT_PACKAGES["php-contracts"] / ENVELOPES))
    errors = []
    if retired := prior - current:
        errors.append(f"current codec lost P2 schemas: {sorted(retired)}")
    if undeclared := (current - prior) ^ current_only:
        errors.append(f"current-only schemas differ from the codec registries: {sorted(undeclared)}")
    return errors


def stage_prior_clients(root: Path) -> None:
    source = ROOT / "conformance/clients"
    shutil.copytree(source / "php", root / "php-client", ignore=shutil.ignore_patterns("tests"))
    dart = root / "billium_client_conformance"
    shutil.copytree(source / "dart", dart, ignore=shutil.ignore_patterns("test", ".dart_tool", "pubspec.lock"))
    pubspec = dart / "pubspec.yaml"
    current = pubspec.read_text(encoding="utf-8")
    pinned = current.replace("../../../packages/contracts-dart", "../billium_contracts")
    if pinned == current:
        raise RuntimeError("Dart test client no longer points at the expected contracts path")
    pubspec.write_text(pinned, encoding="utf-8")
    archive_bootstrap(root, ROOT)


def run_clients(cases: list[dict], php: list[str], dart: list[str], cwd_php: Path, cwd_dart: Path, env: dict | None) -> tuple[dict, dict]:
    document = {"op": "batch", "cases": cases}
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


def compare_corpus_generations(current: dict, prior: dict, name: str, expected: dict | None) -> list[str]:
    if expected is None:
        failure = compare_pair(current, prior, f"{name}/generation")
        return [failure] if failure else []

    return [
        failure
        for generation, result in (("current", current), ("prior", prior))
        if (failure := check(result, expected[generation], f"{name}/{generation}"))
    ]


def execute(matrix_path: Path) -> int:
    matrix = load(matrix_path)
    errors = archive_errors(matrix, HERE)
    if errors:
        print("\n".join(errors), flush=True)
        return 1
    generations = prior_generations(matrix)
    for gen in generations:
        gen_current_only = set(gen.get("currentOnlySchemas", matrix["currentOnlySchemas"]))
        if errors := schema_errors(HERE / gen["archives"]["php-contracts"]["path"], gen_current_only):
            print("\n".join(errors), flush=True)
            return 1
    if not (ROOT / "vendor/autoload.php").is_file():
        print("vendor/autoload.php is missing", flush=True)
        return 1
    vectors = client_vectors()
    if not vectors:
        print("client vectors are missing", flush=True)
        return 1
    cases = [materialize(doc) for doc in vectors]
    corpus = corpus_cases()
    if len(corpus) < 21:
        print("shared HTTP command corpus is incomplete", flush=True)
        return 1
    corpus_expectations = matrix.get("corpusExpectations", {})
    if unknown := set(corpus_expectations) - {case["id"] for case in corpus}:
        print(f"unknown HTTP corpus cases in matrix: {sorted(unknown)}", flush=True)
        return 1
    batch = cases + corpus
    current_php = php_command(str(ROOT / "conformance/clients/php/bin/client.php"))
    ensure_dart(ROOT / "conformance/clients/dart")
    current_dart = ["dart", "run", "bin/client.dart"]
    problems = []
    try:
        current = run_clients(batch, current_php, current_dart, ROOT, ROOT / "conformance/clients/dart", None)
    except (RuntimeError, subprocess.CalledProcessError, json.JSONDecodeError, KeyError) as error:
        print(str(error), flush=True)
        return 1

    by_id = {doc["id"]: doc for doc in vectors}
    transport = 0
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
        transport += 1

    for case in corpus:
        name = case["id"]
        for label, result in (("current-php", current[0][name]), ("current-dart", current[1][name])):
            problem = audit(result, f"{name}/{label}")
            if problem:
                problems.append(problem)
        failure = compare_pair(current[0][name], current[1][name], name)
        if failure:
            problems.append(failure)
        transport += 1

    total_archive_steps = 0
    for gen in generations:
        gen_id = gen["id"]
        prior = gen["archives"]
        gen_current_only = set(gen.get("currentOnlySchemas", matrix["currentOnlySchemas"]))
        gen_corpus_expectations = gen.get("corpusExpectations", corpus_expectations if gen is matrix.get("prior") else {})
        archive_steps = 0
        with tempfile.TemporaryDirectory(prefix=f"billium-{gen_id}-") as temporary:
            root = Path(temporary)
            extract(HERE / prior["php-contracts"]["path"], root / "php-contracts")
            extract(HERE / prior["dart-contracts"]["path"], root / "billium_contracts")
            env = {**os.environ, "BILLIUM_ARCHIVE_ROOT": str(root)}
            env.pop("BILLIUM_AUTOLOAD", None)
            try:
                stage_prior_clients(root)
                ensure_dart(root / "billium_client_conformance")
                prior_runs = run_clients(
                    batch,
                    php_command(str(root / "php-client/bin/client.php")),
                    ["dart", "run", "bin/client.dart"],
                    root,
                    root / "billium_client_conformance",
                    env,
                )
            except (RuntimeError, subprocess.CalledProcessError, json.JSONDecodeError, KeyError) as error:
                print(str(error), flush=True)
                return 1
            for case in cases:
                name = case["id"]
                doc = by_id[name]
                prior_expect = doc.get("priorExpect")
                shared = doc.get("shared", True)
                is_p2 = gen is matrix.get("prior")
                for label, result in ((f"{gen_id}-php", prior_runs[0][name]), (f"{gen_id}-dart", prior_runs[1][name])):
                    problem = audit(result, f"{name}/{label}")
                    if problem:
                        problems.append(problem)
                    if is_p2 and prior_expect:
                        failure = check(result, prior_expect, f"{name}/{label}")
                        if failure:
                            problems.append(failure)
                    elif shared or not is_p2:
                        failure = check(result, doc.get("expect", {}), f"{name}/{label}")
                        if failure:
                            problems.append(failure)
                failure = compare_pair(prior_runs[0][name], prior_runs[1][name], f"{name}/{gen_id}")
                if failure:
                    problems.append(failure)
                if (shared and not prior_expect) or not is_p2:
                    failure = compare_pair(current[0][name], prior_runs[0][name], f"{name}/{gen_id}-generation")
                    if failure:
                        problems.append(failure)
                archive_steps += 1
            for case in corpus:
                name = case["id"]
                schema = schema_of(case["jsonText"])
                for label, result in ((f"{gen_id}-php", prior_runs[0][name]), (f"{gen_id}-dart", prior_runs[1][name])):
                    problem = audit(result, f"{name}/{label}")
                    if problem:
                        problems.append(problem)
                if schema in gen_current_only:
                    for label, result in ((f"{gen_id}-php", prior_runs[0][name]), (f"{gen_id}-dart", prior_runs[1][name])):
                        code = (result.get("error") or {}).get("code")
                        if code != "billium.schema.unsupported" or result.get("attempts"):
                            problems.append(f"{name}/{label} delivered a current-only schema: {code}")
                    archive_steps += 1
                else:
                    failure = compare_pair(prior_runs[0][name], prior_runs[1][name], f"{name}/{gen_id}")
                    if failure:
                        problems.append(failure)
                    expected = gen_corpus_expectations.get(name)
                    problems.extend(compare_corpus_generations(current[0][name], prior_runs[0][name], name, expected))
                    if expected is not None:
                        archive_steps += 1
        print(f"compatibility-archive[{gen_id}] steps={archive_steps}", flush=True)
        total_archive_steps += archive_steps
    print(f"transport-contract steps={transport}", flush=True)
    print(f"compatibility-archive steps={total_archive_steps}", flush=True)
    if problems:
        print("\n".join(problems), flush=True)
        return 1
    print("passed", flush=True)
    return 0


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--matrix", required=True, type=Path)
    parser.add_argument("--pin", action="store_true")
    parser.add_argument("--pin-baseline", nargs=3, metavar=("ID", "COMMIT", "SPEC_REVISION"))
    args = parser.parse_args(argv)
    if args.pin:
        return pin(args.matrix)
    if args.pin_baseline:
        return pin_baseline(args.matrix, args.pin_baseline[0], args.pin_baseline[1], args.pin_baseline[2])
    return execute(args.matrix)


if __name__ == "__main__":
    raise SystemExit(main())
