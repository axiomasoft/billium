<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Feature;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Execution\Contracts\AdapterQualification;
use Billium\Execution\Contracts\AttemptWorker;
use Billium\Execution\Contracts\CallbackCustody;
use Billium\Execution\Contracts\CallbackPayloadArchive;
use Billium\Execution\Contracts\EvidenceReducer;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\MerchantRegistry;
use Billium\Execution\Contracts\PaymentDrivers;
use Billium\Execution\Contracts\RootPaymentCommands;
use Billium\Execution\Data\CapabilitySnapshot;
use Billium\Execution\Data\MerchantRevision;
use Billium\Execution\Data\MerchantRevisionPolicy;
use Billium\Execution\Data\ProviderEvidence;
use Billium\Execution\Data\SecretRef;
use Billium\Execution\Enums\ReductionDisposition;
use Billium\Execution\Internal\Identity\PaymentIdentity;
use Billium\Execution\Internal\Merchants\ContinuityMerchantCredentials;
use Billium\Execution\Internal\Merchants\DatabaseMerchantRegistry;
use Billium\Execution\Tests\Concurrency\AdmissionIdentityTest as Fixture;
use Billium\Execution\Tests\Drill\UnknownResultTest;
use Billium\Platform\Data\Installation\PlatformReceipt;
use Billium\Testkit\Drivers\ResponseMode;
use Billium\Testkit\Drivers\SimulatedCapabilities;
use Billium\Testkit\Fixtures\DurableJournal;
use DateTimeImmutable;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

#[Group('pg')]
#[Group('PF-05')]
#[Group('ACC-CON-23')]
final class CallbackCustodyTest extends ExecutionTestCase
{
    public static function dispatched(Application $app, string $journal, ResponseMode $mode = ResponseMode::Success, ?CapabilitySnapshot $cap = null): ProviderEvidence
    {
        Fixture::seedMerchant($app, $cap);
        Fixture::host($app, $journal, cap: $cap, mode: $mode);
        $app->make(RootPaymentCommands::class)->admit(Fixture::scope(), Fixture::wire())->orThrow();
        $app->make(AttemptWorker::class)->dispatch(Fixture::scope(), UnknownResultTest::attemptRef(), 'initial-worker', new DateTimeImmutable('+1 minute'))->orThrow();

        return self::providerInput($app);
    }

    public static function providerInput(Application $app, string $operation = 'root-a'): ProviderEvidence
    {
        $merchant = $app->make(MerchantRegistry::class)->historical(Fixture::scope(), $operation);
        $driver = $app->make(PaymentDrivers::class)->resolve($merchant->driverId, $merchant->driverContractVersion);
        $credentials = $app->make(ContinuityMerchantCredentials::class)->resolve($merchant, 'lookup');
        $page = $driver->inventory($merchant, $credentials, new DateTimeImmutable('-5 minutes'), null);
        self::assertNotEmpty($page->evidence);

        return $page->evidence[0];
    }

    public static function work(ProviderEvidence $delivery): string
    {
        return 'provider-work:'.substr(PaymentIdentity::digest(['scope' => get_object_vars($delivery->scope), 'handler' => $delivery->handlerContractVersion,
            'evidenceRef' => $delivery->evidenceRef]), 7);
    }

    /** Independently signed provider test input. The signer and key are simulated, outside the execution database.
     * @param  array<string,mixed>  $changes
     */
    public static function signed(ProviderEvidence $original, array $changes): ProviderEvidence
    {
        $material = $original->sanitizedPayload['material'];
        self::assertIsArray($material);
        $material = array_replace($material, $changes);
        $canonical = (new Codec)->canonicalize($material);
        $digest = hash('sha256', $canonical);

        return new ProviderEvidence('evidence:'.$digest, $original->scope, $original->providerId, $original->merchantAccountRef,
            'simulated-event:'.self::text($material['stableEffectId']).':'.self::text($material['revision']), $digest, $original->signatureVerdict,
            $original->handlerContractVersion, new DateTimeImmutable, ['material' => $material, 'fixtureSignature' => hash_hmac('sha256', $canonical, 'simulated-public-fixture-key-not-production')], $original->securePayloadRef);
    }

