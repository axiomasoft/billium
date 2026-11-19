"""Verify standalone contracts and Laravel discovery from the distributable PHP archives."""

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


def run(consumer, *args):
    env = {**os.environ, "COMPOSER_DISABLE_NETWORK": "1"}
    subprocess.run(args, cwd=consumer, env=env, check=True)


def prepare(consumer, packages, required, development=None):
    consumer.mkdir()
    manifest = {
        "name": "consumer/billium-smoke",
        "require": required,
        "repositories": [{"type": "package", "package": packages}, {"packagist.org": False}],
    }
    if development:
        manifest["require-dev"] = development
    (consumer / "composer.json").write_text(json.dumps(manifest))


def contracts(consumer, packages):
    prepare(consumer, packages, {"axiomasoft/billium-contracts": "^0.1"})
    run(consumer, "composer", "update", "--no-dev", "--prefer-dist", "--no-interaction", "--no-progress", "--no-audit")
    run(consumer, "php", "-r", r"""
        require 'vendor/autoload.php';
        if (is_link('vendor/axiomasoft/billium-contracts') || is_dir('vendor/laravel') || is_dir('vendor/illuminate')) {
            throw new RuntimeException('The contracts archive must install independently of Laravel.');
        }
        $money = Billium\Contracts\Data\Money::fromWire(['amount' => '1490.00', 'currency' => 'RUB']);
        $envelope = (new Billium\Contracts\Contracts\Codec)->decode(getenv('EXECUTE'));
        if ($money->minor !== 149000 || ! $envelope instanceof Billium\Contracts\Data\Envelopes\ExecutePayment || $envelope->money->minor !== 149000) {
            throw new RuntimeException('Invalid standalone money or ExecutePayment envelope.');
        }
        echo "smoke php: ok\n";
    """)


def laravel(consumer, packages):
    prepare(consumer, packages, {"axiomasoft/billium-core": "^0.1"}, {"axiomasoft/billium-testkit": "^0.1"})
    (consumer / "bootstrap/cache").mkdir(parents=True)
    (consumer / "storage/framework/views").mkdir(parents=True)
    run(consumer, "composer", "update", "--prefer-dist", "--no-interaction", "--no-progress", "--no-audit")
    run(consumer, "php", "-r", r"""
        require 'vendor/autoload.php';
        Billium\Testkit\Drivers\SimulatedCapabilities::snapshot();
        if (is_dir('vendor/axiomasoft/billium-execution')) {
            throw new RuntimeException('Execution installed twice.');
        }
    """)
    run(consumer, "composer", "install", "--no-dev", "--no-interaction", "--no-progress")
    run(consumer, "php", "-r", r"""
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


def main():
    root = Path(__file__).resolve().parents[2]
    packman = (root / "../packman").resolve()
    consumer = Path(sys.argv[1])
    archives = consumer / "archives"
    archives.mkdir(parents=True)

    lock = json.loads((root / "composer.lock").read_text())
    packages = [p for p in lock["packages"] if not p["name"].startswith("axiomasoft/")]
    for slug in ("core", "contracts", "testkit"):
        packages.append(package_archive(root, Path("packages") / slug, "0.1.0", archives))
    for slug in ("core", "schema", "plugins"):
        packages.append(package_archive(packman, Path("packages") / slug, "0.1.1", archives))

    contracts(consumer / "contracts", packages)
    laravel(consumer / "laravel", packages)


if __name__ == "__main__":
    main()
