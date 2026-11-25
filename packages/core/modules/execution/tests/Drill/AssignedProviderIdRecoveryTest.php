<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Drill;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Execution\Contracts\AttemptWorker;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\PaymentDriver;
use Billium\Execution\Contracts\PaymentDrivers;
use Billium\Execution\Data\CapabilitySnapshot;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\LookupRequest;
use Billium\Execution\Data\MaterialSnapshot;
use Billium\Execution\Data\ProviderFact;
use Billium\Execution\Data\ProviderLookupResult;
use Billium\Execution\Enums\AttemptState;
use Billium\Execution\Internal\Storage\ExecutionCodec;
use Billium\Execution\Tests\Concurrency\AdmissionIdentityTest as Fixture;
use Billium\Execution\Tests\Feature\CallbackCustodyTest;
use Billium\Platform\Data\Installation\PlatformReceipt;
use Billium\Testkit\Drivers\ResponseMode;
use Billium\Testkit\Drivers\SimulatedCapabilities;
use Billium\Testkit\Fixtures\ChildProcess;
use Billium\Testkit\Fixtures\DurableJournal;
use Billium\Testkit\Fixtures\JournalEvent;
use Billium\Testkit\Fixtures\ProcessIdentity;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
final class AssignedProviderIdRecoveryTest extends ExecutionTestCase
{
    public static function capability(bool $reference = false, bool $byId = true): CapabilitySnapshot
    {
        $base = SimulatedCapabilities::snapshot(absence: false);
        $body = ExecutionCodec::map(ExecutionCodec::encode($base));
        $details = ExecutionCodec::map(ExecutionCodec::encode($base->details));
        $details['lookupByProviderId'] = $byId;
        $digest = (new Codec)->digest(['schema' => 'billium.testkit.qualified-lookups.v1', 'base' => $base->digest, 'byReference' => $reference, 'byId' => $byId]);

        return ExecutionCodec::decode(CapabilitySnapshot::class, ExecutionCodec::encode(array_replace($body, [
            'snapshotRef' => 'simulated:'.$digest, 'digest' => $digest, 'lookupByReference' => $reference, 'details' => $details,
        ])));
    }

    public static function restartedWorker(string $path, bool $reference, bool $byId): void
    {
        $app = Fixture::workerApp(811);
        Fixture::host($app, $path, cap: self::capability($reference, $byId));
        $outcome = $app->make(AttemptWorker::class)->recover(Fixture::scope(), UnknownResultTest::attemptRef(), 'after-restart', new DateTimeImmutable('+30 seconds'))->orThrow();
        new DurableJournal($path.'.control')->append(new JournalEvent('recovered', ['status' => self::text($outcome->body['status']),
            'backend' => self::integer($app->make(DatabaseManager::class)->connection()->scalar('SELECT pg_backend_pid()'))], ProcessIdentity::current()));
    }

    private function seedPendingFact(CapabilitySnapshot $capability, MaterialSnapshot $snapshot, DateTimeImmutable $due): void
    {
        $store = $this->application()->make(ExecutionStore::class);
        $operation = $store->operation(Fixture::scope(), $snapshot->operationRef) ?? throw new \LogicException('Original operation missing');
        $merchant = $store->merchantRevision(Fixture::scope(), $operation->binding->merchantRevisionRef);
        $identityScope = ExecutionCodec::providerIdentityScope($merchant, $capability);
        $this->runner()->run($this->spec(kind: 'execution.fixture_verified_pending', class: ModuleOperationClass::ObservedFactRecording, modules: ['platform', 'execution']),
            static function (TransactionContext $ctx) use ($store, $snapshot, $capability, $due, $identityScope): PlatformReceipt {
                $scope = Fixture::scope();
                $store->lock($ctx, $store->plan($scope, $snapshot->operationRef, context: $ctx));
                $attempt = $store->attemptForOperation($scope, $snapshot->operationRef, $ctx);
                self::assertNotNull($attempt);
                $fact = new ProviderFact('fixture-signed-pending', $snapshot->operationRef, $identityScope, 'original-pending-revision', $snapshot->materialDigest,
                    $capability->evidenceMode, $snapshot, $snapshot->evidenceRefs);
                self::assertTrue($store->appendFact($ctx, $fact));
                $store->applyAttemptFact($ctx, $attempt, $fact, AttemptState::Pending, $due);

                return self::receipt();
            })->orThrow();
    }

    private static function sleepUntilPast(DateTimeImmutable $due): void
    {
        $remaining = (float) $due->format('U.u') - microtime(true) + 0.1;
        if ($remaining > 0) {
            usleep((int) round($remaining * 1_000_000));
        }
    }

