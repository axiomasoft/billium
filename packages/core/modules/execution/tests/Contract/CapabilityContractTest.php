<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Contract;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Execution\Data\AbsenceCertificate;
use Billium\Execution\Data\AdapterQualificationManifest;
use Billium\Execution\Data\CapabilityDetails;
use Billium\Execution\Data\CapabilitySnapshot;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\FrozenBinding;
use Billium\Execution\Data\ProviderLookupResult;
use Billium\Execution\Data\SecretRef;
use Billium\Execution\Enums\CaptureMode;
use Billium\Execution\Enums\DriverCapability;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Enums\ProviderEvidenceMode;
use Billium\Execution\Enums\ProviderLookupState;
use Billium\Execution\Enums\QualificationMode;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-type DetailOverrides array{
 *     version?: string, initiateWithIdempotency?: bool, initiateWithoutIdempotency?: bool,
 *     idempotencyKeyScope?: string|null, idempotencyRetentionSeconds?: int|null, lookupByProviderId?: bool,
 *     lookupVisibility?: string, lookupRetentionSeconds?: int|null, captureMode?: CaptureMode,
 *     multipleRefund?: bool, refundLookup?: bool, signedCallbacks?: bool,
 *     callbackEventIdentity?: bool, callbackEventLookup?: bool, asynchronousStates?: bool,
 *     settlementListing?: bool, settlementExport?: bool, disputes?: bool,
 *     savedMethod?: bool, recurringConsent?: bool, fiscalPassthrough?: bool,
 *     lookupNamespace?: string, referenceSemantics?: string, qualificationManifestRef?: string,
 * }
 * @phpstan-type SnapshotOverrides array{
 *     snapshotRef?: string, driverId?: string, driverContractVersion?: string,
 *     capabilityVersion?: string, merchantAccountRef?: string, supportedEffects?: array<mixed>,
 *     currencies?: array<mixed>, evidenceMode?: ProviderEvidenceMode, partialCapture?: bool,
 *     partialRefund?: bool, partialVoidAuthorization?: bool, authorizationExpiryEvidence?: bool,
 *     lookupByReference?: bool, inventoryComplete?: bool, absenceCertification?: bool,
 *     idempotencyValidUntil?: DateTimeImmutable|null, qualificationMode?: QualificationMode, qualificationEvidenceRef?: string,
 *     digest?: string, details?: CapabilityDetails,
 * }
 */
final class CapabilityContractTest extends TestCase
{
    /** @param DetailOverrides $overrides */
    private static function details(array $overrides = []): CapabilityDetails
    {
        return new CapabilityDetails(...array_replace([
            'version' => 'capability-details.v1',
            'initiateWithIdempotency' => true,
            'initiateWithoutIdempotency' => false,
            'idempotencyKeyScope' => 'provider-account/reference',
            'idempotencyRetentionSeconds' => 86400,
            'lookupByProviderId' => true,
            'lookupVisibility' => 'eventual',
            'lookupRetentionSeconds' => 172800,
            'captureMode' => CaptureMode::None,
            'multipleRefund' => false,
            'refundLookup' => false,
            'signedCallbacks' => false,
            'callbackEventIdentity' => false,
            'callbackEventLookup' => false,
            'asynchronousStates' => true,
            'settlementListing' => false,
            'settlementExport' => false,
            'disputes' => false,
            'savedMethod' => false,
            'recurringConsent' => false,
            'fiscalPassthrough' => false,
            'lookupNamespace' => 'test-account/reference-v1',
            'referenceSemantics' => 'immutable-business-reference.v1',
            'qualificationManifestRef' => 'fixture-bank-a-manifest',
        ], $overrides));
    }

