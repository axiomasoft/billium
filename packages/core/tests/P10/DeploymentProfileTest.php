<?php

declare(strict_types=1);

namespace Billium\Core\Tests\P10;

use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Execution\Contracts\ExecutionReadiness;
use Billium\Execution\Data\AdapterQualificationManifest;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\FrozenBinding;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Enums\QualificationMode;
use Billium\Execution\Internal\Admission\ExecutionReadinessGuard;
use Billium\Execution\Internal\Dispatch\TransportBoundary;
use DateTimeImmutable;
use Illuminate\Container\Container;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\DatabaseManager;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('P10.7')]
#[Group('D71')]
#[Group('D72')]
final class DeploymentProfileTest extends TestCase
{
    public function test_valid_complete_live_manifest_passes_schema_and_semantic_validator(): void
    {
        $schemaPath = self::repoPath('spec/deployment-profile.schema.json');
        self::assertFileExists($schemaPath);

        $schema = json_decode((string) file_get_contents($schemaPath), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('billium.deployment.profile.v1', $schema['$id']);

        $result = self::runValidator(self::validLiveManifest(), ['--require-live']);

        self::assertSame(0, $result['exitCode'], json_encode($result, JSON_THROW_ON_ERROR));
        self::assertSame('valid', $result['report']['status']);
        self::assertSame(0, $result['report']['errorCount']);
        self::assertSame('prod-remote-eu-01', $result['report']['profileId']);
        self::assertSame('live', $result['report']['activationMode']);
    }

    public function test_missing_and_invalid_fields_are_rejected(): void
    {
        // Missing required operational sections on a non-contracts-only profile.
        $missingSections = self::validLiveManifest();
        unset($missingSections['authority'], $missingSections['retention'], $missingSections['slo']);
        $missingResult = self::runValidator($missingSections);

        self::assertSame(1, $missingResult['exitCode']);
        self::assertSame('invalid', $missingResult['report']['status']);
        $missingCodes = array_column($missingResult['report']['errors'], 'code');
        self::assertContains('profile.section_required', $missingCodes);

        // Invalid schema values: bad capabilityDigest, unknown extra field, invalid currency.
        $invalidTypes = self::validLiveManifest();
        $invalidTypes['driver']['capabilityDigest'] = 'not-a-sha256';
        $invalidTypes['seller']['currencies'] = ['rubles'];
        $invalidTypes['unexpectedExtra'] = true;
        $invalidResult = self::runValidator($invalidTypes);

        self::assertSame(1, $invalidResult['exitCode']);
        $invalidCodes = array_column($invalidResult['report']['errors'], 'code');
        self::assertContains('schema.pattern', $invalidCodes);
        self::assertContains('schema.additionalProperties', $invalidCodes);

        // Recurring mandate enabled without policy ref or recurring_consent capability.
        $badMandate = self::validLiveManifest();
        $badMandate['mandateAndFiscal']['mandatePolicyRef'] = null;
        $badMandate['driver']['capabilities'] = array_values(array_diff(
            $badMandate['driver']['capabilities'],
            ['recurring_consent'],
        ));
        $mandateResult = self::runValidator($badMandate);

        self::assertSame(1, $mandateResult['exitCode']);
        $mandateCodes = array_column($mandateResult['report']['errors'], 'code');
        self::assertContains('mandate.policy_required', $mandateCodes);
        self::assertContains('mandate.capability_missing', $mandateCodes);

        // Live activation missing historical refund support and egress quiesce.
        $missingOps = self::validLiveManifest();
        $missingOps['driver']['historicalRefundSupported'] = false;
        $missingOps['authority']['egressQuiesceSupported'] = false;
        $opsResult = self::runValidator($missingOps, ['--require-live']);

        self::assertSame(1, $opsResult['exitCode']);
        $opsCodes = array_column($opsResult['report']['errors'], 'code');
        self::assertContains('live.historical_refund_required', $opsCodes);
        self::assertContains('live.egress_quiesce_required', $opsCodes);
    }

    public function test_unqualified_or_simulated_provider_is_blocked_from_live_execution(): void
    {
        // Case 1: unqualified provider cannot activate live.
        $unqualified = self::validLiveManifest();
        $unqualified['qualification']['status'] = 'unqualified';
        $unqualifiedResult = self::runValidator($unqualified, ['--require-live']);

        self::assertSame(1, $unqualifiedResult['exitCode']);
        self::assertContains(
            'live.provider_unqualified',
            array_column($unqualifiedResult['report']['errors'], 'code'),
        );

        // Case 2: simulated qualification mode cannot authorize live execution even if status='qualified'.
        $simulatedLive = self::validLiveManifest();
        $simulatedLive['qualification']['mode'] = QualificationMode::Simulated->value;
        $simulatedResult = self::runValidator($simulatedLive, ['--require-live']);

        self::assertSame(1, $simulatedResult['exitCode']);
        self::assertContains(
            'live.simulated_not_allowed',
            array_column($simulatedResult['report']['errors'], 'code'),
        );

        // Case 3: unresolved qualification gaps block live activation.
        $withGaps = self::validLiveManifest();
        $withGaps['qualification']['unresolvedGaps'] = ['GAP-REFUND-WINDOW-UNVERIFIED'];
        $gapsResult = self::runValidator($withGaps, ['--require-live']);

        self::assertSame(1, $gapsResult['exitCode']);
        self::assertContains(
            'live.unresolved_gaps',
            array_column($gapsResult['report']['errors'], 'code'),
        );

        // Case 4: runtime boundary — ExecutionReadinessGuard fails closed when evidence refs or outgoing flag are absent.
        $manifest = new AdapterQualificationManifest(
            manifestRef: 'qual-sim-01',
            driverId: 'fake-psp-v1',
            driverContractVersion: '1.0.0',
            providerApiVersion: '2026-01-01',
            capabilityDigest: 'sha256:'.str_repeat('a', 64),
            merchantAccountRef: 'acct_sim_01',
            environment: 'sandbox',
            officialSources: ['https://provider.example/docs/api'],
            checkedAt: new DateTimeImmutable('2026-11-22T00:00:00Z'),
            credentialRotationBehavior: 'dual_key_overlap_24h',
            sandboxEvidenceRefs: ['evidence://sandbox/run-01'],
            unresolvedGaps: ['live-merchant-dossier-pending'],
            mode: QualificationMode::Simulated,
            qualificationEvidenceRef: 'evidence://qual/sim-01',
        );
        self::assertSame(QualificationMode::Simulated, $manifest->mode);
        self::assertNotSame(QualificationMode::ProviderVerified, $manifest->mode);

        $container = new Container;
        $dbManager = new DatabaseManager($container, new ConnectionFactory($container));

        $guard = new ExecutionReadinessGuard(
            BilliumOptions::fromArray([
                'execution' => [
                    'outgoing_enabled' => true,
                    'release_evidence_ref' => null,
                    'retention_policy_ref' => null,
                ],
            ]),
            new TransportBoundary($dbManager),
            static fn (): ?ExecutionReadiness => null,
        );

        $binding = new FrozenBinding(
            scope: new ExecutionScope('inst_eu_01', 'seller_eu_01', 'production', 'ns_eu_01'),
            executionProfile: 'remote-billium',
            businessIntentRef: 'intent_01',
            rootOperationRef: 'op_01',
            merchantRevisionRef: 'rev_01',
            merchantAccountRef: 'acct_sim_01',
            driverId: 'fake-psp-v1',
            driverContractVersion: '1.0.0',
            capabilityDigest: 'sha256:'.str_repeat('a', 64),
            semanticDigest: 'sha256:'.str_repeat('c', 64),
            canonicalizationVersion: 'jcs.v1',
            collectionAuthorizationRef: null,
            sourceContext: 'checkout',
        );

        try {
            $guard->assertReady($binding, EffectKind::Charge, Money::fromWire(['amount' => '10.00', 'currency' => 'EUR']));
            self::fail('Expected ExecutionReadinessGuard to fail closed without release_evidence_ref');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::RecoveryRequired, $e->problem);
            self::assertStringContainsString('release_evidence_ref', $e->getMessage());
        }
    }

