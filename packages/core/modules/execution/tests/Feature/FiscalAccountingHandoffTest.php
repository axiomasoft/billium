<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Feature;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Envelopes\AuthorizePayment;
use Billium\Contracts\Data\Envelopes\CapturePayment;
use Billium\Contracts\Data\Envelopes\PaymentObservation;
use Billium\Contracts\Data\Envelopes\RequestRefund;
use Billium\Contracts\Data\Money;
use Billium\Core\Contracts\Modules\ModuleRegistry;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\EventPublisher;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Execution\Contracts\AttemptWorker;
use Billium\Execution\Contracts\AuthorizationCommands;
use Billium\Execution\Contracts\EvidenceReducer;
use Billium\Execution\Contracts\ExecutionHistoryQueries;
use Billium\Execution\Contracts\ExecutionQueries;
use Billium\Execution\Contracts\RefundCommands;
use Billium\Execution\Contracts\RootPaymentCommands;
use Billium\Execution\Data\ReductionResult;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Enums\ProviderEvidenceMode;
use Billium\Execution\Enums\ReductionDisposition;
use Billium\Execution\Internal\Identity\PaymentIdentity;
use Billium\Execution\Tests\Concurrency\AdmissionIdentityTest as Fixture;
use Billium\Execution\Tests\Concurrency\OutcomeDeliveryTest;
use Billium\Execution\Tests\Drill\UnknownResultTest;
use Billium\Ledger\Contracts\LedgerParticipants;
use Billium\Ledger\Contracts\LedgerQueries;
use Billium\Ledger\Contracts\ObservationCheckpointQueries;
use Billium\Ledger\Data\Obligations\RecordObligation;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Billium\Ledger\Data\Settlements\DeclareSettlementIntent;
use Billium\Platform\Contracts\Outbox\OutboxDeliveryQueries;
use Billium\Platform\Data\EventContracts\EventContractRegistration;
use Billium\Platform\Data\EventContracts\PublicEventDestinations;
use Billium\Platform\Data\Feed\FeedScope;
use Billium\Platform\Data\Outbox\OutboxDeliveryStatus;
use Billium\Platform\Internal\EventContracts\DatabaseEventContractRegistrar;
use Billium\Platform\Internal\Outbox\OutboxRelay;
use Billium\Testkit\Drivers\SimulatedCapabilities;
use Billium\Testkit\Fixtures\ChildProcess;
use Billium\Testkit\Fixtures\DurableJournal;
use Billium\Testkit\Fixtures\JournalEvent;
use Billium\Testkit\Fixtures\ProcessIdentity;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