    /** @return list<array{bool,bool}> */
    public static function selections(): array
    {
        return [[false, true], [true, true], [true, false], [false, false]];
    }

    #[DataProvider('selections')]
    public function test_only_persisted_verified_id_selects_the_original_provider_operation_across_restart(bool $reference, bool $byId): void
    {
        $app = $this->application();
        $path = Fixture::tempPath();
        try {
            $capability = self::capability($reference, $byId);
            $event = CallbackCustodyTest::dispatched($app, $path, ResponseMode::Pending, $capability);
            $driver = $app->make(PaymentDrivers::class)->resolve($capability->driverId, $capability->driverContractVersion);
            $snapshot = $driver->normalize($event, $capability);
            self::assertNotNull($snapshot->providerOperationId);
            self::assertNotNull($snapshot->stableEffectId);
            self::assertNotSame($snapshot->providerOperationId, $snapshot->stableEffectId);
            $store = $app->make(ExecutionStore::class);
            $scope = Fixture::scope();
            $attemptRef = UnknownResultTest::attemptRef();
            $original = $store->attempt($scope, $attemptRef);
            self::assertNotNull($original);
            self::assertSame($original->providerReference, $snapshot->providerReference);
            self::assertNull($store->providerOperationId($scope, $attemptRef));
            if (! $reference) {
                // Even the provider's genuine signed pending body is insufficient until accepted and durably bound.
                usleep(1_100_000);
                $result = $app->make(AttemptWorker::class)->recover($scope, $attemptRef, 'no-id', new DateTimeImmutable('+30 seconds'))->orThrow();
                self::assertSame('unknown', $result->body['status']);
                self::assertSame(1, $this->rows('billium_execution_dispatch_work', "state='parked' AND last_error_code='original_lookup_requires_manual_recovery'"));
                self::assertSame(0, UnknownResultTest::events($path, 'provider.lookup'));
            }
            // The early recovery below must run before the lookup is due; two seconds leave room for a
            // loaded runner (coverage, parallel workers) where one second was observed to run out.
            $due = new DateTimeImmutable('+2 seconds');
            $this->seedPendingFact($capability, $snapshot, $due);
            self::assertSame($snapshot->providerOperationId, $store->providerOperationId($scope, $attemptRef));
            self::assertSame('root-a', $store->operationByProviderId($scope, $event->providerId, $event->merchantAccountRef, $capability->details->lookupNamespace, $snapshot->providerOperationId)?->operationRef);
            self::assertNull($store->operationByProviderId($scope, $event->providerId, 'other-account', $capability->details->lookupNamespace, $snapshot->providerOperationId));
            self::assertNull($store->operationByProviderId($scope, $event->providerId, $event->merchantAccountRef, 'other-namespace', $snapshot->providerOperationId));
            self::assertNull($store->operationByProviderId($scope, $event->providerId, $event->merchantAccountRef, $capability->details->lookupNamespace, $snapshot->stableEffectId));
            try {
                $app->make(AttemptWorker::class)->recover($scope, $attemptRef, 'early', new DateTimeImmutable('+30 seconds'));
                self::fail('Future lookup work was acquired early');
            } catch (ProblemException $exception) {
                self::assertSame(ProblemCode::RecoveryRequired, $exception->problem);
            }
            self::sleepUntilPast($due);
            $child = new ChildProcess([PHP_BINARY, '-r', 'require "vendor/autoload.php"; Billium\\Execution\\Tests\\Drill\\AssignedProviderIdRecoveryTest::restartedWorker($argv[1],$argv[2]==="ref",$argv[3]==="id");',
                $path, $reference ? 'ref' : 'no-ref', $byId ? 'id' : 'no-id']);
            self::assertSame(0, $child->wait());
            $finish = new DurableJournal($path.'.control')->records()[0] ?? null;
            self::assertInstanceOf(JournalEvent::class, $finish);
            self::assertSame($child->pid, $finish->pid);
            self::assertNotEquals($this->value('SELECT pg_backend_pid()'), $finish->data['backend']);
            $canLookup = $reference || $byId;
            self::assertSame($canLookup ? 'pending' : 'unknown', $finish->data['status']);
            self::assertSame(1, UnknownResultTest::events($path, 'provider.call'));
            self::assertSame($canLookup ? 1 : 0, UnknownResultTest::events($path, 'provider.lookup'));
            if ($canLookup) {
                $lookup = array_find(new DurableJournal($path)->records(), static fn (object $entry): bool => $entry instanceof JournalEvent && $entry->kind === 'provider.lookup');
                self::assertInstanceOf(JournalEvent::class, $lookup);
                self::assertSame($byId ? $snapshot->providerOperationId : null, $lookup->data['providerOperationId'] ?? null);
                self::assertSame($original->providerReference, $lookup->data['reference'] ?? null);
                self::assertSame('account-a', $lookup->data['account'] ?? null);
            }
            self::assertSame(1, $this->rows('billium_execution_attempt'));
            self::assertSame($original->immutableRequestDigest, $store->attempt($scope, $attemptRef)?->immutableRequestDigest);
            self::assertSame(1, $this->rows('billium_execution_dispatch_work', $canLookup ? "state='due'" : "state='parked'"));
            $other = new ExecutionScope($scope->installationRef, $scope->sellerRef, $scope->environment, 'source-b');
            $this->callers->set(new Caller($scope->sellerRef, $scope->environment, $scope->installationRef, 'other-owner', ['remote-billium'], ['source-b']));
            self::assertNull($store->providerOperationId($other, $attemptRef));
            self::assertNull($store->operationByProviderId($other, $event->providerId, $event->merchantAccountRef, $capability->details->lookupNamespace, $snapshot->providerOperationId));
        } finally {
            UnknownResultTest::clean($path);
        }
    }

