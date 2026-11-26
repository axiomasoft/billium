<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Concurrency;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Envelopes\PaymentObservation;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Contracts\Runtime\IncomingCommandAuthenticator;
use Billium\Core\Contracts\Runtime\InternalCommandReceiver;
use Billium\Core\Contracts\Runtime\QualifiedFactualCommandPublisher;
use Billium\Core\Contracts\Runtime\ScopedCallerContext;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Data\Runtime\FactualCommandBinding;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Data\Runtime\SourceAuthorityGrant;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Core\Tests\Support\FixedCallerContext;
use Billium\Execution\Contracts\CallbackCustody;
use Billium\Execution\Contracts\EvidenceReducer;
use Billium\Execution\Contracts\ExecutionEvidenceQueries;
use Billium\Execution\Contracts\ExecutionQueries;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Data\ProviderEvidence;
use Billium\Execution\Data\ProviderWorkClaim;
use Billium\Execution\Tests\Concurrency\AdmissionIdentityTest as Fixture;
use Billium\Execution\Tests\Drill\UnknownResultTest;
use Billium\Execution\Tests\Feature\CallbackCustodyTest;
use Billium\Ledger\Contracts\LedgerParticipants;
use Billium\Ledger\Contracts\LedgerQueries;
use Billium\Ledger\Contracts\ObservationCheckpointQueries;
use Billium\Ledger\Data\Obligations\RecordObligation;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Billium\Ledger\Data\Settlements\DeclareSettlementIntent;
use Billium\Platform\Contracts\Outbox\OutboxDeliveryQueries;
use Billium\Platform\Data\EventContracts\PreparedFactualCommand;
use Billium\Platform\Data\Feed\FeedScope;
use Billium\Platform\Data\Feed\RelayReport;
use Billium\Platform\Data\Installation\PlatformReceipt;
use Billium\Platform\Data\Outbox\OutboxDeliveryStatus;
use Billium\Platform\Internal\CommandOutbox\CommandOutboxRelay;
use Billium\Testkit\Drivers\ResponseMode;
use Billium\Testkit\Fixtures\ChildProcess;
use Billium\Testkit\Fixtures\DurableJournal;
use Billium\Testkit\Fixtures\JournalEvent;
use Billium\Testkit\Fixtures\ProcessIdentity;
use Closure;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use stdClass;

#[Group('pg')]
final class OutcomeDeliveryTest extends ExecutionTestCase
{
    private const string ORIGIN_KEY = 'delivery-fixture-independent-origin-key';

