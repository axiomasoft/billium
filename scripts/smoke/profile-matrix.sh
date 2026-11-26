#!/usr/bin/env bash
# Repeatable profile matrix smoke check for Billium P10.1.
# Covers: contract-only, embedded, standalone, remote profiles.
# Run inside the toolchain image:
#   docker compose run --rm -u 0:0 toolchain bash scripts/smoke/profile-matrix.sh [all|contract-only|embedded|standalone|remote]
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TARGET="${1:-all}"

smoke_contract_only() {
    echo "[SMOKE] profile=contract-only"
    php -r '
        declare(strict_types=1);
        require $argv[1] . "/vendor/autoload.php";

        $composer = json_decode((string) file_get_contents($argv[1] . "/packages/contracts/composer.json"), true, 32, JSON_THROW_ON_ERROR);
        foreach (array_keys($composer["require"] ?? []) as $pkg) {
            if (preg_match("/^(?:axiomasoft\/billium-(?:core|server)|laravel\/|illuminate\/|ext-pdo|stripe\/|adyen\/|yookassa\/)/i", $pkg) === 1) {
                fwrite(STDERR, "contract-only: forbidden PHP dependency: {$pkg}\n");
                exit(1);
            }
        }

        $money = \Billium\Contracts\Data\Money::parse("1490.00", "RUB");
        if ($money->minor !== 149000) {
            fwrite(STDERR, "contract-only: unexpected minor units\n");
            exit(1);
        }

        $codec = new \Billium\Contracts\Contracts\Codec();
        $canonical = $codec->canonicalize(["currency" => "RUB", "amount" => "1490.00"]);
        if ($canonical !== "{\"amount\":\"1490.00\",\"currency\":\"RUB\"}") {
            fwrite(STDERR, "contract-only: unexpected canonical json\n");
            exit(1);
        }

        $pubspec = (string) file_get_contents($argv[1] . "/packages/contracts-dart/pubspec.yaml");
        if (preg_match("/^\s*(?:billium_core|billium_server|http|dio|postgres|sqlite3|stripe|adyen|flutter)\s*:/mi", $pubspec) === 1) {
            fwrite(STDERR, "contract-only: forbidden Dart dependency\n");
            exit(1);
        }
    ' "$ROOT"
    echo "[PASS] profile=contract-only (php+dart contract isolation & codec ok)"
}

smoke_embedded() {
    echo "[SMOKE] profile=embedded"
    php -r '
        declare(strict_types=1);
        require $argv[1] . "/vendor/autoload.php";

        use Billium\Core\BilliumCoreServiceProvider;
        use Billium\Core\Data\Modules\CompatibilityInput;
        use Billium\Core\Data\Modules\InstalledCheckpoint;
        use Billium\Core\Data\Modules\MigrationIdentity;
        use Billium\Core\Enums\Modules\CompatibilityFindingCode as Code;
        use Billium\Core\Internal\Modules\ModuleCatalog;
        use Billium\Documents\Laravel\DocumentsServiceProvider;
        use Billium\Execution\Contracts\PaymentDrivers;
        use Billium\Execution\Contracts\WriterAdmission;
        use Billium\Execution\Laravel\ExecutionServiceProvider;
        use Billium\Ledger\Contracts\Ledger;
        use Billium\Ledger\Laravel\LedgerServiceProvider;
        use Billium\Platform\Laravel\PlatformServiceProvider;
        use Illuminate\Config\Repository;
        use Illuminate\Container\Container;
        use Illuminate\Database\DatabaseServiceProvider;
        use Illuminate\Encryption\EncryptionServiceProvider;
        use Illuminate\Filesystem\FilesystemServiceProvider;
        use Illuminate\Foundation\Application;

        $ledgerPorts = ["AuthContext", "SellerContext", "AuthorizationVerdict", "FinancialSessionAdmission", "FinancialAuthorityEvidence"];
        $catalog = ModuleCatalog::packaged();
        $checkpoints = [];
        foreach (["platform", "ledger"] as $m) {
            $d = $catalog->descriptor($m);
            $checkpoints[$m] = new InstalledCheckpoint($m, $d->currentSchemaVersion, array_map(static fn (MigrationIdentity $mi): string => $mi->id, $d->migrationIdentities));
        }

        $report = $catalog->check(new CompatibilityInput("ledger-only", ["platform", "ledger"], $ledgerPorts, $checkpoints));
        if (! $report->isReady()) {
            fwrite(STDERR, "embedded: compatibility failed\n");
            exit(1);
        }
        if (! $catalog->check(new CompatibilityInput("ledger-only", ["platform", "ledger", "execution"], $ledgerPorts, $checkpoints))->has(Code::CompositionForbidden, "execution")) {
            fwrite(STDERR, "embedded: execution must be forbidden\n");
            exit(1);
        }

        $app = new Application($argv[1]);
        Container::setInstance($app);
        $app->instance("config", new Repository([
            "app" => ["key" => "base64:" . base64_encode(str_repeat("k", 32)), "cipher" => "AES-256-CBC"],
            "database" => ["default" => "pgsql", "connections" => ["pgsql" => ["driver" => "pgsql", "database" => "billium_test"]]],
            "billium" => [
                "feed" => ["signing_key" => str_repeat("k", 32)],
                "modules" => ["composition" => "ledger-only", "enabled" => ["platform", "ledger"]],
                "documents" => ["enabled" => false],
                "execution" => ["outgoing_enabled" => false],
            ],
        ]));
        $app->register(DatabaseServiceProvider::class);
        $app->register(EncryptionServiceProvider::class);
        $app->register(BilliumCoreServiceProvider::class);
        $app->boot();

        if ($app->getProviders(PlatformServiceProvider::class) === []
            || $app->getProviders(LedgerServiceProvider::class) === []
            || ! $app->bound(Ledger::class)
            || $app->getProviders(ExecutionServiceProvider::class) !== []
            || $app->getProviders(DocumentsServiceProvider::class) !== []
            || $app->bound(PaymentDrivers::class)
            || $app->bound(WriterAdmission::class)
        ) {
            fwrite(STDERR, "embedded: runtime provider/binding invariant violated\n");
            exit(1);
        }
    ' "$ROOT"
    echo "[PASS] profile=embedded (ledger-only active, execution/documents absent, zero provider dispatch)"
}

