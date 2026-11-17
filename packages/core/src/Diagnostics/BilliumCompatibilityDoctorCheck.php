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
use Override;
use Packman\Attributes\Api;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Data\DoctorFinding;

/**
 * Read-only Packman doctor check verifying Billium closed-schema options, module composition compatibility, and signing/role readiness.
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
        $checkpoints = $this->checkpointsResolver !== null
            ? ($this->checkpointsResolver)()
            : $this->defaultSpecCheckpoints();
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
