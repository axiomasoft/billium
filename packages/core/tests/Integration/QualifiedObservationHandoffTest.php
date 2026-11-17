<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Integration;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Envelopes\PaymentObservation;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\EventPublisher;
use Billium\Core\Contracts\Runtime\FinancialAuthorityEvidence;
use Billium\Core\Contracts\Runtime\IncomingCommandAuthenticator;
use Billium\Core\Contracts\Runtime\QualifiedFactualCommandPublisher;
use Billium\Core\Contracts\Runtime\ScopedCallerContext;
use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Core\Data\Runtime\AccountCoverage;
use Billium\Core\Data\Runtime\ActivationCertificate;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Data\Runtime\FactualCommandBinding;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Data\Runtime\NamespaceDisposition;
use Billium\Core\Data\Runtime\RecoveryCoverage;
use Billium\Core\Data\Runtime\SourceAuthorityGrant;
use Billium\Core\Data\Runtime\VerifiedSessionAuthorization;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Internal\Runtime\PaymentObservationCommandReceiver;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Core\Tests\Support\Concurrency\ConcurrencyStand;
use Billium\Core\Tests\Support\FixedCallerContext;
use Billium\Core\Tests\Support\FixedSessionAdmission;
use Billium\Core\Tests\Support\ObservationFactory;
use Billium\Ledger\Contracts\LedgerParticipants;
use Billium\Ledger\Contracts\ObservationApplier;
use Billium\Ledger\Contracts\QualifiedObservationApplier;
use Billium\Ledger\Data\Obligations\RecordObligation;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Billium\Ledger\Data\Settlements\DeclareSettlementIntent;
use Billium\Ledger\Internal\Commands\BootstrapPartitionCommand;
use Billium\Ledger\Internal\Observation\EconomicDigest;
use Billium\Ledger\Internal\Observation\ParkedDrainer;
use Billium\Ledger\Tests\Observation\QualifiedDrainWorker;
use Billium\Platform\Contracts\Feed\FeedPartitions;
use Billium\Platform\Contracts\Inbox\ObservationBootstrapAuthenticator;
use Billium\Platform\Contracts\Outbox\FactualCommandOutbox;
use Billium\Platform\Data\EventContracts\PreparedFactualCommand;
use Billium\Platform\Data\Inbox\BootstrapItem;
use Billium\Platform\Data\Inbox\BootstrapPartition;
use Billium\Platform\Data\Inbox\BootstrapStatus;
use Billium\Platform\Data\Inbox\VerifiedObservationBootstrap;
use Billium\Platform\Data\Installation\PlatformReceipt;
use Billium\Platform\Internal\CommandOutbox\CommandOutboxRelay;
use Closure;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use LogicException;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
final class QualifiedObservationHandoffTest extends ExecutionTestCase
{
    private VerifiedSessionAuthorization $signedSession;

    private function selection(string $owner = 'source-a'): FinancialScope
    {
        return new FinancialScope(self::INSTALLATION, 'seller-a', 'test', $owner, 'remote-billium');
    }

    protected function activeSession(): void
    {
        $this->installation()->init(self::INSTALLATION);
        $this->authority->issueTransition('open', 'open', ['owner-1', 1, 1, 1, 'opening']);
        $this->installation()->openSession('open', 'owner-1', 1, 1, 1, 'opening');
        $token = FixedSessionAdmission::token(self::INSTALLATION, modes: [FinancialMode::Recovery]);
        $this->tokens->set($token);
        $this->authority->issueProcess($token, [$this->selection(), $this->selection('source-b')]);
    }

    private function activate(): void
    {
        $good = $this->authority->issueTransition('activate', 'activate', [1, 'certificate', 'admission', 'evidence']);
        $certificate = $good->activationCertificate;
        self::assertNotNull($certificate);
        $from = $certificate->coverage->recoveryHorizon;
        $coverage = new RecoveryCoverage(...array_replace(get_object_vars($certificate->coverage), [
            'perAccountWatermarks' => [new AccountCoverage('pa-1', 'fixture-a', $from, new DateTimeImmutable, 'A:15', 'digest-A', 'fixture-coverage-A'),
                new AccountCoverage('pa-b', 'fixture-b', $from, new DateTimeImmutable, 'B:21', 'digest-B', 'fixture-coverage-B')],
            'namespaceStates' => [new NamespaceDisposition($this->selection(), 'active', 'fixture-coverage-A'),
                new NamespaceDisposition($this->selection('source-b'), 'sealed', 'fixture-coverage-B')],
        ]));
        $certificate = new ActivationCertificate(...array_replace(get_object_vars($certificate), ['coverage' => $coverage]));
        $signed = new VerifiedSessionAuthorization(...array_replace(get_object_vars($good), ['activationCertificate' => $certificate]));
        $this->signedSession = $signed;
        $host = $this->createMock(FinancialAuthorityEvidence::class);
        $host->method('process')->willReturnCallback($this->authority->process(...));
        $host->method('transition')->willReturnCallback(fn ($installation, $ref): ?VerifiedSessionAuthorization => $ref === 'activate' ? $signed : $this->authority->transition($installation, $ref));
        $this->application()->instance(FinancialAuthorityEvidence::class, $host);
        $this->installation()->activateSession('activate', 1, 'certificate', 'admission', 'evidence');
        $this->worker();
    }