    /** @return list<array{string}> */
    public static function mismatches(): array
    {
        return [['providerOperationId'], ['bindingDigest'], ['providerReference']];
    }

    #[DataProvider('mismatches')]
    public function test_by_id_lookup_retains_but_parks_material_with_a_substituted_identity(string $field): void
    {
        $app = $this->application();
        $path = Fixture::tempPath();
        try {
            $capability = self::capability();
            $event = CallbackCustodyTest::dispatched($app, $path, ResponseMode::Pending, $capability);
            $driver = $app->make(PaymentDrivers::class)->resolve($capability->driverId, $capability->driverContractVersion);
            $snapshot = $driver->normalize($event, $capability);
            self::assertNotNull($snapshot->providerOperationId);
            $this->seedPendingFact($capability, $snapshot, new DateTimeImmutable('+1 second'));
            $substituted = new MaterialSnapshot($snapshot->operationRef, $snapshot->kind, $snapshot->merchantAccountRef, $snapshot->currency,
                $snapshot->authorizedTotal, $snapshot->capturedTotal, $snapshot->refundedTotal, $snapshot->voidedTotal, $snapshot->normalizedStatus,
                $snapshot->providerRevision, $snapshot->stableEffectId, $snapshot->materialDigest, $snapshot->evidenceRefs, $snapshot->observedAt,
                $field === 'providerOperationId' ? 'foreign-id' : $snapshot->providerOperationId,
                $field === 'bindingDigest' ? 'foreign-binding' : $snapshot->bindingDigest,
                $field === 'providerReference' ? 'foreign-reference' : $snapshot->providerReference, $snapshot->operationAmount);
            $wrong = self::createStub(PaymentDriver::class);
            $wrong->method('contractVersion')->willReturn($driver->contractVersion());
            $wrong->method('lookup')->willReturnCallback(static function (LookupRequest $request) use ($driver, $snapshot, $substituted): ProviderLookupResult {
                self::assertSame($snapshot->providerOperationId, $request->providerOperationId);
                $original = $driver->lookup($request);

                return new ProviderLookupResult($original->state, $original->evidence, $substituted, null, null, null);
            });
            $drivers = self::createStub(PaymentDrivers::class);
            $drivers->method('resolve')->willReturn($wrong);
            $drivers->method('capabilities')->willReturn($capability);
            $app->instance(PaymentDrivers::class, $drivers);
            usleep(1_100_000);
            $result = $app->make(AttemptWorker::class)->recover(Fixture::scope(), UnknownResultTest::attemptRef(), 'recovery', new DateTimeImmutable('+30 seconds'))->orThrow();
            self::assertSame('unknown', $result->body['status']);
            self::assertSame(1, $this->rows('billium_execution_dispatch_work', "state='parked'"));
            self::assertSame(2, $this->rows('billium_execution_provider_evidence', "signature_verdict='local_transport'"));
            self::assertSame($snapshot->providerOperationId, $app->make(ExecutionStore::class)->providerOperationId(Fixture::scope(), UnknownResultTest::attemptRef()));
            self::assertSame(1, UnknownResultTest::events($path, 'provider.call'));
            self::assertSame(1, UnknownResultTest::events($path, 'provider.lookup'));
        } finally {
            UnknownResultTest::clean($path);
        }
    }
}