    /** @param SnapshotOverrides $overrides */
    private static function snapshot(array $overrides = []): CapabilitySnapshot
    {
        return new CapabilitySnapshot(...array_replace([
            'snapshotRef' => 'fixture-bank-a-snapshot',
            'driverId' => 'fixture-bank-a-sbp',
            'driverContractVersion' => 'bank-a-exact-v1',
            'capabilityVersion' => 'capabilities-exact-v1',
            'merchantAccountRef' => 'fixture-bank-a-account',
            'supportedEffects' => [EffectKind::Charge],
            'currencies' => ['RUB'],
            'evidenceMode' => ProviderEvidenceMode::CumulativeSnapshot,
            'partialCapture' => false,
            'partialRefund' => false,
            'partialVoidAuthorization' => false,
            'authorizationExpiryEvidence' => false,
            'lookupByReference' => true,
            'inventoryComplete' => false,
            'absenceCertification' => false,
            'idempotencyValidUntil' => new DateTimeImmutable('2026-11-18T00:00:00Z'),
            'qualificationMode' => QualificationMode::Simulated,
            'qualificationEvidenceRef' => 'fixture-qualification-a',
            'digest' => str_repeat('a', 64),
            'details' => self::details(),
        ], $overrides));
    }

    public function test_unsupported_effect_refuses_with_stable_typed_problem_before_a_continuation_runs(): void
    {
        $calls = 0;
        try {
            self::snapshot()->requireEffect(EffectKind::Capture, 'RUB', false);
            $calls++;
            self::fail('Unsupported capture reached the effect continuation');
        } catch (ProblemException $exception) {
            self::assertSame(ProblemCode::CapabilityUnsupported, $exception->problem);
            self::assertSame('billium.capability.unsupported', $exception->problem->value);
        }
        self::assertSame(0, $calls);
        // This contract test has no journal/PSP: runtime zero-row and zero-send assertions belong to admission tests.
    }

    public function test_simulated_qualification_cannot_authorize_a_production_effect(): void
    {
        self::snapshot()->requireEffect(EffectKind::Charge, 'RUB', false);
        $this->expectExceptionObject(new ProblemException(ProblemCode::CapabilityUnsupported, 'Exact effect, currency and qualification are required'));
        self::snapshot()->requireEffect(EffectKind::Charge, 'RUB', true);
    }

    public function test_an_undeclared_currency_is_unsupported(): void
    {
        $this->expectExceptionObject(new ProblemException(ProblemCode::CapabilityUnsupported, 'Exact effect, currency and qualification are required'));
        self::snapshot()->requireEffect(EffectKind::Charge, 'USD', false);
    }

    public function test_named_sbp_provider_capabilities_stay_separate(): void
    {
        $bankA = self::snapshot();
        $bankB = self::snapshot([
            'driverId' => 'fixture-bank-b-sbp',
            'merchantAccountRef' => 'fixture-bank-b-account',
            'supportedEffects' => [EffectKind::Charge, EffectKind::Refund],
            'lookupByReference' => false,
            'details' => self::details(['lookupByProviderId' => false, 'lookupVisibility' => 'none', 'lookupRetentionSeconds' => null]),
        ]);
        $bankB->requireEffect(EffectKind::Refund, 'RUB', false);
        self::assertNotSame($bankA->driverId, $bankB->driverId);
        self::assertTrue($bankA->details->lookupByProviderId);
        self::assertFalse($bankB->details->lookupByProviderId);
        self::assertTrue($bankA->lookupByReference);
        self::assertFalse($bankB->lookupByReference);
        $this->expectException(ProblemException::class);
        $bankA->requireEffect(EffectKind::Refund, 'RUB', false);
    }

    public function test_version_scope_retention_and_capability_gaps_are_explicit(): void
    {
        $snapshot = self::snapshot();
        self::assertSame('bank-a-exact-v1', $snapshot->driverContractVersion);
        self::assertSame('capabilities-exact-v1', $snapshot->capabilityVersion);
        self::assertSame(86400, $snapshot->details->idempotencyRetentionSeconds);
        self::assertSame('provider-account/reference', $snapshot->details->idempotencyKeyScope);
        self::assertSame(172800, $snapshot->details->lookupRetentionSeconds);
        self::assertFalse($snapshot->details->signedCallbacks);
        self::assertFalse($snapshot->details->callbackEventIdentity);
        self::assertFalse($snapshot->details->callbackEventLookup);
        self::assertFalse($snapshot->details->settlementListing);
        self::assertFalse($snapshot->details->settlementExport);
        self::assertFalse($snapshot->details->disputes);
        self::assertFalse($snapshot->details->savedMethod);
        self::assertFalse($snapshot->details->recurringConsent);
        self::assertFalse($snapshot->details->fiscalPassthrough);
    }