    #[DataProvider('custodyFrames')]
    public function test_repaired_verifier_reprocesses_immutable_safe_or_independently_archived_frame(bool $secret): void
    {
        $journal = Fixture::tempPath();
        $archiveFile = $journal.'.frames';
        try {
            $app = $this->application();
            $source = self::dispatched($app, $journal);
            $event = self::signed($source, ['revision' => '3']);
            if ($secret) {
                $event = new ProviderEvidence($event->evidenceRef, $event->scope, $event->providerId, $event->merchantAccountRef, $event->providerEventId, $event->payloadDigest, $event->signatureVerdict, $event->handlerContractVersion, $event->receivedAt, [...$event->sanitizedPayload, 'password' => 'fixture-sensitive', 'api_key' => 'not-inline-api-key', 'note' => '4111111111111111', 'cvv2' => 'not-inline-cvv2'], $event->securePayloadRef);
            }
            $original = $app->make(AdapterQualification::class);
            $verifier = $this->createMock(AdapterQualification::class);
            $verifier->method('verify')->willThrowException(new RuntimeException('Temporarily unavailable verification configuration'));
            $app->instance(AdapterQualification::class, $verifier);
            $vault = $this->createMock(CallbackPayloadArchive::class);
            $vault->expects($secret ? self::once() : self::never())->method('retain')->willReturnCallback(function ($scope, $revision, $value) use ($archiveFile): string {
                self::assertSame(0, $this->owner()->transactionLevel());
                self::assertEquals(Fixture::scope(), $scope);
                self::assertSame('merchant-a', $revision);
                self::assertInstanceOf(ProviderEvidence::class, $value);
                new DurableJournal($archiveFile)->append($value);

                return 'fixture-archive:1';
            });
            $vault->expects($secret ? self::once() : self::never())->method('read')->willReturnCallback(function ($scope, $revision, $ref) use ($archiveFile): ProviderEvidence {
                self::assertSame(0, $this->owner()->transactionLevel());
                self::assertEquals(Fixture::scope(), $scope);
                self::assertSame(['merchant-a', 'fixture-archive:1'], [$revision, $ref]);
                $value = new DurableJournal($archiveFile)->records()[0];
                self::assertInstanceOf(ProviderEvidence::class, $value);

                return $value;
            });
            $app->instance(CallbackPayloadArchive::class, $vault);
            try {
                $app->make(CallbackCustody::class)->receive(Fixture::scope(), 'merchant-a', 'root-a', $event);
                self::fail('Expected durable rejection');
            } catch (ProblemException $failure) {
                self::assertSame(ProblemCode::AuthDenied, $failure->problem);
            }
            $ref = self::text($this->value("SELECT evidence_ref FROM billium_execution_provider_work WHERE state='quarantined'"));
            self::assertSame(0, $this->rows('billium_execution_provider_evidence', "sanitized_payload::text LIKE '%fixture-sensitive%'"));
            self::assertSame(0, $this->rows('billium_execution_provider_evidence', "sanitized_payload::text LIKE '%not-inline-api-key%'"));
            self::assertSame(0, $this->rows('billium_execution_provider_evidence', "sanitized_payload::text LIKE '%4111111111111111%'"));
            self::assertSame(0, $this->rows('billium_execution_provider_evidence', "sanitized_payload::text LIKE '%not-inline-cvv2%'"));
            self::assertSame(1, $this->rows('billium_execution_provider_evidence', $secret ? "secure_payload_ref='fixture-archive:1' AND sanitized_payload->>'archived'='true'" : "sanitized_payload->'original' IS NOT NULL"));
            $app->instance(AdapterQualification::class, $original);
            $spec = $this->spec(kind: 'execution.upgrade_provider_work', mode: FinancialMode::RawCustody, class: ModuleOperationClass::CallbackCustody, modules: ['execution', 'platform']);
            $this->runner()->run($spec, static function (TransactionContext $ctx) use ($app, $ref): PlatformReceipt {
                $app->make(ExecutionStore::class)->enqueueProviderWork($ctx, $ref, 'reverified-frame', 'execution.provider.v1', 'root-a');

                return self::receipt();
            })->orThrow();
            self::assertSame(ReductionDisposition::Applied, $app->make(EvidenceReducer::class)->reduce('reverified-frame')->disposition);
            self::assertSame(1, $this->rows('billium_execution_provider_fact'));
            self::assertSame(1, $this->rows('billium_execution_capacity', 'confirmed_capture_minor=100'));
            self::assertSame(1, UnknownResultTest::events($journal, 'provider.call'));
        } finally {
            UnknownResultTest::clean($journal);
            if (is_file($archiveFile)) {
                unlink($archiveFile);
            }
        }
    }

