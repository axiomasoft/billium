#!/usr/bin/env bash
# Install the PHP and Dart test consumers from package archives.
# The install has no symlink and no path into this monorepo.
# Run inside the toolchain image:
#   docker compose run --rm toolchain bash scripts/smoke/server-consumers.sh
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
export BILLIUM_MONOREPO_ROOT="$ROOT"
exec python3 - "$ROOT" <<'PY'
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import zipfile

ROOT = Path(sys.argv[1]).resolve()
PACKMAN = (ROOT / "../packman").resolve()
PILOT = '{"schema":"payment.execute.v1","commandKey":"7e0c4a52-91d3-4b6f-8a25-c3d1e9f04b87","operationRef":"op-8f3a21","operationKind":"charge","source":{"context":"commerz","orderRef":"ord-10234","intentRef":"int-10234-1"},"installationRef":"inst-eu-1","environment":"test","executionProfile":"remote-billium","routingEpoch":1,"sellerRef":"seller-acme","merchantAccountRef":"ma-main","money":{"amount":"1490.00","currency":"RUB"},"bindingRevision":"bind-7c91d0e2","returnContextRef":"rc-10234","requestedAt":"2026-10-17T12:00:00Z"}'
DART_PUBSPEC = """name: billium_client_conformance
description: Test-only Billium HTTP consumer. Not a production SDK and not a money writer.
version: 0.1.0
publish_to: none
environment:
  sdk: ^3.13.0
dependencies:
  billium_contracts:
    path: ../billium_contracts
"""


def fail(message):
    raise SystemExit(message)


def members(directory, skip):
    found = []
    for path in sorted(directory.rglob("*")):
        if not path.is_file() or path.is_symlink():
            continue
        relative = path.relative_to(directory).as_posix()
        if any(part in skip for part in Path(relative).parts):
            continue
        found.append((relative, path.read_bytes()))
    return found


def write_zip(path, items):
    path.parent.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(path, "w") as archive:
        for name, data in sorted(items):
            info = zipfile.ZipInfo(name)
            info.date_time = (2026, 11, 21, 0, 0, 0)
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = 0o644 << 16
            archive.writestr(info, data)


def package_from_zip(filename):
    with zipfile.ZipFile(filename) as archive:
        package = json.loads(archive.read("composer.json"))
    package.update(version=package.get("version", "0.1.0"), dist={"type": "zip", "url": filename.resolve().as_uri()})
    return package


def git_archive(repo, relative, output):
    subprocess.run(
        ["git", "-c", f"safe.directory={repo}", "-C", repo, "archive", "--format=zip", "--output", output, f"HEAD:{relative}"],
        check=True,
    )


def seed_cache(work):
    configured = subprocess.check_output(["composer", "config", "--global", "--absolute", "cache-files-dir"], cwd=work, text=True)
    shared = Path(configured.strip())
    cache = work / "composer-cache"
    cache.mkdir()
    for archive in shared.glob("*/*/*.zip"):
        relative = archive.relative_to(shared)
        if relative.parts[0] == "axiomasoft":
            continue
        copy = cache / "files" / relative
        copy.parent.mkdir(parents=True, exist_ok=True)
        try:
            shutil.copyfile(archive, copy)
        except PermissionError:
            continue
    return cache


def assert_closed(consumer):
    root = str(ROOT)
    for path in consumer.rglob("*"):
        if path.is_symlink():
            fail(f"symlink in consumer install: {path}")
        if path.is_file():
            real = str(path.resolve())
            if real == root or real.startswith(root + os.sep):
                fail(f"consumer file resolves inside the monorepo: {path}")
    vendor = consumer / "vendor"
    for forbidden in ("laravel", "illuminate", "axiomasoft/billium-server", "axiomasoft/billium-core", "axiomasoft/billium-sdk", "axiomasoft/billium-testkit"):
        if (vendor / forbidden).exists():
            fail(f"consumer installed {forbidden}")