    private function worker(): void
    {
        $old = $this->tokens->currentToken(self::INSTALLATION);
        if ($old !== null) {
            $this->authority->forgetProcess($old);
        }
        $token = FixedSessionAdmission::token(self::INSTALLATION, processRef: 'worker-'.bin2hex(random_bytes(8)));
        $this->tokens->set($token);
        $this->authority->issueProcess($token, [$this->selection()]);
    }

    private function settlement(string $intent = 'intent-a'): void
    {
        $ledger = $this->application()->make(LedgerParticipants::class);
        $account = new BillingAccountScope('seller-a', 'test', 'billing-a');
        $this->runner()->run($this->spec(financialScope: $this->selection()), fn (TransactionContext $ctx) => $ledger->recordObligation($ctx,
            new RecordObligation('debt-'.$intent, $account, 'debt-'.$intent, 'basis-'.$intent, 'customer-a', 'component-'.$intent, new Money(100, Currency::of('RUB')), 'sha256:'.str_repeat('d', 64))));
        $this->runner()->run($this->spec(financialScope: $this->selection()), fn (TransactionContext $ctx) => $ledger->declareSettlementIntent($ctx,
            new DeclareSettlementIntent('declare-'.$intent, $account, $intent, 'RUB', ['debt-'.$intent])));
    }

    private function fact(string $root = 'root-a', int $sequence = 1, array $fields = []): PreparedFactualCommand
    {
        $codec = new Codec;
        $lineage = $fields['sourceLineage'] ?? 'execution-lineage:'.hash('sha256', $codec->canonicalize(['schema' => 'billium.execution.source-lineage.v1',
            'installationRef' => self::INSTALLATION, 'ownerSessionRef' => 'owner-1', 'deploymentGeneration' => '1', 'attachmentGeneration' => '1', 'authorityEpoch' => '1', 'localSessionEpoch' => '1']));
        $partition = 'execution-charge:'.hash('sha256', $codec->canonicalize(['schema' => 'billium.execution.observation-partition.v1', 'installationRef' => self::INSTALLATION,
            'sellerRef' => 'seller-a', 'environment' => 'test', 'sourceAuthority' => 'remote-billium', 'sourceLineage' => $lineage, 'rootOperationRef' => $root]));
        $raw = ObservationFactory::charge($root, $sequence, 100, sequence: $sequence, fields: ['sourceAuthority' => 'remote-billium', 'partitionRef' => $partition, 'sourceLineage' => $lineage, ...$fields]);
        $o = $codec->decode(ObservationFactory::json($raw));
        self::assertInstanceOf(PaymentObservation::class, $o);

        return new PreparedFactualCommand('command-'.$root.'-'.substr(hash('sha256', $lineage), 0, 12).'-'.$sequence, $root, $o);
    }

    private function record(PreparedFactualCommand $cmd): void
    {
        $this->runner()->run($this->spec(class: ModuleOperationClass::ObservedFactRecording, modules: ['execution', 'platform'], financialScope: $this->selection()),
            function (TransactionContext $ctx) use ($cmd): PlatformReceipt {
                $this->application()->make(FactualCommandOutbox::class)->record($ctx, $cmd);

                return self::receipt();
            });
    }

    private function message(PreparedFactualCommand $fact): array
    {
        return ['schema' => 'billium.command.v1', 'commandRef' => $fact->commandRef, 'businessRef' => $fact->businessRef, 'commandType' => PaymentObservation::SCHEMA,
            'payloadDigest' => 'sha256:'.hash('sha256', (new Codec)->encode($fact->observation)), 'payload' => $fact->observation->raw];
    }

    private function callers(): ScopedCallerContext
    {
        return new readonly class($this->callers) implements ScopedCallerContext
        {
            public function __construct(private FixedCallerContext $inner) {}

            public function current(): Caller
            {
                return $this->inner->current();
            }

            public function run(Caller $caller, Closure $body): mixed
            {
                $previous = $this->current();
                $this->inner->set($caller);
                try {
                    return $body();
                } finally {
                    $this->inner->set($previous);
                }
            }
        };
    }

    private function receiver(IncomingCommandAuthenticator $host): PaymentObservationCommandReceiver
    {
        $app = $this->application();

        $app->instance(IncomingCommandAuthenticator::class, $host);
        $app->instance(ScopedCallerContext::class, $this->callers());

        return $app->make(PaymentObservationCommandReceiver::class);
    }