    public function test_distinct_merchants_can_deliver_the_same_provider_event_and_credential_rotation_preserves_the_original_effect(): void
    {
        $pathA = Fixture::tempPath();
        $pathB = Fixture::tempPath();
        try {
            $app = $this->application();
            $a = self::dispatched($app, $pathA);
            $scope = Fixture::scope();
            $store = $app->make(ExecutionStore::class);
            $original = $store->merchantRevision($scope, 'merchant-a');
            $capA = SimulatedCapabilities::snapshot();
            $capB = SimulatedCapabilities::snapshot('account-b');
            $spec = $this->spec(kind: 'execution.fixture_merchant', mode: FinancialMode::Administrative, class: ModuleOperationClass::HistoricalRead, modules: ['execution', 'platform']);
            foreach (['rotated-a' => $capA, 'merchant-b' => $capB] as $revision => $capability) {
                $predecessor = $revision === 'rotated-a' ? 'merchant-a' : null;
                $policy = $original->policy;
                $merchant = new MerchantRevision($revision, $scope, $capability->merchantAccountRef, $original->providerId,
                    $capability->driverId, $capability->driverContractVersion, $capability->snapshotRef, $capability->digest, new SecretRef('secret://fixture/'.$revision),
                    'credential-'.$revision, $capability->currencies, hash('sha256', $revision), $original->createdAt,
                    new MerchantRevisionPolicy($policy->validFrom, null, $predecessor, $policy->apiProfileVersion, 'webhook-'.$revision, 'continuity-'.$revision,
                        $policy->settlementAccountRef, $policy->mandateNamespaceRef));
                $this->runner()->run($spec, static function (TransactionContext $ctx) use ($app, $store, $merchant, $capability, $scope, $revision): PlatformReceipt {
                    $store->appendMerchantRevision($ctx, $merchant, $capability);
                    $registry = $app->make(DatabaseMerchantRegistry::class);
                    foreach (['new', 'lookup'] as $purpose) {
                        $registry->appendLifecycle($ctx, $scope, $revision, $revision.'-enable-'.$purpose, $purpose === 'new' ? 'enable_new' : 'enable_recovery', $purpose, new DateTimeImmutable('-1 day'), $purpose === 'new' ? null : new DateTimeImmutable('+2 days'), 'simulated merchant policy', 'approval-'.$revision.'-'.$purpose);
                        $registry->appendCredential($ctx, $merchant, $revision.'-cred-'.$purpose, $merchant->credentialVersionRef, $merchant->credentialRef, $purpose, new DateTimeImmutable('-1 day'), new DateTimeImmutable('+2 days'), 'proof-'.$purpose, null);
                    }

                    return self::receipt();
                })->orThrow();
            }
            $intake = $app->make(CallbackCustody::class);
            $first = $intake->receive($scope, 'merchant-a', 'root-a', $a)->orThrow();
            $rotated = $intake->receive($scope, 'rotated-a', 'root-a', $a)->orThrow();
            self::assertSame($first->body['commandKey'], $rotated->body['commandKey']);
            self::assertSame(ReductionDisposition::Applied, $app->make(EvidenceReducer::class)->reduce(self::work($a))->disposition);
            Fixture::host($app, $pathB, [Fixture::origin(context: 'support', operation: 'root-b')], $capB);
            $app->make(RootPaymentCommands::class)->admit($scope, Fixture::wire(['commandKey' => Fixture::key(2), 'operationRef' => 'root-b', 'source' => ['context' => 'support', 'intentRef' => 'intent-a'], 'merchantAccountRef' => 'account-b', 'bindingRevision' => 'merchant-b']))->orThrow();
            $attempt = PaymentIdentity::reference($scope, 'root-b', 'attempt');
            $app->make(AttemptWorker::class)->dispatch($scope, $attempt, 'worker-b', new DateTimeImmutable('+1 minute'))->orThrow();
            $b = self::providerInput($app, 'root-b');
            self::assertSame($a->providerEventId, $b->providerEventId);
            self::assertNotSame($a->evidenceRef, $b->evidenceRef);
            $intake = $app->make(CallbackCustody::class);
            try {
                $intake->receive($scope, 'merchant-b', 'root-b', $a);
                self::fail('Mismatched authenticated merchant must refuse after custody');
            } catch (ProblemException $failure) {
                self::assertSame(ProblemCode::AuthDenied, $failure->problem);
            }
            $receipt = $intake->receive($scope, 'merchant-b', 'root-b', $b)->orThrow();
            self::assertSame('custody_accepted', $receipt->body['status']);
            self::assertSame(ReductionDisposition::Applied, $app->make(EvidenceReducer::class)->reduce(self::work($b))->disposition);
            self::assertSame(2, $this->rows('billium_execution_provider_fact'));
            self::assertSame(2, $this->rows('billium_execution_capacity', 'confirmed_capture_minor=100'));
            self::assertSame('merchant-a', $store->operation($scope, 'root-a')?->binding->merchantRevisionRef);
            self::assertSame('merchant-b', $store->operation($scope, 'root-b')?->binding->merchantRevisionRef);
            self::assertSame(1, UnknownResultTest::events($pathA, 'provider.call'));
            self::assertSame(1, UnknownResultTest::events($pathB, 'provider.call'));
        } finally {
            UnknownResultTest::clean($pathA);
            UnknownResultTest::clean($pathB);
        }
    }