    public function test_separable_contracts_only_profile_is_allowed_without_live_qualification(): void
    {
        $contractsOnly = [
            'schema' => 'billium.deployment.profile.v1',
            'profileId' => 'contracts-only-sdk',
            'profileType' => 'contracts-only',
            'environment' => 'sandbox',
            'activationMode' => 'contracts-only',
            'generatedAt' => '2026-11-22T19:00:00Z',
            'modules' => [
                'composition' => 'contracts-only',
                'enabled' => ['contracts'],
                'executionEnabled' => false,
            ],
            'qualification' => [
                'status' => 'not_applicable',
                'mode' => 'none',
                'manifestRef' => null,
                'checkedAt' => null,
                'officialSources' => [],
                'sandboxEvidenceRefs' => [],
                'liveEvidenceRefs' => [],
                'unresolvedGaps' => [],
            ],
        ];

        $validResult = self::runValidator($contractsOnly);
        self::assertSame(0, $validResult['exitCode'], json_encode($validResult, JSON_THROW_ON_ERROR));
        self::assertSame('valid', $validResult['report']['status']);
        self::assertSame('contracts-only', $validResult['report']['profileType']);

        // Promoting contracts-only directly to --require-live is still blocked.
        $livePromotion = self::runValidator($contractsOnly, ['--require-live']);
        self::assertSame(1, $livePromotion['exitCode']);
        self::assertContains(
            'live.required',
            array_column($livePromotion['report']['errors'], 'code'),
        );
    }