smoke_standalone() {
    echo "[SMOKE] profile=standalone"
    php -r '
        declare(strict_types=1);
        require $argv[1] . "/vendor/autoload.php";

        use Billium\Core\Data\Modules\CompatibilityInput;
        use Billium\Core\Data\Modules\InstalledCheckpoint;
        use Billium\Core\Data\Modules\MigrationIdentity;
        use Billium\Core\Enums\Modules\CompatibilityFindingCode as Code;
        use Billium\Core\Enums\Modules\ModuleLifecycleState as State;
        use Billium\Core\Enums\Modules\ModuleOperationClass as Operation;
        use Billium\Core\Data\Modules\ModuleState;
        use Billium\Core\Internal\Modules\ModuleCatalog;
        use Billium\Core\Internal\Modules\ModuleLifecycle;

        $ledgerPorts = ["AuthContext", "SellerContext", "AuthorizationVerdict", "FinancialSessionAdmission", "FinancialAuthorityEvidence"];
        $execPorts = ["PaymentDrivers", "WriterAdmission", "PaymentObservationSink", "DispatchEgress", "MerchantCredentials", "ProviderAbsenceVerifier", "ExecutionOriginResolver", "AdapterQualification", "ExecutionReadiness"];
        $catalog = ModuleCatalog::packaged();
        $checkpoints = [];
        foreach (["platform", "ledger", "execution", "collection"] as $m) {
            $d = $catalog->descriptor($m);
            $checkpoints[$m] = new InstalledCheckpoint($m, $d->currentSchemaVersion, array_map(static fn (MigrationIdentity $mi): string => $mi->id, $d->migrationIdentities));
        }

        $ledgerOnly = $catalog->check(new CompatibilityInput("standalone-billium", ["platform", "ledger"], $ledgerPorts, $checkpoints));
        $full = $catalog->check(new CompatibilityInput("standalone-billium", ["collection", "execution", "ledger", "platform"], [...$ledgerPorts, ...$execPorts, "CollectionConsentGuard"], $checkpoints));
        $badDep = $catalog->check(new CompatibilityInput("standalone-billium", ["platform", "ledger", "collection"], [...$ledgerPorts, "CollectionConsentGuard"], $checkpoints));

        if (! $ledgerOnly->isReady() || ! $full->isReady() || $full->bootOrder !== ["platform", "ledger", "execution", "collection"] || ! $badDep->has(Code::DependencyMissing, "collection")) {
            fwrite(STDERR, "standalone: composition matrix check failed\n");
            exit(1);
        }

        $lifecycle = new ModuleLifecycle($catalog);
        $retired = new ModuleState("inst-smoke", "execution", State::Retired, 1, 0);
        if ($lifecycle->admit($retired, Operation::NewIntent)->allowed || ! $lifecycle->admit($retired, Operation::HistoricalRead)->allowed) {
            fwrite(STDERR, "standalone: post-disable history read invariant failed\n");
            exit(1);
        }
    ' "$ROOT"
    echo "[PASS] profile=standalone (DAG order, optional execution, post-disable history read ok)"
}