    public function test_partial_capture_mode_must_agree_with_the_snapshot(): void
    {
        $this->expectException(ProblemException::class);
        self::snapshot(['details' => self::details(['captureMode' => CaptureMode::PartialMultiple])]);
    }

    public function test_idempotency_support_cannot_omit_scope_or_retention(): void
    {
        $this->expectException(ProblemException::class);
        self::details(['idempotencyRetentionSeconds' => null]);
    }

    public function test_secret_references_carry_only_opaque_locators(): void
    {
        self::assertSame('secret://fixture-bank/account/revision', (new SecretRef('secret://fixture-bank/account/revision'))->locator);
        $this->expectException(ProblemException::class);
        new SecretRef('raw-password-secret');
    }

    /** @return iterable<string, array{ProviderLookupState}> */
    public static function nonAbsenceStates(): iterable
    {
        foreach ([ProviderLookupState::Unavailable, ProviderLookupState::Ambiguous, ProviderLookupState::Unsupported] as $state) {
            yield $state->value => [$state];
        }
    }

    #[DataProvider('nonAbsenceStates')]
    public function test_unavailable_lookup_never_constructs_an_absence_proof(ProviderLookupState $state): void
    {
        $result = new ProviderLookupResult($state, null, null, null, 'fixture.lookup.'.$state->value, null);
        self::assertSame($state, $result->state);
        self::assertNull($result->absence);
        self::assertNull($result->snapshot);
    }

    public function test_found_cannot_be_constructed_without_provider_evidence_and_actual_snapshot(): void
    {
        $this->expectException(ProblemException::class);
        new ProviderLookupResult(ProviderLookupState::Found, null, null, null, null, null);
    }

    public function test_certified_absent_cannot_be_constructed_from_an_http_status_or_timeout(): void
    {
        $this->expectException(ProblemException::class);
        new ProviderLookupResult(ProviderLookupState::CertifiedAbsent, null, null, null, 'http.404', null);
    }

    public function test_certificate_cannot_be_attached_to_an_unavailable_lookup(): void
    {
        $scope = new ExecutionScope('installation', 'seller', 'test', 'authenticated-owner');
        $binding = new FrozenBinding($scope, 'standalone-billium', 'business-intent', 'root-operation', 'merchant-revision', 'account', 'driver', 'exact-v1', 'capability-digest', 'semantic-digest', 'canonical.v1', null, 'commerz');
        $certificate = new AbsenceCertificate('certificate', $binding, 'provider-reference', 'provider-namespace', 'complete-through-1', new DateTimeImmutable('2026-11-01T00:00:00Z'), new DateTimeImmutable('2026-11-02T00:00:00Z'), 'closed-dispatch-manifest', 'retirement-proof', 'egress-fence', 'qualification-proof', 'fixture-issuer', 'signature-digest', new DateTimeImmutable('2026-11-19T00:00:00Z'));
        $this->expectException(ProblemException::class);
        new ProviderLookupResult(ProviderLookupState::Unavailable, null, null, $certificate, 'lookup.unavailable', null);
    }