    /** The signing input comes from the simulated independently retained producer journal. */
    private function signed(PreparedFactualCommand $origin, string $owner = 'source-a', string $ttl = '+1 hour'): SourceAuthorityGrant
    {
        $binding = FactualCommandBinding::digest(PreparedFactualCommand::DESTINATION, $this->message($origin), $this->selection($owner));

        return new SourceAuthorityGrant(self::INSTALLATION, 'seller-a', 'test', 'remote-billium', $origin->observation->sourceLineage, PreparedFactualCommand::DESTINATION,
            'fixture-origin', new DateTimeImmutable($ttl), 'sha256:'.hash_hmac('sha256', $binding, 'external-fixture-signing-key'), $owner, $binding);
    }

    private function snapshot(string $ref, PreparedFactualCommand $fact, int $cursor, bool $empty = false): BootstrapPartition
    {
        $o = $fact->observation;
        $ns = ['channel' => 'payment.observation', 'sourceAuthority' => $o->sourceAuthority, 'sourceLineage' => $o->sourceLineage, 'sellerRef' => $o->sellerRef,
            'environment' => $o->environment, 'partitionRef' => $o->partitionRef];
        $items = $empty ? [] : [new BootstrapItem($o->operationRef, $o->operationRevision, EconomicDigest::of($o), $o->raw)];

        return new BootstrapPartition($ref, ...$ns, cursorSequence: $cursor, snapshotDigest: BootstrapPartition::digestOf($cursor, $items, $ns),
            sourceRetentionLeaseRef: 'lease-'.$ref, expectedItemCount: count($items), items: $items);
    }

    private function bootstrap(BootstrapPartition $origin, string $owner, string $root, string $account): BootstrapPartitionCommand
    {
        $scope = $this->selection($owner);
        $digest = VerifiedObservationBootstrap::digest($origin, $scope, $root, $account);
        $proof = 'sha256:'.hash_hmac('sha256', $digest, 'independent-snapshot-origin');
        $auth = $this->createMock(ObservationBootstrapAuthenticator::class);
        $auth->method('authenticate')->willReturnCallback(function ($snapshot) use ($scope, $root, $account, $digest, $proof): VerifiedObservationBootstrap {
            foreach ($this->application()->make(DatabaseManager::class)->getConnections() as $connection) {
                self::assertSame(0, $connection->transactionLevel());
            }
            if (! hash_equals($proof, 'sha256:'.hash_hmac('sha256', VerifiedObservationBootstrap::digest($snapshot, $scope, $root, $account), 'independent-snapshot-origin'))) {
                throw new ProblemException(ProblemCode::AuthDenied, 'Unsigned or altered manifest');
            }

            return new VerifiedObservationBootstrap($scope, $root, $account, 'snapshot-host', VerifiedObservationBootstrap::AUDIENCE, new DateTimeImmutable('+1 hour'), $digest, $proof);
        });
        $app = $this->application();
        $app->instance(ObservationBootstrapAuthenticator::class, $auth);
        $app->instance(ScopedCallerContext::class, $this->callers());

        return $app->make(BootstrapPartitionCommand::class);
    }

    public function test_new_physical_partition_requires_authenticated_empty_snapshot_and_holds_tail_until_adopted(): void
    {
        $this->activate();
        $this->settlement();
        $this->deliver($this->fact());
        $tail = $this->fact(fields: ['sourceLineage' => 'L2', 'operationRevision' => 2]);
        $this->refused(ProblemCode::RecoveryRequired, fn () => $this->deliver($tail));
        self::assertSame(1, $this->rows('billium_feed_partition', "state='recovery_required'"));
        self::assertSame(1, $this->rows('billium_inbox_message', "status='held_recovery' AND partition_id IS NULL"));
        $snapshot = $this->snapshot('replace-epoch', $tail, 0, true);
        $command = $this->bootstrap($snapshot, 'source-a', 'root-a', 'pa-1');
        $this->refused(ProblemCode::AuthDenied, fn (): BootstrapStatus => $command->executeQualified($this->snapshot('other', $tail, 1, true)));
        $this->refused(ProblemCode::InstallationQuarantined, fn (): BootstrapStatus => $command->execute($snapshot));
        self::assertSame(0, $this->rows('billium_feed_bootstrap_session'));
        $this->worker();
        $scope = $this->selection();
        $invented = new VerifiedObservationBootstrap($scope, 'root-a', 'pa-1', 'guessed', VerifiedObservationBootstrap::AUDIENCE, new DateTimeImmutable('+1 hour'),
            VerifiedObservationBootstrap::digest($snapshot, $scope, 'root-a', 'pa-1'), 'sha256:'.str_repeat('a', 64));
        $feed = $this->application()->make(FeedPartitions::class);
        $roots = $this->application()->make(ParkedDrainer::class);
        $this->refused(ProblemCode::AuthDenied, fn () => $roots->root(fn (TransactionContext $ctx): int => $feed->stageQualifiedBootstrap($ctx, $snapshot, $invented), scope: $scope));
        $status = $command->executeQualified($snapshot);
        $this->refused(ProblemCode::AuthDenied, fn () => $roots->root(fn (TransactionContext $ctx): BootstrapStatus => $feed->bootstrapStatus($ctx, $status->sessionId),
            mode: FinancialMode::RawCustody, commitKind: null, scope: $scope));
        self::assertSame('adopted', $status->status);
        self::assertSame(1, $this->rows('billium_feed_partition', "state='retired'"));
        self::assertSame(2, $this->rows('billium_inbox_message', "status='applied'"));
        self::assertSame(1, $this->rows('billium_allocation'));
        $this->worker();
        self::assertSame('adopted', $command->executeQualified($snapshot)->status);
        $foreign = $this->bootstrap($snapshot, 'source-a', 'retarget', 'pa-1');
        $this->refused(ProblemCode::ObservationConflict, fn (): BootstrapStatus => $foreign->executeQualified($snapshot));
        self::assertSame(1, $this->rows('billium_feed_bootstrap_session'));
    }

