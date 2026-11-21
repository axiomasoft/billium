"""Stage only the package archives and minimal host in an isolated consumer."""

import importlib.util
import json
import os
from pathlib import Path
import shutil
import sys
import zipfile

sys.dont_write_bytecode = True


def stage(root: Path, work: Path) -> None:
    packman = (root.parent / "packman").resolve()
    # The toolchain may mount these two checkouts under a different UID.
    # Apply trust only to git subprocesses started by this staging process.
    config_count = int(os.environ.get("GIT_CONFIG_COUNT", "0"))
    for index, checkout in enumerate((root, packman), start=config_count):
        os.environ[f"GIT_CONFIG_KEY_{index}"] = "safe.directory"
        os.environ[f"GIT_CONFIG_VALUE_{index}"] = str(checkout)
    os.environ["GIT_CONFIG_COUNT"] = str(config_count + 2)

    helper = importlib.util.spec_from_file_location("php_consumer", root / "scripts/smoke/php-consumer.py")
    assert helper is not None and helper.loader is not None
    consumer = importlib.util.module_from_spec(helper)
    helper.loader.exec_module(consumer)

    archives = work / "archives"
    archives.mkdir()
    lock = json.loads((root / "composer.lock").read_text())
    packages = [p for p in lock["packages"] if not p["name"].startswith("axiomasoft/")]
    for slug in ("core", "contracts"):
        packages.append(consumer.package_archive(root, Path("packages") / slug, "0.1.0", archives))
    for slug in ("core", "schema", "plugins"):
        packages.append(consumer.package_archive(packman, Path("packages") / slug, "0.1.1", archives))

    # Include server worktree files so the smoke is also runnable before the coordinator commits.
    server = root / "packages/server"
    output = archives / "billium-server.zip"
    with zipfile.ZipFile(output, "w", zipfile.ZIP_DEFLATED) as archive:
        for group in ("composer.json", "README.md", "CHANGELOG.md", "src", "routes", "resources"):
            source = server / group
            candidates = [source] if source.is_file() else sorted(source.rglob("*"))
            for path in candidates:
                if path.is_symlink():
                    raise ValueError(f"Archive content cannot use a workspace symlink: {path}")
                if path.is_file():
                    archive.write(path, path.relative_to(server))
        package = json.loads(archive.read("composer.json"))
    package.update(version="0.1.0", dist={"type": "zip", "url": output.as_uri()})
    packages.append(package)

    host = work / "host"
    consumer.prepare(host, packages, {"axiomasoft/billium-server": "^0.1"})
    (host / "bootstrap/cache").mkdir(parents=True)
    for name in ("Host.php", "Database.php", "smoke.php"):
        shutil.copyfile(root / "tests/minimal-host/server" / name, host / name)


if __name__ == "__main__":
    stage(Path(sys.argv[1]).resolve(), Path(sys.argv[2]).resolve())