#[Group('pg')]
final class FiscalAccountingHandoffTest extends ExecutionTestCase
{
    public function test_real_returned_capture_and_refund_publish_immutable_facts_and_independent_destination_states(): void
    {
        $path = Fixture::tempPath();
        try {
            $app = $this->application();
            $scope = Fixture::scope();
            $selected = new FinancialScope($scope->installationRef, $scope->sellerRef, $scope->environment, $scope->intentOwnerNamespace, 'remote-billium');
            $modules = $app->make(ModuleRegistry::class);
            $descriptor = $modules->descriptor('execution');
            self::assertNotNull($descriptor);
            $registrar = new DatabaseEventContractRegistrar($this->owner(), $modules);
            $registrar->register($registrar->plan($descriptor, true));
            $declaration = array_find($descriptor->publicEvents, static fn ($event): bool => $event->eventType === 'payment.effect.outcome.v1');
            self::assertNotNull($declaration);
            $contract = EventContractRegistration::from('execution', $declaration);
            $app->instance(PublicEventDestinations::class, new PublicEventDestinations([$contract->key() => ['fiscal', 'provisioning', 'accounting']]));
            $capability = SimulatedCapabilities::snapshot(evidenceMode: ProviderEvidenceMode::StableEffectId);
            Fixture::seedMerchant($app, $capability);
            Fixture::host($app, $path, cap: $capability);
            $account = new BillingAccountScope('seller-a', 'test', 'billing-a');
            $ledger = $app->make(LedgerParticipants::class);
            $this->runner()->run($this->spec(financialScope: $selected), static fn (TransactionContext $ctx) => $ledger->recordObligation($ctx,
                new RecordObligation('invoice-amount', $account, 'debt', 'basis', 'buyer', 'component', new Money(60, Currency::of('RUB')), 'sha256:'.str_repeat('f', 64))))->orThrow();
            $this->runner()->run($this->spec(financialScope: $selected), static fn (TransactionContext $ctx) => $ledger->declareSettlementIntent($ctx,
                new DeclareSettlementIntent('pay-obligation', $account, 'intent-a', 'RUB', ['debt'])))->orThrow();
            $app->make(RootPaymentCommands::class)->admit($scope, Fixture::wire(['schema' => AuthorizePayment::SCHEMA]))->orThrow();
            $authorized = $this->dispatchAndReduce('root-a');
            $observations = $authorized->observationCommandRefs;
            $statuses = $app->make(ExecutionQueries::class);
            $history = $app->make(ExecutionHistoryQueries::class);
            $authCommands = $app->make(AuthorizationCommands::class);
            $captures = [];
            foreach ([['capture-a', '0.60', 2], ['capture-b', '0.20', 3]] as [$ref, $amount, $number]) {
                $command = self::child(CapturePayment::SCHEMA, $ref, $amount, $number);
                self::assertInstanceOf(CapturePayment::class, $command);
                $accepted = $authCommands->admit($scope, $command)->orThrow();
                $id = $history->childBusiness($scope, 'remote-billium', EffectKind::Capture, 'checkout', 'intent-'.$ref);
                self::assertSame($accepted->body['receiptRef'], $id->receiptRef);
                self::assertSame($ref, $id->operation->operationRef);
                self::assertSame($command->money->minor, $id->operation->requested->minor);
                self::assertEquals($accepted->body, $history->receiptByCommandKey($scope, 'remote-billium', CapturePayment::SCHEMA, 'checkout', $command->commandKey)->outcome->body);
                $result = $this->dispatchAndReduce($ref);
                self::assertCount(1, $result->publicEventIds);
                self::assertCount(1, $result->observationCommandRefs);
                $status = $statuses->status($scope, $ref);
                self::assertSame('succeeded', $status->status);
                self::assertSame($result->observationCommandRefs, $status->delivery->observationCommandRefs);
                self::assertFalse($status->delivery->projectionApplied);
                $captures[$ref] = $result->publicEventIds[0];
                array_push($observations, ...$result->observationCommandRefs);
            }
            $refund = self::child(RequestRefund::SCHEMA, 'refund-a', '0.10', 4);
            self::assertInstanceOf(RequestRefund::class, $refund);
            $refunded = $app->make(RefundCommands::class)->admit($scope, $refund)->orThrow();
            $refundBinding = $history->childBusiness($scope, 'remote-billium', EffectKind::Refund, 'checkout', 'intent-refund-a');
            self::assertSame('overpayment', $refundBinding->reasonCode);
            self::assertSame('unallocated_overpayment', $refundBinding->category);
            self::assertSame($refunded->body['receiptRef'], $refundBinding->receiptRef);
            self::assertSame($refunded->body['semanticDigest'], $refundBinding->semanticDigest);
            $worker = new ChildProcess([PHP_BINARY, '-r', 'require "vendor/autoload.php"; Billium\\Execution\\Tests\\Feature\\FiscalAccountingHandoffTest::readHeldRefund($argv[1]);', $path]);
            self::assertSame(0, $worker->wait());
            $read = array_find(new DurableJournal($path)->records(), static fn (object $row): bool => $row instanceof JournalEvent && $row->kind === 'held-refund-read');
            self::assertInstanceOf(JournalEvent::class, $read);
            self::assertNotSame(getmypid(), $read->pid);
            self::assertNotSame($this->value('SELECT pg_backend_pid()'), $read->data['backend']);
            self::assertSame(80, $read->data['captured']);
            self::assertSame(0, $read->data['confirmedRefund']);
            self::assertSame(10, $read->data['heldRefund']);
            self::assertSame(70, $read->data['available']);
            $afterRefund = $this->dispatchAndReduce('refund-a');
            self::assertCount(2, $afterRefund->observationCommandRefs);
            array_push($observations, ...$afterRefund->observationCommandRefs);
            $emitted = [];
            foreach ($observations as $ref) {
                $emitted[] = OutcomeDeliveryTest::attest($app, $path, $ref);
            }
            self::assertSame([1, 2, 3, 4, 5], array_map(static fn (PaymentObservation $o): int => $o->sourceSequence, $emitted));
            self::assertSame([0, 60, 80, 80, 10], array_map(static fn (PaymentObservation $o): int => $o->capturedTotal->minor, $emitted));
            self::assertSame([0, 0, 0, 10, 0], array_map(static fn (PaymentObservation $o): int => $o->refundedTotal->minor, $emitted));
            self::assertSame(5, OutcomeDeliveryTest::relay($app, $path)->delivered);
            $initialProjection = $app->make(LedgerQueries::class)->paymentStatus('root-a', 'remote-billium');
            foreach (array_slice($observations, 1) as $ref) {
                OutcomeDeliveryTest::duplicateDelivery($app, $ref);
            }
            self::assertSame($initialProjection, $app->make(LedgerQueries::class)->paymentStatus('root-a', 'remote-billium'));
            self::assertSame(5, $this->rows('billium_observed_payment_revision'));
            self::assertSame(5, $this->rows('billium_allocation_receipt', "kind IN ('refund_detail','payment_observation')"));
            foreach (['capture-a', 'capture-b', 'refund-a'] as $ref) {
                $status = $statuses->status($scope, $ref);
                self::assertSame('succeeded', $status->status);
                self::assertTrue($status->delivery->projectionApplied);
                self::assertTrue($status->delivery->allocationApplied);
            }
            $refundProjection = $app->make(ObservationCheckpointQueries::class)->checkpoint($selected, $emitted[4]);
            self::assertSame('refund_detail', $refundProjection?->allocationReceipt?->mode());
            $payment = $app->make(LedgerQueries::class)->paymentStatus('root-a', 'remote-billium');
            $funds = self::arr($payment['applied']);
            self::assertSame([80, 10, 60, 10], array_map(static fn (string $field): int => Money::fromWire($funds[$field])->minor,
                ['capturedTotal', 'refundedTotal', 'allocated', 'unallocated']));
            self::assertSame(1, $this->rows('billium_allocation_receipt', "kind='refund_detail'"));
            self::assertSame(1, $this->rows('billium_allocation'));
            self::assertSame(5, $this->rows('billium_execution_observation_emission'));
            self::assertSame(4, UnknownResultTest::events($path, 'provider.call'));
            $this->proveIndependentDestinations($captures['capture-a'] ?? self::fail('First capture did not publish an effect'),
                $captures['capture-b'] ?? self::fail('Second capture did not publish an effect'));
            self::assertSame($payment, $app->make(LedgerQueries::class)->paymentStatus('root-a', 'remote-billium'));
            self::assertSame('succeeded', $statuses->status($scope, 'capture-a')->status);
            self::assertSame('succeeded', $statuses->status($scope, 'refund-a')->status);
            self::assertSame(4, UnknownResultTest::events($path, 'provider.call'));
            self::assertSame(0, UnknownResultTest::events($path, 'provider.lookup'));
        } finally {
            UnknownResultTest::clean($path);
            if (is_file($path.'.origins')) {
                unlink($path.'.origins');
            }
        }
    }