    public function test_recovery_bootstrap_parks_restart_without_inbox_and_sealed_b_cannot_consume_a_budget(): void
    {
        foreach (['source-b' => 'pa-b', 'source-a' => 'pa-1'] as $owner => $account) {
            $root = 'charge-'.$owner;
            $fact = $this->fact($root, fields: ['providerAccountRef' => $account]);
            $snapshot = $this->snapshot('bootstrap-'.$owner, $fact, 1);
            $status = $this->bootstrap($snapshot, $owner, $root, $account)->executeQualified($snapshot, FinancialMode::Recovery);
            self::assertSame(1, $status->parked);
        }
        self::assertSame(2, $this->rows('billium_observation_park', 'inbox_id IS NULL AND released_at IS NULL'));
        $this->activate();
        $this->settlement();
        $this->callers->set(new Caller('seller-a', 'test', self::INSTALLATION, 'queue', ['remote-billium'], ['source-b', 'source-a']));
        self::assertSame(['applied'], $this->application()->make(ParkedDrainer::class)->retryParked(1));
        self::assertSame(1, $this->rows('billium_payment', "operation_ref='charge-source-a'"));
        self::assertSame(0, $this->rows('billium_payment', "operation_ref='charge-source-b'"));
        self::assertSame(1, $this->rows('billium_observation_park', 'released_at IS NULL'));
        self::assertSame(1, $this->rows('billium_allocation'));
    }

    private function receiverFor(SourceAuthorityGrant $signed): PaymentObservationCommandReceiver
    {
        $host = $this->createMock(IncomingCommandAuthenticator::class);
        $host->method('authenticate')->willReturnCallback(function () use ($signed): SourceAuthorityGrant {
            foreach ($this->application()->make(DatabaseManager::class)->getConnections() as $connection) {
                self::assertSame(0, $connection->transactionLevel());
            }
            self::assertSame('sha256:'.hash_hmac('sha256', $signed->commandBindingDigest, 'external-fixture-signing-key'), $signed->proofDigest);

            return $signed;
        });

        return $this->receiver($host);
    }

    private function deliver(PreparedFactualCommand $fact, string $owner = 'source-a'): void
    {
        $this->receiverFor($this->signed($fact, $owner))->receive(PreparedFactualCommand::DESTINATION, $this->message($fact));
    }

    private function refused(ProblemCode $code, Closure $work): void
    {
        try {
            $work();
            self::fail('Expected '.$code->value);
        } catch (ProblemException $error) {
            self::assertSame($code, $error->problem, $error->getMessage());
        }
    }

