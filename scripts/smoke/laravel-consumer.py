"""Install the actual Billium and Packman package archives into an empty Laravel project."""

import json
import os
from pathlib import Path
import subprocess
import sys
import zipfile


def package_archive(repo, path, version, output):
    filename = output / f"{repo.name}-{path.name}.zip"
    subprocess.run(
        ["git", "-C", repo, "archive", "--format=zip", "--output", filename,
         f"HEAD:{path.as_posix()}"],
        check=True,
    )
    with zipfile.ZipFile(filename) as archive:
        package = json.loads(archive.read("composer.json"))
    package.update(version=version, dist={"type": "zip", "url": filename.as_uri()})
    return package


def main():
    root = Path(__file__).resolve().parents[2]
    packman = (root / "../packman").resolve()
    consumer = Path(sys.argv[1])
    archives = consumer / "archives"
    archives.mkdir(parents=True)
    (consumer / "bootstrap/cache").mkdir(parents=True)
    (consumer / "storage/framework/views").mkdir(parents=True)

    lock = json.loads((root / "composer.lock").read_text())
    packages = [p for p in lock["packages"] if not p["name"].startswith("axiomasoft/")]
    for slug in ("core", "contracts", "testkit"):
        packages.append(package_archive(root, Path("packages") / slug, "0.1.0", archives))
    for slug in ("core", "schema", "plugins"):
        packages.append(package_archive(packman, Path("packages") / slug, "0.1.1", archives))

    manifest = {
        "name": "consumer/billium-laravel",
        "require": {"axiomasoft/billium-core": "^0.1"},
        "require-dev": {"axiomasoft/billium-testkit": "^0.1"},
        "repositories": [{"type": "package", "package": packages}, {"packagist.org": False}],
    }
    (consumer / "composer.json").write_text(json.dumps(manifest))
    env = {**os.environ, "COMPOSER_DISABLE_NETWORK": "1"}

    def run(*args):
        subprocess.run(args, cwd=consumer, env=env, check=True)

    run("composer", "update", "--prefer-dist", "--no-interaction", "--no-progress", "--no-audit")
    run("php", "-r", r"""
        require 'vendor/autoload.php';
        Billium\Testkit\Drivers\SimulatedCapabilities::snapshot();
        if (is_dir('vendor/axiomasoft/billium-execution')) {
            throw new RuntimeException('Execution installed twice.');
        }
    """)
    run("composer", "install", "--no-dev", "--no-interaction", "--no-progress")
    run("php", "-r", r"""
        require 'vendor/autoload.php';
        $app = Illuminate\Foundation\Application::configure(basePath: getcwd())->create();
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $plugins = $app->make(Packman\Plugins\Contracts\Registry\PluginInspector::class)->describe();
        if (array_column($plugins, 'id') !== ['axiomasoft.billium.platform', 'axiomasoft.billium.ledger']) {
            throw new RuntimeException('Unexpected installed financial plugins.');
        }
        $app->make(Billium\Core\Contracts\Modules\ModuleAdmissionGate::class);
        if (class_exists(Billium\Testkit\Drivers\SimulatedCapabilities::class)) {
            throw new RuntimeException('Testkit was loaded in production.');
        }
        echo "smoke Laravel: ok\n";
    """)


if __name__ == "__main__":
    main()