    public static function readHeldRefund(string $path): void
    {
        $app = Fixture::workerApp(506);
        $capacity = $app->make(ExecutionQueries::class)->status(Fixture::scope(), 'root-a')->capacity;
        $db = $app->make(DatabaseManager::class)->connection();
        new DurableJournal($path)->append(new JournalEvent('held-refund-read', [
            'backend' => self::integer($db->scalar('SELECT pg_backend_pid()')),
            'captured' => Money::fromWire($capacity->confirmedCapture)->minor,
            'confirmedRefund' => Money::fromWire($capacity->confirmedRefund)->minor,
            'heldRefund' => Money::fromWire($capacity->heldRefund)->minor,
            'available' => Money::fromWire($capacity->refundAvailable)->minor,
        ], ProcessIdentity::current()));
    }

    private function dispatchAndReduce(string $ref): ReductionResult
    {
        $app = $this->application();
        $scope = Fixture::scope();
        $app->make(AttemptWorker::class)->dispatch($scope, PaymentIdentity::reference($scope, $ref, 'attempt'), 'worker-'.$ref, new DateTimeImmutable('+1 minute'))->orThrow();
        $work = self::text($this->value("SELECT work_ref FROM billium_execution_provider_work WHERE operation_hint=? AND state='due'", [$ref]));
        $result = $app->make(EvidenceReducer::class)->reduce($work);
        self::assertSame(ReductionDisposition::Applied, $result->disposition);

        return $result;
    }

    private static function child(string $schema, string $ref, string $amount, int $number): CapturePayment|RequestRefund
    {
        $input = ['schema' => $schema, 'commandKey' => Fixture::key($number), 'installationRef' => self::INSTALLATION, 'environment' => 'test',
            'sellerRef' => 'seller-a', 'operationRef' => $ref, 'originalOperationRef' => 'root-a', 'source' => ['context' => 'checkout', 'intentRef' => 'intent-'.$ref],
            'money' => ['amount' => $amount, 'currency' => 'RUB'], 'requestedAt' => '2026-11-17T00:00:00Z'];
        if ($schema === RequestRefund::SCHEMA) {
            $input += ['reasonCode' => 'overpayment', 'category' => 'unallocated_overpayment'];
        }
        $decoded = new Codec()->decode(json_encode($input, JSON_THROW_ON_ERROR));
        if (! $decoded instanceof CapturePayment && ! $decoded instanceof RequestRefund) {
            throw new RuntimeException('Unexpected child payment command');
        }

        return $decoded;
    }