    public function test_detached_binding_rejects_tampering_expiry_missing_origin_and_mixed_callers_before_custody(): void
    {
        $this->activate();
        $fact = $this->fact();
        $signed = $this->signed($fact);
        $msg = $this->message($fact);
        $receiver = $this->receiverFor($signed);
        foreach (['commandRef' => 'foreign-command', 'businessRef' => 'foreign-root', 'commandType' => 'payment.execute.v1', 'schema' => 'billium.command.v2', 'extra' => 'unsigned'] as $key => $change) {
            $this->refused(ProblemCode::AuthDenied, fn () => $receiver->receive(PreparedFactualCommand::DESTINATION, [...$msg, $key => $change]));
        }
        $stringPayload = [...$msg, 'payload' => (new Codec)->canonicalize($msg['payload'])];
        $this->refused(ProblemCode::AuthDenied, fn () => $receiver->receive(PreparedFactualCommand::DESTINATION, $stringPayload));
        $this->refused(ProblemCode::AuthDenied, fn () => $receiver->receive('foreign.inbox', $msg));
        $this->refused(ProblemCode::ValidationInvalid, fn () => $receiver->receive(PreparedFactualCommand::DESTINATION, [...$msg, 'payloadDigest' => 'sha256:'.str_repeat('f', 64)]));
        foreach (['sourceLineage' => 'unproven', 'partitionRef' => 'different', 'sellerRef' => 'seller-b', 'installationRef' => 'clone', 'sourceAuthority' => 'standalone-billium',
            'providerAccountRef' => 'pa-b', 'businessIntentRef' => 'guessed-owner'] as $key => $change) {
            $body = ObservationFactory::with($fact->observation->raw, [$key => $change]);
            $altered = [...$msg, 'payload' => $body, 'payloadDigest' => 'sha256:'.hash('sha256', ObservationFactory::json($body))];
            $this->refused(ProblemCode::AuthDenied, fn () => $receiver->receive(PreparedFactualCommand::DESTINATION, $altered));
        }
        $this->refused(ProblemCode::AuthDenied, fn () => $this->receiverFor($this->signed($fact, ttl: '-1 second'))->receive(PreparedFactualCommand::DESTINATION, $msg));
        $absent = $this->createMock(IncomingCommandAuthenticator::class);
        $absent->method('authenticate')->willThrowException(new ProblemException(ProblemCode::AuthDenied, 'Qualified credential has no independently attested original'));
        $this->refused(ProblemCode::AuthDenied, fn () => $this->receiver($absent)->receive(PreparedFactualCommand::DESTINATION, $msg));
        $this->callers->set(new Caller('seller-a', 'test', self::INSTALLATION, 'operator', ['remote-billium'], ['source-a', 'source-b']));
        $this->refused(ProblemCode::AuthDenied, fn () => $this->application()->make(QualifiedObservationApplier::class)->applyQualified(
            ObservationFactory::json($fact->observation->raw), $this->selection(), $signed->proofDigest));
        $this->callers->set(new Caller('seller-a', 'test', self::INSTALLATION, 'ordinary-authorized-operator', ['remote-billium'], ['source-a']));
        $this->refused(ProblemCode::AuthDenied, fn () => $this->application()->make(QualifiedObservationApplier::class)->applyQualified(
            ObservationFactory::json($fact->observation->raw), $this->selection(), $signed->proofDigest));
        self::assertSame(0, $this->rows('billium_inbox_message'));
        self::assertSame(0, $this->rows('billium_payment'));
    }

    public function test_orphan_and_retired_process_preserve_original_then_recover_only_for_the_admitted_worker(): void
    {
        $this->activate();
        $this->settlement();
        $tail = $this->fact(sequence: 2);
        $this->refused(ProblemCode::ObservationParked, fn () => $this->deliver($tail));
        self::assertSame(1, $this->rows('billium_inbox_message', 'partition_id IS NULL'));
        self::assertSame(1, $this->rows('billium_feed_observation_scope_link', 'inbox_id IS NOT NULL'));
        $token = $this->tokens->currentToken(self::INSTALLATION);
        self::assertNotNull($token);
        $this->authority->forgetProcess($token);
        $head = $this->fact();
        $this->refused(ProblemCode::InstallationQuarantined, fn () => $this->deliver($head));
        self::assertSame(0, $this->rows('billium_observed_payment'));
        $this->worker();
        $this->deliver($head);
        self::assertSame(2, $this->rows('billium_inbox_message', "status='applied'"));
        self::assertSame(2, $this->rows('billium_observed_payment_revision'));
        self::assertSame(100, self::integer($this->value('SELECT allocated_minor FROM billium_payment')));
        $this->refused(ProblemCode::ObservationConflict, fn () => $this->deliver($tail, 'source-b'));
        $this->refused(ProblemCode::ObservationConflict, fn () => $this->application()->make(ObservationApplier::class)->apply(ObservationFactory::json($head->observation->raw)));
        self::assertSame(2, $this->rows('billium_inbox_message'));
        self::assertSame(1, $this->rows('billium_observation_owner_binding'));
        self::assertSame(1, $this->rows('billium_allocation'));
    }

    public function test_account_collision_keeps_original_economic_identity_and_competing_raw_fact(): void
    {
        $this->activate();
        $this->settlement();
        $this->deliver($this->fact());
        $other = $this->fact(sequence: 2, fields: ['providerAccountRef' => 'pa-b']);
        $this->refused(ProblemCode::RecoveryRequired, fn () => $this->deliver($other, 'source-b'));
        self::assertSame(2, $this->rows('billium_feed_observation_root_scope'));
        self::assertSame(1, $this->rows('billium_inbox_message', "status='held_recovery' AND partition_id IS NULL"));
        self::assertSame(1, $this->rows('billium_observation_owner_binding', "intent_owner_namespace='source-a' AND provider_account_ref='pa-1'"));
        self::assertSame(1, $this->rows('billium_observed_payment_revision'));
        self::assertSame(1, $this->rows('billium_allocation'));
        $this->deliver($this->fact('independent', fields: ['providerAccountRef' => 'pa-1', 'businessIntentRef' => 'intent-a']));
        self::assertSame(2, $this->rows('billium_observation_owner_binding'));
    }