def composer_install(consumer, packages):
    consumer.mkdir(parents=True)
    (consumer / "composer.json").write_text(json.dumps({
        "name": "consumer/billium-client",
        "require": {"axiomasoft/billium-client-conformance": "^0.1"},
        "repositories": [{"type": "package", "package": packages}, {"packagist.org": False}],
    }))
    env = {**os.environ, "COMPOSER_DISABLE_NETWORK": "1"}
    subprocess.run(
        ["composer", "update", "--no-dev", "--prefer-dist", "--no-interaction", "--no-progress", "--no-audit"],
        cwd=consumer, env=env, check=True,
    )
    assert_closed(consumer)
    return consumer / "vendor/axiomasoft/billium-client-conformance/bin/client.php"


def run_php(binary, consumer, document):
    env = {
        **os.environ,
        "BILLIUM_AUTOLOAD": str(consumer / "vendor/autoload.php"),
        "BILLIUM_CONSUMER_ROOT": str(consumer),
        "BILLIUM_MONOREPO_ROOT": str(ROOT),
    }
    env.pop("BILLIUM_ARCHIVE_ROOT", None)
    result = subprocess.run([os.environ.get("PHP84_BIN", "php"), str(binary)], input=json.dumps(document).encode(), stdout=subprocess.PIPE, stderr=subprocess.PIPE, cwd=consumer, env=env, check=False)
    if result.returncode != 0:
        fail(result.stderr.decode()[-2000:])
    return json.loads(result.stdout)


def install_dart(client, contracts, work):
    if client.is_symlink() or contracts.is_symlink():
        fail("dart archive extract is a symlink")
    pubspec = (client / "pubspec.yaml").read_text(encoding="utf-8")
    if str(ROOT) in pubspec or "packages/contracts-dart" in pubspec:
        fail("dart pubspec points at the monorepo")
    target = (client / ".." / "billium_contracts").resolve()
    if target != contracts.resolve() or str(ROOT) in str(target):
        fail("dart contracts path escapes the consumer")
    subprocess.run(["dart", "pub", "get"], cwd=client, check=True)
    config = (client / ".dart_tool/package_config.json").read_text(encoding="utf-8")
    if str(ROOT) in config:
        fail("dart package config points at the monorepo")
    for path in work.rglob("*"):
        if path.is_symlink():
            fail(f"symlink in dart consumer: {path}")
    env = {**os.environ}
    env.pop("BILLIUM_ARCHIVE_ROOT", None)
    result = subprocess.run(["dart", "run", "bin/client.dart"], input=json.dumps({"op": "prepare", "id": "pilot", "jsonText": PILOT}).encode(), stdout=subprocess.PIPE, stderr=subprocess.PIPE, cwd=client, env=env, check=False)
    if result.returncode != 0:
        fail(result.stderr.decode()[-2000:])
    return json.loads(result.stdout)


def current_archives(work):
    contracts = members(ROOT / "packages/contracts", {"tests"})
    composer = json.loads(next(data for name, data in contracts if name == "composer.json"))
    composer["version"] = "0.1.0"
    contracts = [(name, data) for name, data in contracts if name != "composer.json"]
    contracts.append(("composer.json", (json.dumps(composer, indent=4) + "\n").encode()))
    write_zip(work / "current-contracts.zip", contracts)
    write_zip(work / "current-client.zip", members(ROOT / "conformance/clients/php", {"tests"}))
    git_archive(PACKMAN, "packages/core", work / "packman.zip")


def copy_tree(source, destination, skip, pubspec=None):
    for name, data in members(source, skip):
        if pubspec is not None and name == "pubspec.yaml":
            continue
        target = destination / name
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(data)
    if pubspec is not None:
        (destination / "pubspec.yaml").write_text(pubspec, encoding="utf-8")