smoke_remote() {
    echo "[SMOKE] profile=remote"
    php -r '
        declare(strict_types=1);
        require $argv[1] . "/vendor/autoload.php";

        use Billium\Core\BilliumCoreServiceProvider;
        use Billium\Core\Data\Modules\CompatibilityInput;
        use Billium\Core\Data\Modules\InstalledCheckpoint;
        use Billium\Core\Data\Modules\MigrationIdentity;
        use Billium\Core\Enums\Modules\CompatibilityFindingCode as Code;
        use Billium\Core\Internal\Modules\ModuleCatalog;
        use Billium\Execution\Laravel\ExecutionServiceProvider;
        use Billium\Server\BilliumServerServiceProvider;
        use Illuminate\Config\Repository;
        use Illuminate\Container\Container;
        use Illuminate\Database\DatabaseServiceProvider;
        use Illuminate\Encryption\EncryptionServiceProvider;
        use Illuminate\Filesystem\FilesystemServiceProvider;
        use Illuminate\Foundation\Application;

        $ports = [
            "AuthContext", "SellerContext", "AuthorizationVerdict", "FinancialSessionAdmission", "FinancialAuthorityEvidence",
            "PaymentDrivers", "WriterAdmission", "PaymentObservationSink", "DispatchEgress", "MerchantCredentials",
            "ProviderAbsenceVerifier", "ExecutionOriginResolver", "AdapterQualification", "ExecutionReadiness",
        ];
        $catalog = ModuleCatalog::packaged();
        $checkpoints = [];
        foreach (["platform", "ledger", "execution"] as $m) {
            $d = $catalog->descriptor($m);
            $checkpoints[$m] = new InstalledCheckpoint($m, $d->currentSchemaVersion, array_map(static fn (MigrationIdentity $mi): string => $mi->id, $d->migrationIdentities));
        }

        $ready = $catalog->check(new CompatibilityInput("remote-billium", ["platform", "ledger", "execution"], $ports, $checkpoints));
        $missingExec = $catalog->check(new CompatibilityInput("remote-billium", ["platform", "ledger"], $ports, $checkpoints));
        if (! $ready->isReady() || ! $missingExec->has(Code::CompositionRequired, "execution")) {
            fwrite(STDERR, "remote: composition check failed\n");
            exit(1);
        }

        $app = new Application($argv[1]);
        Container::setInstance($app);
        $app->instance("config", new Repository([
            "app" => ["key" => "base64:" . base64_encode(str_repeat("k", 32)), "cipher" => "AES-256-CBC"],
            "database" => ["default" => "pgsql", "connections" => ["pgsql" => ["driver" => "pgsql", "database" => "billium_test"]]],
            "billium" => [
                "feed" => ["signing_key" => str_repeat("k", 32)],
                "modules" => ["composition" => "remote-billium", "enabled" => ["platform", "ledger", "execution"]],
                "execution" => ["outgoing_enabled" => false],
            ],
        ]));
        $app->register(DatabaseServiceProvider::class);
        $app->register(EncryptionServiceProvider::class);
        $app->register(FilesystemServiceProvider::class);
        $app->register(BilliumCoreServiceProvider::class);
        $app->register(BilliumServerServiceProvider::class);
        $app->boot();

        if ($app->getProviders(ExecutionServiceProvider::class) === [] || $app->getProviders(BilliumServerServiceProvider::class) === []) {
            fwrite(STDERR, "remote: server/execution provider boot failed\n");
            exit(1);
        }
    ' "$ROOT"
    echo "[PASS] profile=remote (execution mandatory, server+core boot ok)"
}

case "$TARGET" in
    contract-only) smoke_contract_only ;;
    embedded) smoke_embedded ;;
    standalone) smoke_standalone ;;
    remote) smoke_remote ;;
    all)
        smoke_contract_only
        smoke_embedded
        smoke_standalone
        smoke_remote
        echo "[PASS] profile-matrix: 4/4 profiles passed"
        ;;
    *)
        echo "profile-matrix: unknown target: $TARGET" >&2
        exit 2
        ;;
esac