    /** @return list<array{bool}> */
    public static function custodyFrames(): array
    {
        return [[false], [true]];
    }

    #[Group('PB-17')]
    public function test_durable_signed_and_unmatched_custody_precedes_ack_and_rejections_never_create_or_ack_a_financial_effect(): void
    {
        $journal = Fixture::tempPath();
        try {
            $app = $this->application();
            $event = self::dispatched($app, $journal);
            $route = $app->make(CallbackCustody::class);
            $receipt = $route->receive(Fixture::scope(), 'merchant-a', 'root-a', $event)->orThrow();
            self::assertSame('custody_accepted', $receipt->body['status']);
            self::assertSame(self::work($event), $receipt->body['commandKey']);
            $record = $app->make(ExecutionStore::class)->providerWork(self::work($event));
            self::assertNotNull($record);
            self::assertSame([$event->providerId, $event->merchantAccountRef, $event->providerEventId, $event->signatureVerdict, $event->payloadDigest],
                [$record->evidence->providerId, $record->evidence->merchantAccountRef, $record->evidence->providerEventId, $record->evidence->signatureVerdict, $record->evidence->payloadDigest]);
            self::assertEquals($event->scope, $record->evidence->scope);
            self::assertSame($event->receivedAt->getTimestamp(), $record->evidence->receivedAt->getTimestamp());
            self::assertSame(0, $this->rows('billium_execution_provider_fact'));
            $bad = new ProviderEvidence($event->evidenceRef, $event->scope, $event->providerId, $event->merchantAccountRef, $event->providerEventId, $event->payloadDigest, $event->signatureVerdict, $event->handlerContractVersion, $event->receivedAt, ['material' => $event->sanitizedPayload['material'], 'fixtureSignature' => 'forged', 'password' => 'never-retained'], $event->securePayloadRef);
            try {
                $route->receive(Fixture::scope(), 'merchant-a', 'root-a', $bad);
                self::fail('Expected callback authentication refusal');
            } catch (ProblemException $failure) {
                self::assertSame(ProblemCode::AuthDenied, $failure->problem);
            }
            self::assertSame(0, $this->rows('billium_execution_provider_evidence', "sanitized_payload::text LIKE '%never-retained%'"));
            self::assertSame(1, $this->rows('billium_execution_provider_work', "state='quarantined'"));
            $unmatched = self::signed($event, ['operationRef' => 'unknown-at-provider', 'revision' => '50']);
            $retained = $route->receive(Fixture::scope(), 'merchant-a', null, $unmatched)->orThrow();
            self::assertSame('custody_quarantined', $retained->body['status']);
            self::assertSame(1, $this->rows('billium_execution_operation'));
            $changed = self::signed($event, ['status' => 'declined']);
            self::assertSame('custody_quarantined', $route->receive(Fixture::scope(), 'merchant-a', null, $changed)->orThrow()->body['status']);
            self::assertSame(1, $this->rows('billium_execution_provider_evidence', 'evidence_ref=? AND payload_digest=?', [$event->evidenceRef, $event->payloadDigest]));
            self::assertSame(0, $this->rows('billium_execution_operation_revision'));
            self::assertSame(ReductionDisposition::Applied, $app->make(EvidenceReducer::class)->reduce(self::work($event))->disposition);
            self::assertSame(1, $this->rows('billium_execution_provider_fact'));
            self::assertSame(1, $this->rows('billium_execution_observation_emission'));
        } finally {
            UnknownResultTest::clean($journal);
        }
    }
}
