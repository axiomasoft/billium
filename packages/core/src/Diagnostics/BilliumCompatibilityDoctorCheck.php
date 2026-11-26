<?php

declare(strict_types=1);

namespace Billium\Core\Diagnostics;

use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Modules\ModuleRegistry;
use Billium\Core\Data\Modules\CompatibilityInput;
use Billium\Core\Data\Modules\InstalledCheckpoint;
use Billium\Core\Data\Modules\MigrationIdentity;
use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Override;
use Packman\Attributes\Api;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Data\DoctorFinding;
use PDO;
use Throwable;

/**
 * Read-only Packman doctor check verifying Billium closed-schema options, real DB checkpoints, and signing/role readiness.
 */
#[Api(since: '1.0')]
final readonly class BilliumCompatibilityDoctorCheck implements DoctorCheck
{
    /**
     * @param  (Closure(): BilliumOptions)|null  $optionsResolver
     * @param  (Closure(): list<string>)|null  $portsResolver
     * @param  (Closure(): array<string, InstalledCheckpoint>)|null  $checkpointsResolver
     */
    public function __construct(
        private Repository $config,
        private ModuleRegistry $registry,
        private ?Closure $optionsResolver = null,
        private ?Closure $portsResolver = null,
        private ?Closure $checkpointsResolver = null,
        private ?DatabaseManager $databases = null,
    ) {}

    #[Override]
    public function key(): string
    {
        return 'billium.modules.compatibility';
    }

    #[Override]
    public function title(): string
    {
        return 'Billium module composition, configuration, and security readiness';
    }

    /** @return list<DoctorFinding> */
    #[Override]
    public function run(DoctorContext $context): array
    {
        try {
            $options = $this->resolveOptions();
        } catch (InvalidConfiguration $exception) {
            return [
                DoctorFinding::error(
                    code: 'billium.config.invalid',
                    message: $exception->getMessage(),
                    remediation: 'Fix invalid or unknown keys under config/billium.php to match the closed Billium configuration schema.',
                ),
            ];
        }

        $findings = [];
        $ports = $this->portsResolver !== null ? ($this->portsResolver)() : [];
        $checkpoints = $this->resolveCheckpoints($options, $context, $findings);

        if ($checkpoints !== null) {
            $report = $this->registry->check(new CompatibilityInput(
                composition: $options->modules->composition,
                enabled: $options->modules->enabled,
                providedPorts: $ports,
                checkpoints: $checkpoints,
            ));

            foreach ($report->findings as $finding) {
                $code = 'billium.modules.'.$finding->code->value;
                $evidence = $finding->moduleId !== null ? ['module' => $finding->moduleId] : [];
                $remediation = 'Align billium.modules composition/enabled list and host SPI bindings with packaged module descriptors.';

                if (! $context->production && $finding->code->value === 'module.port_missing' && $this->portsResolver === null) {
                    $findings[] = DoctorFinding::warning(
                        code: $code,
                        message: $finding->message,
                        remediation: $remediation,
                        evidence: $evidence,
                        slice: $finding->moduleId,
                    );

                    continue;
                }

                $findings[] = DoctorFinding::error(
                    code: $code,
                    message: $finding->message,
                    remediation: $remediation,
                    evidence: $evidence,
                    slice: $finding->moduleId,
                );
            }
        }

        $appKey = $this->config->get('app.key');
        $signingKey = $options->feed->decodedSigningKey(is_string($appKey) ? $appKey : null);

        if ($signingKey === '') {
            $findings[] = DoctorFinding::at(
                status: $context->strictStatus(),
                severity: $context->strictSeverity(),
                code: 'billium.feed.signing_key_missing',
                message: 'Event feed signing key (billium.feed.signing_key / app.key) is not configured.',
                remediation: 'Provision a dedicated 32+ byte secret in BILLIUM feed signing_key or APP_KEY.',
            );
        } elseif (strlen($signingKey) < 32) {
            $findings[] = DoctorFinding::error(
                code: 'billium.feed.signing_key_too_short',
                message: sprintf('Event feed signing key must be at least 32 bytes (%d bytes configured).', strlen($signingKey)),
                remediation: 'Provision a dedicated 32+ byte secret in BILLIUM feed signing_key or APP_KEY.',
            );
        }

        if ($context->production && ! $options->runtimeRole->preflight) {
            $findings[] = DoctorFinding::warning(
                code: 'billium.runtime_role.preflight_disabled',
                message: 'PostgreSQL D60 runtime role preflight check is disabled in production.',
                remediation: 'Enable billium.runtime_role.preflight to enforce least-privilege runtime role isolation.',
            );
        }

        if ($findings === []) {
            return [
                DoctorFinding::ok(
                    code: 'billium.modules.compatibility',
                    message: 'Billium configuration and module composition are compatible.',
                    evidence: [
                        'composition' => $options->modules->composition,
                        'enabled' => implode(',', $options->modules->enabled),
                    ],
                ),
            ];
        }

        return $findings;
    }

    private function resolveOptions(): BilliumOptions
    {
        if ($this->optionsResolver !== null) {
            return ($this->optionsResolver)();
        }

        $raw = $this->config->get('billium', []);

        if (! is_array($raw)) {
            return BilliumOptions::fromArray(['__invalid_root' => $raw], 'billium');
        }

        return BilliumOptions::fromArray($raw, 'billium');
    }

    /**
     * @param  list<DoctorFinding>  $findings
     * @return array<string, InstalledCheckpoint>|null
     */
    private function resolveCheckpoints(BilliumOptions $options, DoctorContext $context, array &$findings): ?array
    {
        if ($this->checkpointsResolver !== null) {
            return ($this->checkpointsResolver)();
        }

        if ($this->databases === null) {
            if (! $context->production) {
                return $this->defaultSpecCheckpoints();
            }

            $findings[] = DoctorFinding::error(
                code: 'billium.database.unavailable',
                message: 'Database manager is unavailable for inspecting installed migration checkpoints.',
                remediation: 'Bind Illuminate\Database\DatabaseManager or supply a checkpoint resolver.',
            );

            return null;
        }

        $connectionName = $options->connection ?? $this->databases->getDefaultConnection();
        $openConnections = $this->databases->getConnections();
        $alreadyOpen = isset($openConnections[$connectionName]) && $openConnections[$connectionName]->getRawPdo() instanceof PDO;

        if (! $context->production && $options->connection === null && ! $alreadyOpen) {
            return $this->defaultSpecCheckpoints();
        }

        try {
            $connection = $this->databases->connection($connectionName);
            $connection->getPdo();
            $ran = $connection->getSchemaBuilder()->hasTable('migrations')
                ? array_values(array_map(
                    static fn (mixed $value): string => is_string($value) || is_int($value) ? (string) $value : '',
                    $connection->table('migrations')->pluck('migration')->all(),
                ))
                : [];
        } catch (Throwable $exception) {
            $findings[] = DoctorFinding::error(
                code: 'billium.database.unavailable',
                message: sprintf("Database connection '%s' is unavailable: %s", $connectionName, $exception->getMessage()),
                remediation: 'Configure a reachable database connection before evaluating module schema readiness.',
                evidence: ['connection' => $connectionName],
            );

            return null;
        }

        $checkpoints = [];

        foreach ($this->registry->manifest()->modules as $descriptor) {
            if ($descriptor->migrationIdentities === []) {
                $checkpoints[$descriptor->moduleId] = new InstalledCheckpoint(
                    moduleId: $descriptor->moduleId,
                    schemaVersion: $descriptor->currentSchemaVersion,
                );

                continue;
            }

            $expectedIds = array_map(
                static fn (MigrationIdentity $migration): string => $migration->id,
                $descriptor->migrationIdentities,
            );
            $applied = array_values(array_intersect($expectedIds, $ran));

            if ($applied === []) {
                continue;
            }

            $version = 0;
            foreach ($descriptor->migrationIdentities as $migration) {
                if (in_array($migration->id, $applied, true) && $migration->version > $version) {
                    $version = $migration->version;
                }
            }

            $checkpoints[$descriptor->moduleId] = new InstalledCheckpoint(
                moduleId: $descriptor->moduleId,
                schemaVersion: $version,
                appliedMigrations: $applied,
            );
        }

        return $checkpoints;
    }

    /** @return array<string, InstalledCheckpoint> */
    private function defaultSpecCheckpoints(): array
    {
        $checkpoints = [];

        foreach ($this->registry->manifest()->modules as $descriptor) {
            $checkpoints[$descriptor->moduleId] = new InstalledCheckpoint(
                moduleId: $descriptor->moduleId,
                schemaVersion: $descriptor->currentSchemaVersion,
                appliedMigrations: array_map(
                    static fn (MigrationIdentity $migration): string => $migration->id,
                    $descriptor->migrationIdentities,
                ),
            );
        }

        return $checkpoints;
    }
}
