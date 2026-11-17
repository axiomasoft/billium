<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Feature;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Execution\Contracts\EvidenceReducer;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\PaymentDrivers;
use Billium\Execution\Data\ProviderWorkClaim;
use Billium\Execution\Enums\ReductionDisposition;
use Billium\Execution\Tests\Concurrency\AdmissionIdentityTest as Fixture;
use Billium\Execution\Tests\Drill\UnknownResultTest;
use Billium\Platform\Data\Installation\PlatformReceipt;
use Billium\Testkit\Drivers\SimulatedCapabilities;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
#[Group('ACC-CON-16')]
final class PoisonProviderWorkTest extends ExecutionTestCase
{
    public function test_unsupported_work_parks_with_error_then_new_handler_reprocesses_same_evidence_once(): void
    {
        $path = Fixture::tempPath();
        try {
            $app = $this->application();
            $event = CallbackCustodyTest::dispatched($app, $path);
            $store = $app->make(ExecutionStore::class);
            $reducer = $app->make(EvidenceReducer::class);
            $spec = $this->spec(kind: 'execution.operator_work', mode: FinancialMode::RawCustody, class: ModuleOperationClass::CallbackCustody, modules: ['execution', 'platform']);
            $enqueue = function (string $ref, string $version) use ($store, $spec, $event): void {
                $this->runner()->run($spec, static function (TransactionContext $ctx) use ($store, $event, $ref, $version): PlatformReceipt {
                    $store->enqueueProviderWork($ctx, $event->evidenceRef, $ref, $version, 'root-a');

                    return self::receipt();
                })->orThrow();
            };
            $enqueue('unsupported-work', 'historical-unknown.v0');
            self::assertSame(ReductionDisposition::Quarantined, $reducer->reduce('unsupported-work')->disposition);
            $parked = $store->providerWork('unsupported-work');
            self::assertNotNull($parked);
            self::assertSame(['parked', 'unsupported_handler_version', 'historical-unknown.v0'], [$parked->state, $parked->errorCode, $parked->handlerVersion]);
            $reducer->reduce('unsupported-work');
            self::assertSame(1, $this->rows('billium_execution_provider_work', "work_ref='unsupported-work' AND attempt_count=1 AND last_error_at IS NOT NULL AND result_ref IS NOT NULL"));
            $enqueue('replacement-work', 'execution.provider.v1');
            self::assertSame(ReductionDisposition::Applied, $reducer->reduce('replacement-work')->disposition);
            self::assertSame(ReductionDisposition::Duplicate, $reducer->reduce(CallbackCustodyTest::work($event))->disposition);
            self::assertSame(1, $this->rows('billium_execution_operation_revision'));
            self::assertSame(1, $this->rows('billium_execution_observation_emission'));
            self::assertSame(1, $this->rows('billium_execution_provider_evidence', 'evidence_ref=?', [$event->evidenceRef]));
        } finally {
            UnknownResultTest::clean($path);
        }
    }

    public function test_expired_claim_cannot_finish_its_successor_or_apply_a_directly_supplied_snapshot(): void
    {
        $path = Fixture::tempPath();
        try {
            $app = $this->application();
            $event = CallbackCustodyTest::dispatched($app, $path);
            $work = CallbackCustodyTest::work($event);
            $store = $app->make(ExecutionStore::class);
            $raw = $this->spec(kind: 'execution.work_claim', mode: FinancialMode::RawCustody, class: ModuleOperationClass::CallbackCustody, modules: ['execution', 'platform']);
            $one = null;
            $this->runner()->run($raw, function (TransactionContext $ctx) use ($work, $store, &$one): PlatformReceipt {
                $one = $store->claimProviderWork($ctx, $work, 'dead-worker', new DateTimeImmutable('+1 minute'));

                return self::receipt();
            })->orThrow();
            self::assertInstanceOf(ProviderWorkClaim::class, $one);
            $driver = $app->make(PaymentDrivers::class)->resolve('simulated-driver', $event->handlerContractVersion);
            $snapshot = $driver->normalize($event, SimulatedCapabilities::snapshot());
            $reducer = $app->make(EvidenceReducer::class);
            try {
                $this->runner()->run($this->spec(kind: 'execution.forged_reduction', class: ModuleOperationClass::ObservedFactRecording, modules: ['execution', 'platform']), static function (TransactionContext $ctx) use ($one, $snapshot, $reducer): PlatformReceipt {
                    $reducer->reduceIn($ctx, $one, $snapshot);

                    return self::receipt();
                });
                self::fail('Direct snapshot with no verified invocation was accepted');
            } catch (ProblemException $failure) {
                self::assertSame(ProblemCode::AuthDenied, $failure->problem);
            }
            self::assertSame(0, $this->rows('billium_execution_operation_revision'));
            $this->owner()->table('billium_execution_provider_work')->where('work_ref', $work)->update(['lease_expires_at' => new DateTimeImmutable('-1 second')]);
            $this->runner()->run($raw, function (TransactionContext $ctx) use ($work, $store, $one): PlatformReceipt {
                $two = $store->claimProviderWork($ctx, $work, 'successor', new DateTimeImmutable('+1 minute'));
                self::assertNotNull($two);
                self::assertGreaterThan($one->leaseFence, $two->leaseFence);
                self::assertFalse($store->finishProviderWork($ctx, $one, 'processed', 'forged-result', null, null));
                self::assertTrue($store->finishProviderWork($ctx, $two, 'due', null, 'retry', new DateTimeImmutable('+1 minute')));

                return self::receipt();
            })->orThrow();
            $this->owner()->table('billium_execution_provider_work')->where('work_ref', $work)->update(['due_at' => new DateTimeImmutable('-1 second')]);
            self::assertSame(ReductionDisposition::Applied, $app->make(EvidenceReducer::class)->reduce($work)->disposition);
            self::assertSame(1, $this->rows('billium_execution_provider_work', "state='processed' AND attempt_count=3 AND result_ref<>'forged-result'"));
        } finally {
            UnknownResultTest::clean($path);
        }
    }
}
