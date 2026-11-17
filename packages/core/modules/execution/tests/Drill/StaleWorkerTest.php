<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Drill;

use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Execution\Contracts\AttemptWorker;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\MerchantRegistry;
use Billium\Execution\Contracts\PaymentDrivers;
use Billium\Execution\Internal\Merchants\ContinuityMerchantCredentials;
use Billium\Execution\Tests\Concurrency\AdmissionIdentityTest as Fixture;
use Billium\Platform\Data\Installation\PlatformReceipt;
use Billium\Testkit\Fixtures\DurableJournal;
use Billium\Testkit\Fixtures\JournalEvent;
use Billium\Testkit\Fixtures\ProcessBarrier;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
#[Group('ACC-CON-06')]
final class StaleWorkerTest extends ExecutionTestCase
{
    public function test_raw_signed_provider_delivery_committed_before_response_is_retained_once_and_reuses_the_original_work(): void
    {
        $app = $this->application();
        Fixture::seedMerchant($app);
        $path = Fixture::tempPath();
        Fixture::host($app, $path);
        $child = UnknownResultTest::start($path, 'after-accept');
        $control = new DurableJournal($path.'.control');
        $barrier = new ProcessBarrier($control);
        try {
            $barrier->await('at-boundary', 1, 15_000);
            $merchant = $app->make(MerchantRegistry::class)->historical(Fixture::scope(), 'root-a');
            $drivers = $app->make(PaymentDrivers::class);
            $driver = $drivers->resolve($merchant->driverId, $merchant->driverContractVersion);
            $credentials = $app->make(ContinuityMerchantCredentials::class)->resolve($merchant, 'lookup');
            $page = $driver->inventory($merchant, $credentials, new DateTimeImmutable('-1 minute'), null);
            self::assertCount(1, $page->evidence);
            $event = $page->evidence[0];
            $verified = $driver->normalize($event, $drivers->capabilities($merchant));
            self::assertSame('root-a', $verified->operationRef);
            self::assertSame('account-a', $verified->merchantAccountRef);
            $spec = $this->spec(kind: 'execution.fixture_signed_delivery', mode: FinancialMode::RawCustody,
                class: ModuleOperationClass::CallbackCustody, modules: ['execution', 'platform']);
            $store = $app->make(ExecutionStore::class);
            $this->runner()->run($spec, static function (TransactionContext $ctx) use ($event, $store): PlatformReceipt {
                $ref = $store->appendEvidence($ctx, $event);
                $store->enqueueProviderWork($ctx, $ref, 'independent-delivery-work', $event->handlerContractVersion);

                return self::receipt();
            })->orThrow();
            self::assertSame(1, $this->rows('billium_execution_attempt', "state='dispatching'"));
            self::assertSame(1, $this->rows('billium_execution_provider_evidence'));
            $barrier->arrive('resume');
            self::assertSame(0, $child->wait());
            self::assertSame(1, $this->rows('billium_execution_provider_evidence', "signature_verdict='simulated-valid'"));
            self::assertSame(1, $this->rows('billium_execution_provider_evidence', "signature_verdict='local_transport'"));
            self::assertSame(1, $this->rows('billium_execution_provider_work'));
            self::assertSame('independent-delivery-work', $this->value('SELECT work_ref FROM billium_execution_provider_work'));
            self::assertSame(1, $this->rows('billium_execution_attempt', "state='pending'"));
            self::assertSame(1, UnknownResultTest::events($path, 'provider.call'));
        } finally {
            UnknownResultTest::clean($path);
        }
    }

    public function test_a_delayed_original_response_is_retained_after_a_new_workers_lookup(): void
    {
        $app = $this->application();
        Fixture::seedMerchant($app);
        $path = Fixture::tempPath();
        Fixture::host($app, $path);
        try {
            $child = UnknownResultTest::start($path, 'after-accept');
            $control = new DurableJournal($path.'.control');
            $barrier = new ProcessBarrier($control);
            $barrier->await('at-boundary', 1, 15_000);
            UnknownResultTest::awaitLease($app->make(ExecutionStore::class));
            $recovery = $app->make(AttemptWorker::class)->recover(Fixture::scope(), UnknownResultTest::attemptRef(), 'new-owner', new DateTimeImmutable('+1 minute'));
            self::assertSame('pending', $recovery->body['status']);
            $before = $this->row('SELECT state,lease_fence,finish_evidence_ref,provider_reference FROM billium_execution_attempt');
            $barrier->arrive('resume');
            self::assertSame(0, $child->wait());
            self::assertSame($before, $this->row('SELECT state,lease_fence,finish_evidence_ref,provider_reference FROM billium_execution_attempt'));
            $finish = array_find($control->records(), static fn (object $r): bool => $r instanceof JournalEvent && $r->kind === 'finish');
            self::assertInstanceOf(JournalEvent::class, $finish);
            self::assertSame('retained_stale', $finish->data['status']);
            self::assertSame(2, $this->rows('billium_execution_provider_evidence', "signature_verdict='local_transport'"));
            self::assertSame(1, UnknownResultTest::events($path, 'provider.call'));
            self::assertSame(1, UnknownResultTest::events($path, 'provider.lookup'));
            self::assertSame(2, self::integer($before['lease_fence']));
            self::assertSame(1, $this->rows('billium_execution_attempt'));
        } finally {
            UnknownResultTest::clean($path);
        }
    }
}