    /** @return array<string, mixed> */
    private static function validLiveManifest(): array
    {
        return [
            'schema' => 'billium.deployment.profile.v1',
            'profileId' => 'prod-remote-eu-01',
            'profileType' => 'remote',
            'environment' => 'production',
            'activationMode' => 'live',
            'generatedAt' => '2026-11-22T19:00:00Z',
            'seller' => [
                'sellerRef' => 'seller_eu_01',
                'merchantAccountRef' => 'acct_live_eu_01',
                'currencies' => ['EUR', 'USD'],
                'jurisdiction' => 'DE',
            ],
            'modules' => [
                'composition' => 'remote-server',
                'enabled' => ['platform', 'ledger', 'execution', 'documents', 'collection', 'server'],
                'executionEnabled' => true,
            ],
            'driver' => [
                'driverId' => 'fake-qualified-psp',
                'driverContractVersion' => '1.0.0',
                'providerApiVersion' => '2026-06-01',
                'capabilityDigest' => 'sha256:'.str_repeat('b', 64),
                'capabilities' => [
                    'initiate_with_idempotency',
                    'lookup_by_provider_id',
                    'lookup_by_reference',
                    'authorize',
                    'capture',
                    'partial_refund',
                    'refund_lookup',
                    'recurring_consent',
                    'webhook_authenticity',
                    'webhook_event_identity',
                    'inventory_complete',
                ],
                'historicalLookupSupported' => true,
                'historicalRefundSupported' => true,
                'credentialRotationBehavior' => 'dual_key_overlap_24h',
            ],
            'authority' => [
                'owner' => 'payments-platform-oncall',
                'writerAuthorityKind' => 'remote-authority',
                'egressQuiesceSupported' => true,
                'egressQuiesceRunbookRef' => 'docs/operations/restore.md#egress-quiesce',
                'fencingEpochRequired' => true,
            ],
            'mandateAndFiscal' => [
                'recurringMandateEnabled' => true,
                'mandatePolicyRef' => 'policy://mandate/sepa-card-v1',
                'fiscalMode' => 'passthrough',
                'fiscalProfileRef' => 'policy://fiscal/eu-vat-v1',
            ],
            'retention' => [
                'policyRef' => 'policy://retention/ret-01-v1',
                'outboxMinDays' => 30,
                'receiptMinDays' => 365,
                'auditEvidenceMinDays' => 2555,
                'legalHoldSupported' => true,
            ],
            'slo' => [
                'availabilityTargetPct' => 99.95,
                'p95LatencyMs' => 250,
                'rpoSeconds' => 60,
                'rtoSeconds' => 900,
                'escalationContact' => 'oncall-payments@example.invalid',
            ],
            'qualification' => [
                'status' => 'qualified',
                'mode' => QualificationMode::ProviderVerified->value,
                'manifestRef' => 'qual://fake-qualified-psp/2026-11-22',
                'checkedAt' => '2026-11-22T18:30:00Z',
                'officialSources' => ['https://provider.example/docs/idempotency-v2'],
                'sandboxEvidenceRefs' => ['evidence://sandbox/fake-qualified-psp/run-42'],
                'liveEvidenceRefs' => ['evidence://live-qual/fake-qualified-psp/dossier-01'],
                'unresolvedGaps' => [],
            ],
            'evidenceRefs' => [
                'releaseEvidenceRef' => 'evidence://release/2026.11.22-rc1',
                'retentionPolicyRef' => 'policy://retention/ret-01-v1',
                'restoreDrillRef' => 'evidence://drills/restore-2026-11-22',
                'securityGateRef' => 'evidence://security/p10-6-2026-11-22',
                'supplyChainRef' => 'evidence://supply-chain/p10-6-2026-11-22',
                'qualificationEvidenceRef' => 'evidence://live-qual/fake-qualified-psp/dossier-01',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  list<string>  $extraArgs
     * @return array{exitCode: int, report: array<string, mixed>, rawStdout: string, rawStderr: string}
     */
    private static function runValidator(array $manifest, array $extraArgs = []): array
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'billium-deploy-profile-');
        self::assertIsString($tmpFile);
        file_put_contents($tmpFile, json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        try {
            $script = self::repoPath('scripts/quality/deployment-profile.py');
            $cmd = array_merge(['python3', $script, $tmpFile, '--json'], $extraArgs);
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $proc = proc_open($cmd, $descriptors, $pipes, self::repoRoot());
            self::assertIsResource($proc);
            fclose($pipes[0]);
            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($proc);

            $decoded = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);

            return [
                'exitCode' => $exitCode,
                'report' => $decoded,
                'rawStdout' => $stdout,
                'rawStderr' => $stderr,
            ];
        } finally {
            @unlink($tmpFile);
        }
    }

    private static function repoRoot(): string
    {
        return dirname(__DIR__, 4);
    }

    private static function repoPath(string $relative): string
    {
        return self::repoRoot().DIRECTORY_SEPARATOR.$relative;
    }
}