    #[Group('PB-10')]
    public function test_receiver_commit_survives_lost_ack_and_cold_transport_retry_without_provider_io(): void
    {
        $path = Fixture::tempPath();
        try {
            $this->declareIntent();
            $observation = $this->dispatch($path);
            $scope = Fixture::scope();
            $queries = $this->application()->make(ExecutionQueries::class);
            $before = $queries->status($scope, 'root-a');
            self::assertSame([true, true, false, false], self::flags($before->delivery));
            $refs = $before->delivery->observationCommandRefs;
            self::assertIsArray($refs);
            self::assertCount(1, $refs);
            $commandRef = self::text($refs[0]);
            self::assertSame($this->value('SELECT command_ref FROM billium_outbox_command'), $commandRef);
            self::assertNotSame((string) self::integer($this->value('SELECT id FROM billium_outbox_command')), $commandRef);
            self::assertSame(0, $this->rows('billium_allocation'));

            self::child($path, 'lose-ack');
            $checkpoint = $this->application()->make(ObservationCheckpointQueries::class)->checkpoint(self::selection(), $observation);
            self::assertNotNull($checkpoint);
            self::assertTrue($checkpoint->projectionApplied);
            $receipt = $checkpoint->allocationReceipt;
            self::assertNotNull($receipt);
            self::assertSame('assign', $receipt->mode());
            self::assertSame('in_flight', $this->value('SELECT status FROM billium_outbox_command_delivery'));
            self::assertSame(1, $this->rows('billium_allocation'));
            self::assertSame([true, true, true, true], self::flags($queries->status($scope, 'root-a')->delivery));
            self::assertSame(1, $queries->status($scope, 'root-a')->delivery->lastAppliedObservationRevision);
            self::assertSame($refs, $queries->status($scope, 'root-a')->delivery->observationCommandRefs);

            // Advance only the relay lease in the fixture database; no payment, inbox or command content is touched.
            $this->owner()->update("UPDATE billium_outbox_command_delivery SET claim_until = clock_timestamp() - interval '1 second'");
            self::child($path, 'retry');
            $read = $this->application()->make(ObservationCheckpointQueries::class)->checkpoint(self::selection(), $observation);
            self::assertEquals($checkpoint, $read);
            self::assertSame($receipt->body, $this->application()->make(LedgerQueries::class)->receipt($receipt->receiptRef));
            self::assertSame('delivered', $this->value('SELECT status FROM billium_outbox_command_delivery'));
            self::assertSame(2, $this->value('SELECT attempts FROM billium_outbox_command_delivery'));
            self::assertSame(1, $this->rows('billium_observed_payment_revision'));
            self::assertSame(1, $this->rows('billium_allocation_receipt', "kind = 'payment_observation'"));
            self::assertSame(1, $this->rows('billium_allocation'));
            self::assertSame(1, $this->rows('billium_payment', 'captured_minor=100 AND allocated_minor=100'));
            self::assertSame(1, UnknownResultTest::events($path, 'provider.call'));
            self::assertSame(0, UnknownResultTest::events($path, 'provider.lookup'));
            $runs = [];
            foreach (new DurableJournal($path.'.delivery')->records() as $event) {
                if ($event instanceof JournalEvent && in_array($event->kind, ['lose-ack', 'retry'], true)) {
                    $runs[$event->kind] = $event;
                }
            }
            self::assertCount(2, $runs);
            $lost = $runs['lose-ack'] ?? self::fail('Receiver did not report its committed checkpoint');
            $retry = $runs['retry'] ?? self::fail('Cold retry did not report its receipt');
            self::assertNotSame($lost->pid, $retry->pid);
            self::assertNotSame($lost->data['backend'], $retry->data['backend']);
            self::assertSame($commandRef, $lost->data['commandRef']);
            self::assertSame(1, $retry->data['delivered']);
        } finally {
            self::clean($path);
        }
    }

    public function test_confirmed_observation_stays_queryable_when_settlement_dependency_parks_allocation(): void
    {
        $path = Fixture::tempPath();
        try {
            $observation = $this->dispatch($path);
            $app = $this->application();
            $checkpoints = $app->make(ObservationCheckpointQueries::class);
            self::assertNull($checkpoints->checkpoint(self::selection(), $observation));
            self::assertSame(1, self::relay($app, $path)->failed);
            $parked = $checkpoints->checkpoint(self::selection(), $observation);
            self::assertNotNull($parked);
            self::assertTrue($parked->projectionApplied);
            self::assertNull($parked->allocationReceipt);
            self::assertSame('settlement_intent', $parked->pendingReason);
            $status = $app->make(ExecutionQueries::class)->status(Fixture::scope(), 'root-a');
            self::assertSame('succeeded', $status->status);
            self::assertSame([true, true, true, false], self::flags($status->delivery));
            self::assertSame('pending', $status->recovery->status);
            self::assertSame(['settlement_intent'], $status->recovery->reasonCodes);
            $ledger = $app->make(LedgerQueries::class)->paymentStatus('root-a', 'remote-billium');
            self::assertSame('allocation_pending', $ledger['projectionStatus']);
            self::assertSame(0, $this->rows('billium_allocation'));
            $forged = clone $observation->raw;
            $forged->sourceLineage = 'unrelated-source-lineage';
            $forged->contentDigest = new Codec()->digest($forged, 'contentDigest');
            $different = new Codec()->decode(new Codec()->canonicalize($forged));
            self::assertInstanceOf(PaymentObservation::class, $different);
            self::assertNull($checkpoints->checkpoint(self::selection(), $different));
            $scope = Fixture::scope();
            $this->callers->set(new Caller($scope->sellerRef, $scope->environment, $scope->installationRef, 'other-owner', ['remote-billium'], ['other-owner']));
            $other = new FinancialScope($scope->installationRef, $scope->sellerRef, $scope->environment, 'other-owner', 'remote-billium');
            self::assertNull($checkpoints->checkpoint($other, $observation));
            try {
                $checkpoints->checkpoint(self::selection(), $observation);
                self::fail('Unselected owner could read projected checkpoint');
            } catch (ProblemException $problem) {
                self::assertSame(ProblemCode::AuthDenied, $problem->problem);
            }
            $this->callers->set(new Caller($scope->sellerRef, $scope->environment, $scope->installationRef, 'actor-1', ['remote-billium'], ['source-a']));
            $this->declareIntent();
            $this->owner()->update("UPDATE billium_outbox_command_delivery SET available_at = clock_timestamp() - interval '1 second'");
            self::child($path, 'retry');
            self::assertSame([true, true, true, true], self::flags($app->make(ExecutionQueries::class)->status($scope, 'root-a')->delivery));
            self::assertSame(1, $this->rows('billium_allocation'));
            self::assertSame(1, UnknownResultTest::events($path, 'provider.call'));
        } finally {
            self::clean($path);
        }
    }