    public function test_legacy_ready_delivery_needs_independent_replay_before_qualified_drain(): void
    {
        $this->activate();
        $this->settlement();
        $fact = $this->fact();
        $this->refused(ProblemCode::InstallationQuarantined, fn () => $this->application()->make(ObservationApplier::class)->apply(ObservationFactory::json($fact->observation->raw)));
        self::assertSame(0, $this->rows('billium_feed_observation_scope_link'));
        $this->refused(ProblemCode::RecoveryRequired, fn () => $this->deliver($this->fact(sequence: 2)));
        self::assertSame(0, $this->rows('billium_observed_payment'));
        $this->deliver($fact);
        self::assertSame(2, $this->rows('billium_feed_observation_scope_link'));
        self::assertSame(1, $this->rows('billium_observation_owner_binding'));
        self::assertSame(1, $this->rows('billium_allocation'));
    }

    public function test_authenticated_command_ref_retarget_retains_conflicting_raw_fact_without_second_effect(): void
    {
        $this->activate();
        $this->settlement();
        $first = $this->fact();
        $other = $this->fact('different-root');
        $retarget = new PreparedFactualCommand($first->commandRef, $other->businessRef, $other->observation);
        $this->deliver($first);
        $this->refused(ProblemCode::ObservationConflict, fn () => $this->deliver($retarget));
        $this->refused(ProblemCode::ObservationConflict, fn () => $this->deliver($retarget, 'source-b'));
        $this->refused(ProblemCode::ObservationConflict, fn () => $this->deliver($retarget, 'source-b'));
        self::assertSame(1, $this->rows('billium_feed_observation_command', "disposition='received' AND payload->>'operationRef'='root-a'"));
        self::assertSame(2, $this->rows('billium_feed_observation_command', "disposition='conflict' AND payload->>'operationRef'='different-root'"));
        self::assertSame(1, $this->rows('billium_feed_observation_command', "disposition='conflict' AND intent_owner_namespace='source-b'"));
        self::assertSame(1, $this->rows('billium_payment'));
        self::assertSame(1, $this->rows('billium_allocation'));
    }

    public function test_raw_pdo_transaction_refuses_before_any_host_authentication(): void
    {
        $this->activate();
        $fact = $this->fact();
        $auth = $this->createMock(IncomingCommandAuthenticator::class);
        $auth->expects(self::never())->method('authenticate');
        $receiver = $this->receiver($auth);
        $connection = $this->application()->make(DatabaseManager::class)->connection('pgsql');
        $pdo = $connection->getPdo();
        $pdo->beginTransaction();
        try {
            self::assertSame(0, $connection->transactionLevel());
            $receiver->receive(PreparedFactualCommand::DESTINATION, $this->message($fact));
            self::fail('Raw PDO transaction reached host');
        } catch (LogicException $error) {
            self::assertStringContainsString('transaction level zero', $error->getMessage());
        } finally {
            $pdo->rollBack();
        }
    }

    public function test_sender_cannot_rebind_command_to_other_admitted_owner_in_recovery(): void
    {
        $this->callers->set(new Caller('seller-a', 'test', self::INSTALLATION, 'recovery', ['remote-billium'], ['source-a', 'source-b']));
        $fact = $this->fact();
        $record = (fn (string $owner): StoredOutcome => $this->runner()->run($this->spec(mode: FinancialMode::Recovery, class: ModuleOperationClass::ObservedFactRecording, modules: ['execution', 'platform'],
            financialScope: $this->selection($owner)), function (TransactionContext $ctx) use ($fact): PlatformReceipt {
                $this->application()->make(FactualCommandOutbox::class)->record($ctx, $fact);

                return self::receipt();
            }));
        $record('source-a');
        $this->refused(ProblemCode::IdempotencyConflict, fn (): StoredOutcome => $record('source-b'));
        self::assertSame(1, $this->rows('billium_outbox_command'));
        self::assertSame(1, $this->rows('billium_outbox_command_delivery'));
        self::assertSame('source-a', $this->value("SELECT source_scope::jsonb->>'intentOwnerNamespace' FROM billium_outbox_command"));
    }