    private function proveIndependentDestinations(string $first, string $second): void
    {
        $app = $this->application();
        $queries = $app->make(OutboxDeliveryQueries::class);
        $ledger = $app->make(LedgerQueries::class);
        $payment = $ledger->paymentStatus('root-a', 'remote-billium');
        $db = $app->make(DatabaseManager::class)->connection();
        $feed = new FeedScope(self::INSTALLATION, 'seller-a', 'test', '1');
        $publisher = new class implements EventPublisher
        {
            /** @var list<string> */
            public array $offline = ['fiscal', 'provisioning', 'accounting'];

            /** @var array<string, list<string>> */
            public array $calls = [];

            public function publish(string $destinationRef, array $message): void
            {
                if (($message['eventType'] ?? null) !== 'payment.effect.outcome.v1') {
                    return;
                }
                $codec = new Codec;
                Assert::assertSame('billium.event.v2', $message['schema'] ?? null);
                $this->calls[$destinationRef][] = $codec->canonicalize($message);
                if (in_array($destinationRef, $this->offline, true)) {
                    throw new RuntimeException('fixture destination failure contains private diagnostic');
                }
            }
        };
        $relay = new OutboxRelay($db, $publisher, claimSeconds: 10, backoffSeconds: 1, budget: 2);
        self::assertSame(['pending', 'pending', 'pending'], self::states($queries->byEvent($feed, $first)));
        self::assertSame(6, $relay->relay()->failed);
        self::assertSame($payment, $ledger->paymentStatus('root-a', 'remote-billium'));
        $retrying = $queries->byEvent($feed, $first);
        self::assertSame(['pending', 'pending', 'pending'], self::states($retrying));
        foreach ($retrying as $row) {
            self::assertGreaterThan(new DateTimeImmutable, $row->availableAt);
            self::assertSame(RuntimeException::class, $row->lastErrorClass);
            self::assertSame(1, $row->attempts);
        }
        self::assertSame('capture-a', $retrying[0]->aggregateRef);
        self::assertSame(1, $retrying[0]->aggregateRevision);
        $publisher->offline = ['fiscal'];
        $this->owner()->update("UPDATE billium_outbox_delivery SET available_at=clock_timestamp()-interval '1 second' WHERE event_id IN (?,?)", [$first, $second]);
        self::assertSame(2, $relay->relay()->failed);
        $parked = $queries->byEvent($feed, $first);
        self::assertSame(['delivered', 'parked', 'delivered'], self::states($parked));
        self::assertSame('delivery_budget_exhausted', $parked[1]->parkReason);
        self::assertSame($payment, $ledger->paymentStatus('root-a', 'remote-billium'));
        self::assertSame(2, $parked[1]->consecutiveFailures);
        $publisher->offline = [];
        self::assertTrue($relay->requeue($first, 'fiscal'));
        self::assertSame(1, $relay->relay()->delivered);
        self::assertSame(['delivered', 'delivered', 'delivered'], self::states($queries->byEvent($feed, $first)));
        self::assertSame(['delivered', 'parked', 'delivered'], self::states($queries->byEvent($feed, $second)));
        $fiscal = $publisher->calls['fiscal'] ?? self::fail('Fiscal destination saw no factual outcome');
        self::assertCount(5, $fiscal);
        self::assertSame($fiscal[0], $fiscal[2]);
        self::assertSame($fiscal[0], $fiscal[4]);
        $firstPayload = self::arr(json_decode($fiscal[0], true, 32, JSON_THROW_ON_ERROR));
        $secondPayload = self::arr(json_decode($fiscal[1], true, 32, JSON_THROW_ON_ERROR));
        self::assertSame('0.60', self::arr($firstPayload['payload'])['effectTotal']);
        self::assertSame('0.20', self::arr($secondPayload['payload'])['effectTotal']);
    }

    /**
     * @param  list<OutboxDeliveryStatus>  $items
     * @return list<string>
     */
    private static function states(array $items): array
    {
        return array_map(static fn (OutboxDeliveryStatus $row): string => $row->status, $items);
    }
}