    public function test_due_delivery_query_is_scoped_bounded_and_uses_both_partial_indexes(): void
    {
        $owner = $this->owner();
        $scope = new FeedScope(self::INSTALLATION, 'seller-a', 'test', '1');
        $foreign = new FeedScope(self::INSTALLATION, 'seller-b', 'test', '1');
        $owner->insert(<<<'SQL'
            INSERT INTO billium_outbox_event
                (event_id,seller_ref,environment,type,schema_version,aggregate_ref,aggregate_revision,occurred_at,digest,payload,
                 contract_revision,owner_module_id,source_scope)
            SELECT 'history-'||i, 'seller-a','test','obligation.created.v1','billium.event.v1', 'obligation-'||i,1,now(),
                   'sha256:'||repeat('a',64),'{}'::jsonb,'events-2026-10-v1','ledger',?
            FROM generate_series(1,6000) i
            SQL, [$scope->key()]);
        $owner->insert(<<<'SQL'
            INSERT INTO billium_outbox_delivery(event_id,destination_ref,status,delivered_at)
            SELECT event_id,'default','delivered',now() FROM billium_outbox_event
            SQL);
        foreach ([
            ['other-seller', $foreign, 'pending', '-3 seconds'], ['due-a', $scope, 'pending', '-2 seconds'],
            ['expired-a', $scope, 'in_flight', '-1 second'], ['future-a', $scope, 'pending', '+1 hour'],
            ['live-claim-a', $scope, 'in_flight', '+1 hour'], ['parked-a', $scope, 'parked', '-4 seconds'],
        ] as [$eventId, $selection, $status, $offset]) {
            $owner->insert(<<<'SQL'
                INSERT INTO billium_outbox_event
                    (event_id,seller_ref,environment,type,schema_version,aggregate_ref,aggregate_revision,occurred_at,digest,payload,
                     contract_revision,owner_module_id,source_scope)
                VALUES (?,?,'test','obligation.created.v1','billium.event.v1','obligation-cold',7,now(),
                    'sha256:'||repeat('b',64),'{}'::jsonb,'events-2026-10-v1','ledger',?)
                SQL, [$eventId, $selection->sellerRef, $selection->key()]);
            $owner->insert(<<<'SQL'
                INSERT INTO billium_outbox_delivery(event_id,destination_ref,status,available_at,claim_token,claim_until)
                VALUES (?,'default',?,now()+?::interval,?,CASE WHEN ? = 'in_flight' THEN now()+?::interval END)
                SQL, [$eventId, $status, $offset, $status === 'in_flight' ? 'test-claim-'.$eventId : null, $status, $offset]);
        }
        $owner->statement('ANALYZE billium_outbox_event, billium_outbox_delivery');
        $app = $this->application();
        $query = $app->make(OutboxDeliveryQueries::class);
        $db = $app->make(DatabaseManager::class)->connection();
        $db->enableQueryLog();
        $due = $query->due($scope, 'default', 2);
        $queries = $db->getQueryLog();
        $db->disableQueryLog();
        self::assertSame(['due-a', 'expired-a'], array_map(static fn (OutboxDeliveryStatus $status): string => $status->eventId, $due));
        self::assertSame(['pending', 'in_flight'], array_map(static fn (OutboxDeliveryStatus $status): string => $status->status, $due));
        self::assertSame('obligation.created.v1', $due[0]->eventType);
        self::assertSame('obligation-cold', $due[0]->aggregateRef);
        self::assertSame(7, $due[0]->aggregateRevision);
        self::assertNull($due[0]->claimUntil);
        self::assertNotNull($due[1]->claimUntil);
        self::assertSame('due-a', $query->due($scope, 'default', 1)[0]->eventId);
        self::assertSame([], $query->byEvent($scope, 'other-seller'));
        self::assertSame('parked', $query->byEvent($scope, 'parked-a')[0]->status);
        $statement = array_find($queries, static fn (array $entry): bool => str_contains(self::text($entry['query']), 'due_delivery'));
        self::assertNotNull($statement);
        /** @var list<mixed> $bindings */
        $bindings = $statement['bindings'];
        $explain = $owner->selectOne('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.self::text($statement['query']), $bindings);
        self::assertInstanceOf(stdClass::class, $explain);
        $plan = self::text($explain->{'QUERY PLAN'});
        self::assertStringContainsString('billium_outbox_delivery_destination_due', $plan);
        self::assertStringContainsString('billium_outbox_delivery_destination_expired', $plan);
        self::assertStringNotContainsString('"Node Type": "Seq Scan"', $plan);
        fwrite(STDOUT, "Delivery due EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)\n".$plan.PHP_EOL);

        $this->callers->set(new Caller('seller-b', 'test', self::INSTALLATION, 'foreign-reader'));
        self::assertSame('other-seller', $query->due($foreign, 'default', 1)[0]->eventId);
        self::assertSame([], $query->byEvent($foreign, 'due-a'));
        $this->expectException(ProblemException::class);
        $this->expectExceptionMessage('Delivery feed scope is not authenticated');
        $query->due($scope, 'default');
    }

    public function test_cold_authorized_queries_preserve_signed_effect_and_versioned_work_provenance(): void
    {
        $path = Fixture::tempPath();
        try {
            $app = $this->application();
            $scope = Fixture::scope();
            $provider = CallbackCustodyTest::dispatched($app, $path);
            $work = CallbackCustodyTest::work($provider);
            $queries = $app->make(ExecutionEvidenceQueries::class);
            $pending = $queries->work($scope, 'remote-billium', 'root-a', $work);
            self::assertNotNull($pending);
            self::assertSame('due', $pending->state);
            self::assertSame(0, $pending->attemptCount);
            self::assertSame(0, $pending->leaseFence);
            self::assertNull($queries->material($scope, 'remote-billium', 'root-a'));
            self::assertSame('pending', $app->make(ExecutionQueries::class)->status($scope, 'root-a')->status);
            $store = $app->make(ExecutionStore::class);
            $spec = $this->spec(kind: 'execution.test_read_work_lease', mode: FinancialMode::RawCustody, class: ModuleOperationClass::CallbackCustody,
                modules: ['execution', 'platform'], financialScope: self::selection());
            $claim = null;
            $lease = new DateTimeImmutable('+1 minute');
            $this->runner()->run($spec, static function (TransactionContext $ctx) use ($store, $work, $lease, &$claim): PlatformReceipt {
                $claim = $store->claimProviderWork($ctx, $work, 'fixture-worker', $lease);

                return self::receipt();
            })->orThrow();
            self::assertInstanceOf(ProviderWorkClaim::class, $claim);
            $leased = $queries->work($scope, 'remote-billium', 'root-a', $work);
            self::assertNotNull($leased);
            self::assertSame('leased', $leased->state);
            self::assertSame(1, $leased->attemptCount);
            self::assertSame(1, $leased->leaseFence);
            self::assertEquals($lease, $leased->leaseExpiresAt);
            $this->runner()->run($spec, static function (TransactionContext $ctx) use ($store, $claim): PlatformReceipt {
                self::assertTrue($store->finishProviderWork($ctx, $claim, 'due', null, 'fixture-deferred', new DateTimeImmutable('+1 minute')));

                return self::receipt();
            })->orThrow();
            $deferred = $queries->work($scope, 'remote-billium', 'root-a', $work);
            self::assertNotNull($deferred);
            self::assertSame('fixture-deferred', $deferred->lastErrorCode);
            self::assertNotNull($deferred->lastErrorAt);
            $this->owner()->update("UPDATE billium_execution_provider_work SET due_at=clock_timestamp()-interval '1 second' WHERE work_ref=?", [$work]);
            $committed = $app->make(EvidenceReducer::class)->reduce($work);
            self::assertCount(1, $committed->observationCommandRefs);
            $observation = self::attest($app, $path, $committed->observationCommandRefs[0]);
            $upgrade = 'unsupported-work-on-same-evidence';
            $this->runner()->run($this->spec(kind: 'execution.test_work_upgrade', modules: ['execution', 'platform'], financialScope: self::selection()),
                static function (TransactionContext $ctx) use ($store, $provider, $upgrade): PlatformReceipt {
                    $store->enqueueProviderWork($ctx, $provider->evidenceRef, $upgrade, 'unsupported-handler.v8', 'root-a');

                    return self::receipt();
                })->orThrow();
            $app->make(EvidenceReducer::class)->reduce($upgrade);
            $worker = new ChildProcess([PHP_BINARY, '-r', 'require "vendor/autoload.php"; Billium\\Execution\\Tests\\Concurrency\\OutcomeDeliveryTest::coldEvidence($argv[1], $argv[2], $argv[3]);', $path, $work, $upgrade]);
            self::assertSame(0, $worker->wait());
            $entry = array_find(new DurableJournal($path.'.delivery')->records(), static fn (object $e): bool => $e instanceof JournalEvent && $e->kind === 'cold-evidence');
            self::assertInstanceOf(JournalEvent::class, $entry);
            self::assertNotSame(getmypid(), $entry->pid);
            self::assertNotSame($this->value('SELECT pg_backend_pid()'), $entry->data['backend']);
            $read = self::arr(json_decode(self::text($entry->data['snapshot']), true, 64, JSON_THROW_ON_ERROR));
            self::assertSame(['inst-test', 'seller-a', 'test', 'source-a', 'remote-billium', 'root-a'], $read['scope']);
            self::assertNotNull($observation->providerOperationId);
            self::assertSame([$observation->providerOperationId, 'succeeded', 100, 'RUB', 2, [$observation->evidenceRef]], $read['material']);
            self::assertNotSame($observation->providerOperationId, $read['stableEffectId']);
            self::assertSame($observation->observedAt, $read['observedAt']);
            self::assertSame(['simulated-provider', 'account-a', $provider->providerEventId, $provider->payloadDigest, 'simulated-valid',
                $provider->handlerContractVersion, $provider->handlerContractVersion], $read['custody']);
            self::assertSame($provider->receivedAt->format('U.u'), $read['receivedAt']);
            self::assertSame([$work, $provider->evidenceRef, 'processed', 2, 2, null, null, $provider->evidenceRef], $read['work']);
            self::assertSame([$upgrade, $provider->evidenceRef, 'unsupported-handler.v8', 'parked', 1, 1, 'unsupported_handler_version'], $read['upgrade']);
            self::assertTrue($read['upgradeHasErrorTime']);
            $this->callers->set(new Caller($scope->sellerRef, $scope->environment, $scope->installationRef, 'wrong-source', ['standalone-billium'], ['source-a']));
            try {
                $queries->work($scope, 'standalone-billium', 'root-a', $work);
                self::fail('Alternate grant could read original provider work');
            } catch (ProblemException $problem) {
                self::assertSame(ProblemCode::ReferenceUnknown, $problem->problem);
            }
        } finally {
            self::clean($path);
        }
    }

    public static function coldEvidence(string $path, string $workRef, string $upgradeRef): void
    {
        $app = Fixture::workerApp(505);
        $scope = Fixture::scope();
        $evidence = $app->make(ExecutionEvidenceQueries::class);
        $latest = $evidence->material($scope, 'remote-billium', 'root-a');
        $revision = $evidence->material($scope, 'remote-billium', 'root-a', 1);
        self::assertNotNull($latest);
        self::assertEquals($latest, $revision);
        self::assertNull($evidence->material($scope, 'remote-billium', 'root-a', 2));
        $work = $evidence->work($scope, 'remote-billium', 'root-a', $workRef);
        $upgrade = $evidence->work($scope, 'remote-billium', 'root-a', $upgradeRef);
        self::assertNotNull($work);
        self::assertNotNull($upgrade);
        $body = ['scope' => [$work->scope->installationRef, $work->scope->sellerRef, $work->scope->environment, $work->scope->intentOwnerNamespace, $work->sourceAuthority, $work->operationRef],
            'material' => [$latest->providerOperationId, $latest->normalizedStatus, $latest->operationAmount?->minor, $latest->operationAmount?->currency->code,
                $latest->operationAmount?->currency->scale, $latest->evidenceRefs], 'observedAt' => $latest->observedAt->format('Y-m-d\TH:i:s.u\Z'),
            'stableEffectId' => $latest->stableEffectId,
            'custody' => [$work->providerId, $work->merchantAccountRef, $work->providerEventId, $work->payloadDigest, $work->signatureVerdict,
                $work->retainedHandlerVersion, $work->workHandlerVersion], 'receivedAt' => $work->receivedAt->format('U.u'),
            'work' => [$work->workRef, $work->evidenceRef, $work->state, $work->attemptCount, $work->leaseFence, $work->leaseExpiresAt, $work->lastErrorCode, $work->resultRef],
            'upgrade' => [$upgrade->workRef, $upgrade->evidenceRef, $upgrade->workHandlerVersion, $upgrade->state, $upgrade->attemptCount, $upgrade->leaseFence,
                $upgrade->lastErrorCode], 'upgradeHasErrorTime' => $upgrade->lastErrorAt !== null];
        new DurableJournal($path.'.delivery')->append(new JournalEvent('cold-evidence', ['snapshot' => json_encode($body, JSON_THROW_ON_ERROR),
            'backend' => self::integer($app->make(DatabaseManager::class)->connection()->scalar('SELECT pg_backend_pid()'))], ProcessIdentity::current()));
    }

    public function test_pending_signed_fact_is_distinct_from_revision_and_unlinked_hints_stay_hidden(): void
    {
        $path = Fixture::tempPath();
        try {
            $app = $this->application();
            $scope = Fixture::scope();
            $provider = CallbackCustodyTest::dispatched($app, $path, ResponseMode::Pending);
            $work = CallbackCustodyTest::work($provider);
            $outcome = $app->make(EvidenceReducer::class)->reduce($work);
            self::assertSame([], $outcome->observationCommandRefs);
            self::assertSame(0, $this->rows('billium_execution_operation_revision'));
            self::assertSame(1, $this->rows('billium_execution_observation_partition', 'last_sequence=0'));
            $worker = new ChildProcess([PHP_BINARY, '-r', 'require "vendor/autoload.php"; Billium\\Execution\\Tests\\Concurrency\\OutcomeDeliveryTest::coldPending($argv[1]);', $path]);
            self::assertSame(0, $worker->wait());
            $read = array_find(new DurableJournal($path.'.delivery')->records(), static fn (object $row): bool => $row instanceof JournalEvent && $row->kind === 'pending-read');
            self::assertInstanceOf(JournalEvent::class, $read);
            self::assertNotSame(getmypid(), $read->pid);
            self::assertNotSame($this->value('SELECT pg_backend_pid()'), $read->data['backend']);
            self::assertSame('pending', $read->data['providerStatus']);
            self::assertSame('pending', $read->data['publicStatus']);
            self::assertTrue($read->data['amountMissing']);
            self::assertTrue($read->data['revisionMissing']);
            self::assertFalse($read->data['outcomeRecorded']);

            $forged = new ProviderEvidence('invalid-evidence', $scope, $provider->providerId, $provider->merchantAccountRef, $provider->providerEventId,
                $provider->payloadDigest, $provider->signatureVerdict, $provider->handlerContractVersion, $provider->receivedAt,
                array_replace($provider->sanitizedPayload, ['signature' => 'invalid-signature']), $provider->securePayloadRef);
            try {
                $app->make(CallbackCustody::class)->receive($scope, 'merchant-a', 'root-a', $forged);
                self::fail('Invalid signature was accepted');
            } catch (ProblemException $error) {
                self::assertSame(ProblemCode::AuthDenied, $error->problem);
            }
            $quarantine = self::text($this->value("SELECT work_ref FROM billium_execution_provider_work WHERE state='quarantined' AND last_error_code='callback_authentication_failed'"));
            self::assertNull($app->make(ExecutionEvidenceQueries::class)->work($scope, 'remote-billium', 'root-a', $quarantine));
            self::assertSame(1, $this->rows('billium_execution_provider_evidence', "signature_verdict='unverified'"));
            self::assertSame(0, $this->rows('billium_outbox_command'));
        } finally {
            self::clean($path);
        }
    }

    public static function coldPending(string $path): void
    {
        $app = Fixture::workerApp(507);
        $scope = Fixture::scope();
        $evidence = $app->make(ExecutionEvidenceQueries::class);
        $material = $evidence->material($scope, 'remote-billium', 'root-a');
        self::assertNotNull($material);
        $status = $app->make(ExecutionQueries::class)->status($scope, 'root-a');
        $db = $app->make(DatabaseManager::class)->connection();
        new DurableJournal($path.'.delivery')->append(new JournalEvent('pending-read', [
            'backend' => self::integer($db->scalar('SELECT pg_backend_pid()')),
            'providerStatus' => $material->normalizedStatus,
            'publicStatus' => $status->status,
            'amountMissing' => $material->operationAmount === null,
            'revisionMissing' => $evidence->material($scope, 'remote-billium', 'root-a', 1) === null,
            'outcomeRecorded' => $status->delivery->providerOutcomeRecorded === true,
        ], ProcessIdentity::current()));
    }

    /** @return list<mixed> */
    private static function flags(stdClass $delivery): array
    {
        return [$delivery->commandAccepted, $delivery->providerOutcomeRecorded, $delivery->projectionApplied, $delivery->allocationApplied];
    }

    private function dispatch(string $path): PaymentObservation
    {
        $app = $this->application();
        $evidence = CallbackCustodyTest::dispatched($app, $path);
        $reduction = $app->make(EvidenceReducer::class)->reduce(CallbackCustodyTest::work($evidence));
        self::assertCount(1, $reduction->observationCommandRefs);

        return self::attest($app, $path, $reduction->observationCommandRefs[0]);
    }

    private function declareIntent(): void
    {
        $ledger = $this->application()->make(LedgerParticipants::class);
        $account = new BillingAccountScope('seller-a', 'test', 'billing-a');
        $spec = $this->spec(financialScope: self::selection());
        $this->runner()->run($spec, static fn (TransactionContext $ctx) => $ledger->recordObligation($ctx, new RecordObligation('debt', $account,
            'debt', 'basis', 'buyer', 'component', new Money(100, Currency::of('RUB')), 'sha256:'.str_repeat('f', 64))))->orThrow();
        $this->runner()->run($spec, static fn (TransactionContext $ctx) => $ledger->declareSettlementIntent($ctx,
            new DeclareSettlementIntent('declare', $account, 'intent-a', 'RUB', ['debt'])))->orThrow();
    }

    public static function attest(Application $app, string $path, string $commandRef): PaymentObservation
    {
        [$payload, $message] = self::command($app, $commandRef);
        $destination = PreparedFactualCommand::DESTINATION;
        $binding = FactualCommandBinding::digest($destination, $message, self::selection());
        new DurableJournal($path.'.origins')->append(new SourceAuthorityGrant($payload->installationRef, $payload->sellerRef, $payload->environment,
            $payload->sourceAuthority, $payload->sourceLineage, $destination, 'delivery-test-simulated-external-origin', new DateTimeImmutable('+10 minutes'),
            'sha256:'.hash_hmac('sha256', $binding, self::ORIGIN_KEY), 'source-a', $binding));

        return $payload;
    }

    public static function duplicateDelivery(Application $app, string $commandRef): void
    {
        [, $message] = self::command($app, $commandRef);
        $app->make(InternalCommandReceiver::class)->receive(PreparedFactualCommand::DESTINATION, $message);
    }

    /** @return array{PaymentObservation, array<string,mixed>} */
    private static function command(Application $app, string $commandRef): array
    {
        $row = $app->make(DatabaseManager::class)->connection()->table('billium_outbox_command')->where('command_ref', $commandRef)->first();
        self::assertNotNull($row);
        $payload = new Codec()->decode(self::text($row->payload));
        self::assertInstanceOf(PaymentObservation::class, $payload);

        return [$payload, ['schema' => $row->command_schema, 'commandRef' => $row->command_ref, 'businessRef' => $row->business_ref,
            'commandType' => $row->command_type, 'payloadDigest' => $row->payload_digest, 'payload' => $payload->raw]];
    }

    private static function selection(): FinancialScope
    {
        return new FinancialScope(self::INSTALLATION, 'seller-a', 'test', 'source-a', 'remote-billium');
    }

    private static function child(string $path, string $mode): void
    {
        $worker = new ChildProcess([PHP_BINARY, '-r', 'require "vendor/autoload.php"; Billium\\Execution\\Tests\\Concurrency\\OutcomeDeliveryTest::coldRelay($argv[1], $argv[2]);', $path, $mode]);
        self::assertSame(0, $worker->wait());
    }

    public static function coldRelay(string $path, string $mode): void
    {
        $app = Fixture::workerApp($mode === 'lose-ack' ? 501 : 502);
        $report = self::relay($app, $path, $mode === 'lose-ack');
        $db = $app->make(DatabaseManager::class)->connection();
        new DurableJournal($path.'.delivery')->append(new JournalEvent($mode, ['backend' => self::integer($db->scalar('SELECT pg_backend_pid()')),
            'delivered' => $report->delivered, 'failed' => $report->failed], ProcessIdentity::current()));
    }

    public static function relay(Application $app, string $path, bool $loseAck = false): RelayReport
    {
        $current = self::fixedCaller($app->make(CallerContext::class));
        $callers = new readonly class($current) implements ScopedCallerContext
        {
            public function __construct(private FixedCallerContext $callers) {}

            public function current(): Caller
            {
                return $this->callers->current();
            }

            public function run(Caller $caller, Closure $callback): mixed
            {
                $prior = $this->current();
                $this->callers->set($caller);
                try {
                    return $callback();
                } finally {
                    $this->callers->set($prior);
                }
            }
        };
        $app->instance(ScopedCallerContext::class, $callers);
        $app->instance(IncomingCommandAuthenticator::class, new readonly class($path, self::selection(), self::ORIGIN_KEY) implements IncomingCommandAuthenticator
        {
            public function __construct(private string $path, private FinancialScope $scope, private string $key) {}

            public function authenticate(string $destinationRef, array $message): SourceAuthorityGrant
            {
                $binding = FactualCommandBinding::digest($destinationRef, $message, $this->scope);
                $grant = array_find(new DurableJournal($this->path.'.origins')->records(), static fn (object $e): bool => $e instanceof SourceAuthorityGrant && $e->commandBindingDigest === $binding);
                if (! $grant instanceof SourceAuthorityGrant || ! hash_equals('sha256:'.hash_hmac('sha256', $binding, $this->key), $grant->proofDigest)) {
                    throw new LogicException('Delivery does not have its independent source grant');
                }

                return $grant;
            }
        });
        $db = $app->make(DatabaseManager::class)->connection();
        $publisher = new readonly class($app->make(InternalCommandReceiver::class), $path, self::integer($db->scalar('SELECT pg_backend_pid()')), $loseAck) implements QualifiedFactualCommandPublisher
        {
            public function __construct(private InternalCommandReceiver $receiver, private string $path, private int $backend, private bool $loseAck) {}

            public function publish(string $destinationRef, array $message): void
            {
                throw new LogicException('Unqualified transport is unexpected');
            }

            public function publishQualified(string $destinationRef, array $message, FinancialScope $scope): void
            {
                $this->receiver->receive($destinationRef, $message);
                if ($this->loseAck) {
                    $ref = $message['commandRef'] ?? null;
                    if (! is_string($ref)) {
                        throw new LogicException('Expected bound opaque command reference');
                    }
                    new DurableJournal($this->path.'.delivery')->append(new JournalEvent('lose-ack', ['backend' => $this->backend, 'commandRef' => $ref], ProcessIdentity::current()));
                    exit(0);
                }
            }
        };

        return new CommandOutboxRelay($db, $publisher, claimSeconds: 1, backoffSeconds: 1, budget: 4)->relay();
    }

    private static function fixedCaller(CallerContext $context): FixedCallerContext
    {
        return $context instanceof FixedCallerContext ? $context : throw new LogicException('Delivery test expects its fixed caller context');
    }

    private static function clean(string $path): void
    {
        UnknownResultTest::clean($path);
        foreach ([$path.'.origins', $path.'.delivery'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
}