    /** @return iterable<string, array{DriverCapability, DetailOverrides, SnapshotOverrides}> */
    public static function workflowCapabilities(): iterable
    {
        yield 'create-idempotency' => [DriverCapability::InitiateWithIdempotency, [], []];
        yield 'provider-id-lookup' => [DriverCapability::LookupByProviderId, [], []];
        yield 'reference-lookup' => [DriverCapability::LookupByReference, [], []];
        yield 'authorize' => [DriverCapability::Authorize, [], ['supportedEffects' => [EffectKind::Charge, EffectKind::Authorize]]];
        yield 'partial-capture' => [DriverCapability::PartialCapture, ['captureMode' => CaptureMode::PartialSingle], ['partialCapture' => true, 'supportedEffects' => [EffectKind::Charge, EffectKind::Authorize, EffectKind::Capture]]];
        yield 'provider-void' => [DriverCapability::VoidAuthorization, [], ['supportedEffects' => [EffectKind::Charge, EffectKind::Authorize, EffectKind::VoidAuthorization]]];
        yield 'partial-refund' => [DriverCapability::PartialRefund, [], ['partialRefund' => true, 'supportedEffects' => [EffectKind::Charge, EffectKind::Refund]]];
        yield 'multiple-refund' => [DriverCapability::MultipleRefund, ['multipleRefund' => true], ['supportedEffects' => [EffectKind::Charge, EffectKind::Refund]]];
        yield 'saved-method' => [DriverCapability::SavedMethod, ['savedMethod' => true], []];
        yield 'recurring-consent' => [DriverCapability::RecurringConsent, ['recurringConsent' => true], []];
        yield 'webhook-authenticity' => [DriverCapability::WebhookAuthenticity, ['signedCallbacks' => true], []];
        yield 'webhook-event-lookup' => [DriverCapability::WebhookEventLookup, ['callbackEventIdentity' => true, 'callbackEventLookup' => true], []];
        yield 'receipt-passthrough' => [DriverCapability::FiscalPassthrough, ['fiscalPassthrough' => true], []];
        yield 'settlement-listing' => [DriverCapability::SettlementListing, ['settlementListing' => true], []];
        yield 'settlement-export' => [DriverCapability::SettlementExport, ['settlementExport' => true], []];
        yield 'disputes' => [DriverCapability::Disputes, ['disputes' => true], []];
    }

    /** @param DetailOverrides $detailOverrides
     * @param  SnapshotOverrides  $snapshotOverrides
     */
    #[DataProvider('workflowCapabilities')]
    public function test_declared_capability_matrix_admits_only_its_exact_supported_workflow(DriverCapability $feature, array $detailOverrides, array $snapshotOverrides): void
    {
        $supported = self::snapshot(array_replace($snapshotOverrides, ['details' => self::details($detailOverrides)]));
        $supported->requireCapability($feature, false);
        self::assertSame(QualificationMode::Simulated, $supported->qualificationMode);
        $this->expectException(ProblemException::class);
        $supported->requireCapability($feature, true);
    }

    /** @return iterable<string, array{DriverCapability}> */
    public static function unsupportedWorkflows(): iterable
    {
        foreach (DriverCapability::cases() as $feature) {
            if (! in_array($feature, [DriverCapability::InitiateWithIdempotency, DriverCapability::LookupByProviderId, DriverCapability::LookupByReference, DriverCapability::AsynchronousStates], true)) {
                yield $feature->value => [$feature];
            }
        }
    }

    #[DataProvider('unsupportedWorkflows')]
    public function test_unsupported_workflow_returns_the_registered_deterministic_refusal(DriverCapability $feature): void
    {
        $continuations = 0;
        try {
            self::snapshot()->requireCapability($feature, false);
            $continuations++;
            self::fail('Unsupported workflow reached continuation');
        } catch (ProblemException $exception) {
            self::assertSame(ProblemCode::CapabilityUnsupported, $exception->problem);
        }
        self::assertSame(0, $continuations);
    }