def prove(label, observed, golden):
    prepared = observed.get("prepared") or {}
    if prepared.get("bytes") != golden or prepared.get("commandKey") != "7e0c4a52-91d3-4b6f-8a25-c3d1e9f04b87":
        fail(f"{label} did not reproduce the P2 pilot wire")
    if observed.get("attempts") or observed.get("cancelInvoked") or observed.get("newKey"):
        fail(f"{label} sent or mutated the prepared command")
    print(f"smoke {label}: ok")


def main():
    golden = (ROOT / "fixtures/golden/canon/digest/pilot-execute-canonical.txt").read_text(encoding="utf-8").strip("\n")
    work = Path(tempfile.mkdtemp(prefix="billium-consumers-"))
    os.environ["COMPOSER_CACHE_DIR"] = str(seed_cache(work))
    os.environ["PUB_CACHE"] = str(work / "pub-cache")
    try:
        current_archives(work)
        lock = json.loads((ROOT / "composer.lock").read_text(encoding="utf-8"))
        packages = [package for package in lock["packages"] if not str(package["name"]).startswith("axiomasoft/")]
        packages.extend([
            package_from_zip(work / "current-contracts.zip"),
            package_from_zip(work / "current-client.zip"),
            package_from_zip(work / "packman.zip"),
        ])
        # The packman archive is a git tree whose composer name is axiomasoft/packman.
        binary = composer_install(work / "current-php", packages)
        prove("current-php", run_php(binary, work / "current-php", {"op": "prepare", "id": "pilot", "jsonText": PILOT}), golden)
        cancelled = run_php(binary, work / "current-php", {
            "op": "send", "id": "deadline", "jsonText": PILOT,
            "transport": [{"transport": "cancelled-after-send"}],
        })
        if cancelled.get("outcome") != "unresolved" or cancelled.get("cancelInvoked") or cancelled.get("newKey"):
            fail("current-php cancellation minted a key or called cancel")
        if (cancelled.get("recovery") or {}).get("commandKey") != "7e0c4a52-91d3-4b6f-8a25-c3d1e9f04b87":
            fail("current-php cancellation lost the command key")

        dart_contracts = work / "current-dart/billium_contracts"
        dart_client = work / "current-dart/billium_client_conformance"
        copy_tree(ROOT / "packages/contracts-dart", dart_contracts, {"test", ".dart_tool"})
        copy_tree(ROOT / "conformance/clients/dart", dart_client, {"test", ".dart_tool"}, DART_PUBSPEC)
        prove("current-dart", install_dart(dart_client, dart_contracts, work / "current-dart"), golden)

        prior = ROOT / "conformance/compatibility/archives"
        for name in ("p2-php-contracts.zip", "p2-dart-contracts.zip"):
            if not (prior / name).is_file() or (prior / name).is_symlink():
                fail(f"pinned archive missing: {name}")
        prior_packages = [package for package in lock["packages"] if not str(package["name"]).startswith("axiomasoft/")]
        prior_packages.extend([
            package_from_zip(prior / "p2-php-contracts.zip"),
            package_from_zip(work / "current-client.zip"),
            package_from_zip(work / "packman.zip"),
        ])
        prior_binary = composer_install(work / "prior-php", prior_packages)
        prove("prior-php", run_php(prior_binary, work / "prior-php", {"op": "prepare", "id": "pilot", "jsonText": PILOT}), golden)
        prior_contracts = work / "prior-dart/billium_contracts"
        prior_client = work / "prior-dart/billium_client_conformance"
        with zipfile.ZipFile(prior / "p2-dart-contracts.zip") as archive:
            archive.extractall(prior_contracts)
        copy_tree(ROOT / "conformance/clients/dart", prior_client, {"test", ".dart_tool"}, DART_PUBSPEC)
        prove("prior-dart", install_dart(prior_client, prior_contracts, work / "prior-dart"), golden)
    finally:
        shutil.rmtree(work, ignore_errors=True)


if __name__ == "__main__":
    main()
PY