    public function test_complete_signed_snapshot_qualifies_each_legacy_observed_revision_and_missing_items_hold(): void
    {
        $first = $this->fact();
        $second = $this->fact(sequence: 2);
        $snapshot = $this->snapshot('legacy-history', $first, 2);
        $items = [...$snapshot->items, ...$this->snapshot('second', $second, 2)->items];
        $full = fn (string $ref, array $items): BootstrapPartition => new BootstrapPartition($ref, ...$snapshot->namespace(),
            cursorSequence: 2, snapshotDigest: BootstrapPartition::digestOf(2, $items, $snapshot->namespace()), sourceRetentionLeaseRef: 'pinned-history', expectedItemCount: count($items), items: $items);
        $token = $this->tokens->currentToken(self::INSTALLATION);
        $this->authority->issueProcess($token, [new FinancialScope(self::INSTALLATION, 'seller-a', 'test', null, 'remote-billium')]);
        $this->application()->make(BootstrapPartitionCommand::class)->execute($full('old', $items), FinancialMode::Recovery);
        self::assertSame(2, $this->rows('billium_observed_payment_revision'));
        self::assertSame(0, $this->rows('billium_observation_owner_binding'));
        $this->activate();
        $this->settlement();
        $partial = $full('missing-one', [$items[1]]);
        $this->refused(ProblemCode::RecoveryRequired, fn (): BootstrapStatus => $this->bootstrap($partial, 'source-a', 'root-a', 'pa-1')->executeQualified($partial));
        self::assertSame(0, $this->rows('billium_payment'));
        $bad = $this->fact(sequence: 2, fields: ['evidenceRef' => 'replaced-history']);
        $contradiction = $full('altered-history', [$items[0], $this->snapshot('altered', $bad, 2)->items[0]]);
        $this->refused(ProblemCode::RecoveryRequired, fn (): BootstrapStatus => $this->bootstrap($contradiction, 'source-a', 'root-a', 'pa-1')->executeQualified($contradiction));
        self::assertSame(0, $this->rows('billium_observed_revision_owner_proof'));
        $complete = $full('proven-history', $items);
        $this->bootstrap($complete, 'source-a', 'root-a', 'pa-1')->executeQualified($complete);
        self::assertSame(2, $this->rows('billium_observed_payment_revision'));
        self::assertSame(2, $this->rows('billium_observed_revision_owner_proof', "intent_owner_namespace='source-a'"));
        self::assertSame(1, $this->rows('billium_observation_owner_binding'));
        self::assertSame(1, $this->rows('billium_payment'));
        self::assertSame(1, $this->rows('billium_allocation'));
    }

    public function test_cold_relay_process_exits_after_apply_and_a_second_process_recovers_abandoned_claim(): void
    {
        $this->activate();
        $this->settlement();
        $fact = $this->fact();
        $origin = base64_encode(serialize($this->signed($fact)));
        $this->record($fact);
        $key = getenv('BILLIUM_SIMULATED_AUTHORITY_KEY');
        $session = base64_encode(serialize($this->signedSession));
        $args = ['session' => $session, 'signature' => hash_hmac('sha256', $session, $key), 'origin' => $origin, 'originSignature' => hash_hmac('sha256', $origin, $key), 'admitted' => true];
        $first = ConcurrencyStand::run(QualifiedDrainWorker::class, 1, [...$args, 'exitBeforeAck' => true])[0]['ok'];
        self::assertSame('applied_before_ack', $first['checkpoint']);
        self::assertSame(1, $this->rows('billium_outbox_command_delivery', "status='in_flight' AND delivered_at IS NULL"));
        self::assertSame(1, $this->rows('billium_allocation'));
        self::assertSame(1, $this->rows('billium_payment_revision'));
        $deadline = microtime(true) + 5;
        while (! $this->value('SELECT claim_until<=clock_timestamp() FROM billium_outbox_command_delivery') && microtime(true) < $deadline) {
            usleep(10_000);
        }
        $second = ConcurrencyStand::run(QualifiedDrainWorker::class, 1, [...$args, 'exitBeforeAck' => false])[0]['ok'];
        self::assertSame(1, $second['delivered']);
        self::assertSame(0, $second['failed']);
        self::assertSame(1, $this->rows('billium_outbox_command_delivery', "status='delivered' AND attempts=2"));
        self::assertSame(1, $this->rows('billium_inbox_message', "status='applied'"));
        self::assertSame(1, $this->rows('billium_payment_revision'));
        self::assertSame(1, $this->rows('billium_allocation'));
        self::assertNotSame($first['pg'], $second['pg']);
        self::assertNotSame($first['php'], $second['php']);
        fwrite(STDOUT, 'Qualified abandoned-claim backends '.json_encode([$first, $second], JSON_THROW_ON_ERROR).PHP_EOL);
    }