    public function test_qualification_manifest_retains_unresolved_ru_provider_selection_gates(): void
    {
        $manifest = new AdapterQualificationManifest('fixture-bank-a-manifest', 'fixture-bank-a-sbp', 'bank-a-exact-v1', 'fixture-provider-api.v1', str_repeat('a', 64), 'fixture-bank-a-account', 'test', [], new DateTimeImmutable('2026-11-17T00:00:00Z'), 'same-account authenticated continuity required', ['fixture-only-provider-journal'], ['No production adapter selected', 'Official provider sources and live sandbox evidence not supplied', 'Callback signing and credential rotation not qualified'], QualificationMode::Simulated, 'fixture-qualification-a');
        self::assertSame(QualificationMode::Simulated, $manifest->mode);
        self::assertCount(3, $manifest->unresolvedGaps);
        self::assertSame([], $manifest->officialSources);
    }

    /** @return iterable<string, array{SnapshotOverrides}> */
    public static function inconsistentSnapshots(): iterable
    {
        yield 'empty capability version' => [['capabilityVersion' => '']];
        yield 'keyed currencies' => [['currencies' => [1 => 'RUB']]];
        yield 'no currencies' => [['currencies' => []]];
        yield 'lowercase currency' => [['currencies' => ['rub']]];
        yield 'partial refund without refund effect' => [['partialRefund' => true]];
        yield 'partial void without authorization effect' => [['partialVoidAuthorization' => true]];
        yield 'partial capture flag without a partial capture mode' => [['partialCapture' => true]];
        yield 'capture effect without a capture mode' => [['supportedEffects' => [EffectKind::Charge, EffectKind::Capture]]];
        yield 'absence certification without complete inventory' => [['absenceCertification' => true]];
    }

    /** @param SnapshotOverrides $overrides */
    #[DataProvider('inconsistentSnapshots')]
    public function test_an_incomplete_or_inconsistent_snapshot_is_refused(array $overrides): void
    {
        try {
            self::snapshot($overrides);
            self::fail('inconsistent capability snapshot');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::ValidationInvalid, $e->problem);
        }
    }

    public function test_malformed_capability_lists_refuse_at_the_boundary(): void
    {
        $this->expectException(ProblemException::class);
        self::snapshot(['supportedEffects' => ['charge']]);
    }

    /** @return iterable<string, array{ProblemCode, string}> */
    public static function newExecutionProblems(): iterable
    {
        yield 'unsupported' => [ProblemCode::CapabilityUnsupported, 'billium.capability_unsupported'];
        yield 'capture-capacity' => [ProblemCode::CaptureCapacityExceeded, 'billium.capture_capacity_exceeded'];
        yield 'authorization-capacity' => [ProblemCode::AuthorizationCapacityExceeded, 'billium.authorization_capacity_exceeded'];
        yield 'financial-hold' => [ProblemCode::OperationFinancialHold, 'billium.operation_financial_hold'];
        yield 'lookup-ambiguous' => [ProblemCode::LookupAmbiguous, 'billium.lookup_ambiguous'];
    }

    #[DataProvider('newExecutionProblems')]
    public function test_new_execution_problems_render_through_the_registered_error_descriptor(ProblemCode $problem, string $errorCode): void
    {
        $descriptor = (new ProblemException($problem))->errorDescriptor();
        self::assertSame($errorCode, $descriptor->code);
        self::assertFalse($descriptor->retryable);
    }

    public function test_unsupported_capability_has_explicit_no_receipt_and_same_key_policy(): void
    {
        $path = dirname(__DIR__, 6).'/spec/revisions/2026-10/problem-codes.json';
        $json = file_get_contents($path);
        self::assertIsString($json);
        $entries = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($entries);
        $matching = array_values(array_filter($entries, static fn (mixed $entry): bool => is_array($entry) && ($entry['code'] ?? null) === ProblemCode::CapabilityUnsupported->value));
        self::assertCount(1, $matching);
        self::assertArrayHasKey('retryable', $matching[0]);
        self::assertArrayHasKey('sameKey', $matching[0]);
        self::assertArrayHasKey('receipt', $matching[0]);
        self::assertFalse($matching[0]['retryable']);
        self::assertTrue($matching[0]['sameKey']);
        self::assertSame('none', $matching[0]['receipt']);
    }
}