    public function test_process_death_after_custody_recovers_with_external_admission_on_another_postgres_backend(): void
    {
        $this->activate();
        $this->settlement();
        $fact = $this->fact();
        $token = $this->tokens->currentToken(self::INSTALLATION);
        self::assertNotNull($token);
        $this->authority->forgetProcess($token);
        $this->refused(ProblemCode::InstallationQuarantined, fn () => $this->deliver($fact));
        $identity = base64_encode(serialize($this->signedSession));
        $args = ['session' => $identity, 'signature' => hash_hmac('sha256', $identity, getenv('BILLIUM_SIMULATED_AUTHORITY_KEY')),
            'partition' => self::integer($this->value('SELECT id FROM billium_feed_partition'))];
        $denied = ConcurrencyStand::run(QualifiedDrainWorker::class, 1, [...$args, 'admitted' => false]);
        self::assertSame(ProblemCode::InstallationQuarantined->value, $denied[0]['error']['code']);
        self::assertSame(0, $this->rows('billium_payment'));
        $resumed = ConcurrencyStand::run(QualifiedDrainWorker::class, 2, [...$args, 'admitted' => true]);
        $parent = self::integer($this->value('SELECT pg_backend_pid()'));
        $backends = [];
        $children = [];
        foreach ($resumed as $result) {
            self::assertArrayHasKey('ok', $result);
            $backends[] = $result['ok']['pg'];
            $children[] = $result['ok']['php'];
        }
        self::assertCount(3, array_unique([$parent, ...$backends]));
        self::assertCount(3, array_unique([getmypid(), ...$children]));
        fwrite(STDOUT, 'Qualified restart PostgreSQL backends '.json_encode([$parent, ...$backends], JSON_THROW_ON_ERROR)
            .' PHP processes '.json_encode([getmypid(), ...$children], JSON_THROW_ON_ERROR).PHP_EOL);
        self::assertSame(1, $this->rows('billium_payment_revision'));
        self::assertSame(1, $this->rows('billium_allocation'));
        self::assertSame(1, $this->rows('billium_inbox_message', "status='applied'"));
    }

    public function test_qualified_outbox_delivery_survives_sender_restart_and_lost_ack_under_partial_activation(): void
    {
        $this->activate();
        $this->settlement();
        $fact = $this->fact();
        $journal = $this->signed($fact);
        $this->record($fact);
        $this->record($fact);
        $db = $this->application()->make(DatabaseManager::class)->connection($this->application()->make(BilliumOptions::class)->connection);
        $this->worker();
        $this->refused(ProblemCode::InstallationQuarantined, fn () => $this->deliver($this->fact('root-b', fields: ['providerAccountRef' => 'pa-b']), 'source-b'));
        $stored = json_decode(self::text($this->value('SELECT source_scope FROM billium_outbox_command')), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('source-a', $stored['intentOwnerNamespace']);
        self::assertSame('remote-billium', $stored['sourceAuthority']);
        $legacy = $this->createMock(EventPublisher::class);
        $legacy->expects(self::never())->method('publish');
        $relay = new CommandOutboxRelay($db, $legacy, budget: 1);
        self::assertSame(1, $relay->relay()->failed);
        self::assertTrue($relay->requeue($fact->commandRef, PreparedFactualCommand::DESTINATION));
        $calls = 0;
        $host = $this->createMock(QualifiedFactualCommandPublisher::class);
        $host->expects(self::never())->method('publish');
        $host->method('publishQualified')->willReturnCallback(function ($dest, $message, $scope) use ($journal, &$calls, $db, $fact): void {
            self::assertSame(0, $db->transactionLevel());
            self::assertEquals($this->selection(), $scope);
            self::assertCount(6, $message);
            self::assertSame((new Codec)->canonicalize($this->message($fact)), (new Codec)->canonicalize($message));
            $this->receiverFor($journal)->receive($dest, $message);
            if (++$calls === 1) {
                throw new LogicException('Lost acknowledgement after projection commit');
            }
        });
        $relay = new CommandOutboxRelay($db, $host, budget: 1);
        self::assertSame(1, $relay->relay()->failed);
        self::assertSame(1, $this->rows('billium_observed_payment_revision'));
        self::assertSame(1, $this->rows('billium_payment_revision'));
        self::assertSame(1, $this->rows('billium_allocation'));
        self::assertSame(100, self::integer($this->value('SELECT allocated_minor FROM billium_payment')));
        self::assertSame(1, $this->rows('billium_inbox_message', "status='ready' AND operation_ref='root-b'"));
        self::assertSame(1, $this->rows('billium_observation_owner_binding', "intent_owner_namespace='source-a'"));
        self::assertTrue($relay->requeue($fact->commandRef, PreparedFactualCommand::DESTINATION));
        $this->worker();
        self::assertSame(1, $relay->relay()->delivered);
        self::assertSame(1, $this->rows('billium_allocation'));
        self::assertSame(1, $this->rows('billium_allocation_receipt', "kind='payment_observation'"));
        self::assertSame(1, $this->rows('billium_outbox_command_delivery', "status='delivered'"));
        self::assertSame(2, $calls);
    }
}
