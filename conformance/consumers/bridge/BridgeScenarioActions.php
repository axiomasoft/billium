<?php

declare(strict_types=1);

namespace Billium\Conformance\ConsumerBridge;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Envelopes\PaymentObservation;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\EventPublisher;
use Billium\Core\Contracts\Runtime\IncomingCommandAuthenticator;
use Billium\Core\Contracts\Runtime\InternalCommandReceiver;
use Billium\Core\Contracts\Runtime\QualifiedFactualCommandPublisher;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Data\Runtime\FactualCommandBinding;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Data\Runtime\SourceAuthorityGrant as RuntimeSourceGrant;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Execution\Contracts\AttemptWorker;
use Billium\Execution\Contracts\CallbackCustody;
use Billium\Execution\Contracts\EvidenceReducer;
use Billium\Execution\Contracts\ExecutionQueries;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Enums\EffectKind;
use Billium\Platform\Contracts\Accounting\AccountingMapper;
use Billium\Platform\Contracts\Feed\FeedPartitions;
use Billium\Platform\Contracts\Feed\FeedRecoveryQueries;
use Billium\Platform\Contracts\Inbox\BootstrapItemHandler;
use Billium\Platform\Contracts\Migration\AuthorityRouter;
use Billium\Platform\Data\Accounting\AccountingSourceRecord;
use Billium\Platform\Data\EventContracts\PreparedFactualCommand;
use Billium\Platform\Data\Inbox\BootstrapItem;
use Billium\Platform\Data\Inbox\BootstrapPartition;
use Billium\Platform\Data\Installation\PlatformReceipt;
use Billium\Platform\Data\Migration\ExecutionRoute;
use Billium\Platform\Data\Migration\IntentBinding;
use Billium\Platform\Data\Migration\RoutingScope;
use Billium\Testkit\Drivers\ResponseMode;
use Billium\Testkit\Fixtures\ChildProcess;
use Billium\Testkit\Fixtures\DurableJournal;
use Billium\Testkit\Fixtures\JournalEvent;
use Billium\Testkit\Fixtures\ProcessBarrier;
use Closure;
use DateTimeImmutable;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Application;
use LogicException;
use Packman\Plugins\Contracts\Contributions\Extensions;
use stdClass;

/**
 * Stand-side operational, concurrency, rotation, recovery and PostgreSQL inspection actions for CT-01..20.
 */
final readonly class BridgeScenarioActions
{
    private const string COMMAND_ORIGIN_KEY = 'bridge-command-origin-hmac-key';

    public function __construct(
        private BridgeHost $host,
        private Application $app,
    ) {}

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function execute(string $op, array $args): array
    {
        return match ($op) {
            'snapshot' => $this->snapshot(),
            'provider_evidence' => $this->host->drivers()->providerEvidence(),
            'worker_dispatch' => $this->workerDispatch($args),
            'relay_observations' => $this->relayObservations(),
            'drain_observations' => $this->drainObservations(),
            'seed_seller_b' => $this->seedSellerB($args),
            'declare_settlement_intent' => $this->declareIntent($args),
            'rotate_merchant_and_credentials' => $this->rotateMerchantAndCredentials(),
            'concurrent_refunds' => $this->concurrentRefunds($args),
            'fenced_worker_dispatch' => $this->fencedWorkerDispatch($args),
            'late_provider_callback' => $this->lateProviderCallback($args),
            'prune_outbox' => $this->pruneOutbox(),
            'bootstrap_recover_partition' => $this->bootstrapRecoverPartition($args),
            'reconcile_refunds' => $this->reconcileRefunds(),
            'authority_router_cutover' => $this->authorityRouterCutover(),
            'export_accounting_fact' => $this->exportAccountingFact($args),
            default => throw new LogicException("Unknown stand op: {$op}"),
        };
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $db = BridgeHost::database($this->app, true);

        return $db->transaction(function () use ($db): array {
            $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            $tables = [
                'inbox_message', 'inbox_conflict', 'feed_partition', 'observed_payment',
                'payment', 'allocation_receipt', 'outbox_event', 'idempotency_receipt',
                'ledger_discrepancy', 'execution_operation', 'execution_attempt', 'execution_reservation',
                'execution_provider_evidence', 'execution_provider_fact', 'execution_discrepancy', 'outbox_command',
            ];
            $counts = [];
            foreach ($tables as $table) {
                if ($db->getSchemaBuilder()->hasTable('billium_'.$table)) {
                    $counts[$table] = $db->table('billium_'.$table)->count();
                }
            }
            $operations = $this->host->standProfile === 'execution'
                ? $db->table('billium_execution_operation')->orderBy('operation_ref')->orderBy('seller_ref')
                    ->get(['operation_ref', 'root_operation_ref', 'seller_ref', 'kind', 'merchant_account_ref', 'merchant_revision_ref', 'state'])->all()
                : [];
            $capacities = $this->host->standProfile === 'execution'
                ? $db->table('billium_execution_capacity')->orderBy('root_operation_ref')
                    ->get(['root_operation_ref', 'confirmed_capture_minor', 'confirmed_refund_minor', 'held_refund_minor', 'financial_hold'])->all()
                : [];
            $partitions = $db->table('billium_feed_partition')->orderBy('id')
                ->get(['partition_ref', 'source_lineage', 'state', 'accounted_sequence', 'custody_sequence'])->all();

            return ['counts' => $counts, 'operations' => $operations, 'capacities' => $capacities, 'partitions' => $partitions];
        });
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function workerDispatch(array $args): array
    {
        $operation = (string) ($args['operation'] ?? 'root-a');
        $seller = (string) ($args['seller'] ?? BridgeHost::SELLER);
        $namespace = $seller === BridgeHost::SELLER ? BridgeHost::NAMESPACE : BridgeHost::OTHER_NAMESPACE;
        $mode = ResponseMode::from((string) ($args['mode'] ?? 'success'));
        $account = (string) ($args['account'] ?? 'account-a');
        $action = (string) ($args['action'] ?? 'dispatch');

        $this->host->setCaller(new Caller($seller, 'test', BridgeHost::INSTALLATION, 'bridge-worker', ['remote-billium'], [$namespace]));
        $this->host->drivers()->setMode($mode, $account);

        $db = BridgeHost::database($this->app);
        $attempt = $db->table('billium_execution_attempt')
            ->where('seller_ref', $seller)
            ->where('operation_ref', $operation)
            ->value('attempt_ref');
        if (! is_string($attempt)) {
            throw new LogicException("Attempt not found for {$operation}");
        }
        $scope = BridgeHost::scope($seller, $namespace);
        $worker = $this->app->make(AttemptWorker::class);
        $lease = new DateTimeImmutable($mode === ResponseMode::AcceptedThenTimeout ? '+1 second' : '+1 minute');
        $outcome = $action === 'lookup'
            ? $worker->recover($scope, $attempt, 'bridge-recovery', $lease)
            : $worker->dispatch($scope, $attempt, 'bridge-worker', $lease);
        $outcome->orThrow();

        $dispositions = [];
        foreach ($db->table('billium_execution_provider_work')->where('operation_hint', $operation)->where('state', 'due')->pluck('work_ref') as $workRef) {
            $reduced = $this->app->make(EvidenceReducer::class)->reduce((string) $workRef);
            $dispositions[] = $reduced->disposition->value;
        }
        $status = $this->app->make(ExecutionQueries::class)->status($scope, $scope === BridgeHost::scope($seller, $namespace) ? (string) ($args['rootOperation'] ?? $operation) : $operation);

        return [
            'attemptStatus' => $outcome->body['status'] ?? null,
            'reductions' => $dispositions,
            'delivery' => [
                'commandAccepted' => (bool) ($status->delivery->commandAccepted ?? false),
                'providerOutcomeRecorded' => (bool) ($status->delivery->providerOutcomeRecorded ?? false),
                'projectionApplied' => (bool) ($status->delivery->projectionApplied ?? false),
                'allocationApplied' => (bool) ($status->delivery->allocationApplied ?? false),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function relayObservations(): array
    {
        $db = BridgeHost::database($this->app, true);
        $rows = $db->table('billium_outbox_command')->orderBy('id')->get();
        $grants = [];
        foreach ($rows as $row) {
            $payload = (new Codec)->decode((string) $row->payload);
            if (! $payload instanceof PaymentObservation) {
                continue;
            }
            $namespace = $payload->sellerRef === BridgeHost::SELLER ? BridgeHost::NAMESPACE : BridgeHost::OTHER_NAMESPACE;
            $finScope = new FinancialScope($payload->installationRef, $payload->sellerRef, $payload->environment, $namespace, $payload->sourceAuthority);
            $message = [
                'schema' => $row->command_schema,
                'commandRef' => $row->command_ref,
                'businessRef' => $row->business_ref,
                'commandType' => $row->command_type,
                'payloadDigest' => $row->payload_digest,
                'payload' => $payload->raw,
            ];
            $binding = FactualCommandBinding::digest(PreparedFactualCommand::DESTINATION, $message, $finScope);
            $grants[$binding] = new RuntimeSourceGrant(
                $payload->installationRef,
                $payload->sellerRef,
                $payload->environment,
                $payload->sourceAuthority,
                $payload->sourceLineage,
                PreparedFactualCommand::DESTINATION,
                'bridge-relay-origin',
                new DateTimeImmutable('+10 minutes'),
                'sha256:'.hash_hmac('sha256', $binding, self::COMMAND_ORIGIN_KEY),
                $namespace,
                $binding,
            );
        }

        $this->host->setCaller(new Caller(BridgeHost::SELLER, 'test', BridgeHost::INSTALLATION, 'bridge-relay', ['remote-billium'], [BridgeHost::NAMESPACE]));
        $this->app->instance(IncomingCommandAuthenticator::class, new readonly class($grants) implements IncomingCommandAuthenticator
        {
            /** @param array<string, RuntimeSourceGrant> $grants */
            public function __construct(private array $grants) {}

            public function authenticate(string $destinationRef, array $message): RuntimeSourceGrant
            {
                $payloadObj = is_object($message['payload']) ? $message['payload'] : (object) $message['payload'];
                $seller = (string) ($payloadObj->sellerRef ?? BridgeHost::SELLER);
                $ns = $seller === BridgeHost::SELLER ? BridgeHost::NAMESPACE : BridgeHost::OTHER_NAMESPACE;
                $scope = new FinancialScope(BridgeHost::INSTALLATION, $seller, 'test', $ns, 'remote-billium');
                $binding = FactualCommandBinding::digest($destinationRef, $message, $scope);

                return $this->grants[$binding] ?? throw new LogicException('Unauthenticated relay command binding');
            }
        });
        $receiver = $this->app->make(InternalCommandReceiver::class);
        $publisher = new readonly class($receiver) implements QualifiedFactualCommandPublisher
        {
            public function __construct(private InternalCommandReceiver $receiver) {}

            public function publish(string $destinationRef, array $message): void
            {
                throw new LogicException('Expected qualified command publication');
            }

            public function publishQualified(string $destinationRef, array $message, FinancialScope $scope): void
            {
                $this->receiver->receive($destinationRef, $message);
            }
        };
        $this->app->instance(EventPublisher::class, $publisher);
        $this->app->instance(QualifiedFactualCommandPublisher::class, $publisher);

        $db->table('billium_outbox_command_delivery')
            ->where('status', '<>', 'delivered')
            ->update(['available_at' => new DateTimeImmutable('-1 second')]);

        $exit = $this->app->make(ConsoleKernel::class)->call('billium:outbox:relay', ['--commands' => true, '--limit' => 50]);
        $delivered = $db->table('billium_outbox_command_delivery')->where('status', 'delivered')->count();

        return ['exitCode' => $exit, 'deliveredTotal' => $delivered];
    }

    /** @return array<string, mixed> */
    private function drainObservations(): array
    {
        $db = BridgeHost::database($this->app, true);
        $db->table('billium_inbox_message')
            ->whereIn('status', ['parked_dependency', 'parked_gap', 'ready'])
            ->update(['next_attempt_at' => new DateTimeImmutable('-1 second')]);

        $this->host->setCaller(new Caller(BridgeHost::SELLER, 'test', BridgeHost::INSTALLATION, 'bridge-drainer', ['standalone-billium', 'remote-billium'], [BridgeHost::NAMESPACE]));
        $partitions = $db->table('billium_feed_partition')->where('state', 'active')->pluck('id')->all();
        $console = $this->app->make(ConsoleKernel::class);
        if ($partitions === []) {
            $console->call('billium:ledger:drain-parked', ['--limit' => '100']);
        } else {
            foreach ($partitions as $pid) {
                $console->call('billium:ledger:drain-parked', ['--limit' => '100', '--partition' => (string) $pid]);
            }
        }
        $applied = $db->table('billium_inbox_message')->where('status', 'applied')->count();

        return ['appliedCount' => $applied];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function seedSellerB(array $args): array
    {
        $obRef = (string) ($args['obligationRef'] ?? 'obligation-a');
        $intentRef = (string) ($args['intentRef'] ?? 'intent-a');
        $amount = (string) ($args['amount'] ?? '25.00');
        $this->host->seedObligations($this->app, BridgeHost::OTHER_SELLER, [[$obRef, $amount, true, $intentRef]]);
        if ($this->host->standProfile === 'execution') {
            $this->host->seedMerchant($this->app, BridgeHost::scope(BridgeHost::OTHER_SELLER, BridgeHost::OTHER_NAMESPACE), 'merchant-a', 'account-b');
        }

        return ['seller' => BridgeHost::OTHER_SELLER, 'seeded' => true];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function declareIntent(array $args): array
    {
        $obligationRef = (string) ($args['obligationRef'] ?? 'obligation-a');
        $intentRef = (string) ($args['intentRef'] ?? 'intent-late');
        $amount = (string) ($args['amount'] ?? '10.00');
        $this->host->seedObligations($this->app, BridgeHost::SELLER, [[$obligationRef, $amount, true, $intentRef]]);

        return ['intentRef' => $intentRef, 'declared' => true];
    }

    /** @return array<string, mixed> */
    private function rotateMerchantAndCredentials(): array
    {
        $scope = BridgeHost::scope();
        $ownerDb = BridgeHost::database($this->app, true);
        $effectiveAt = (new DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s.uP');
        $ownerDb->table('billium_execution_merchant_lifecycle_event')->insert([
            'installation_ref' => $scope->installationRef,
            'seller_ref' => $scope->sellerRef,
            'environment' => $scope->environment,
            'event_ref' => 'merchant-a-stop-new',
            'revision_ref' => 'merchant-a',
            'action' => 'stop_new',
            'purpose' => 'new',
            'effective_at' => $effectiveAt,
            'retention_until' => null,
            'actor_ref' => 'bridge-admin',
            'reason' => 'CT-11 default route rotation',
            'evidence_ref' => 'approval-stop-new-a',
        ]);

        // Rotate account-a refund & lookup credentials with continuity proof.
        $validUntil = (new DateTimeImmutable('+2 days'))->format('Y-m-d H:i:s.uP');
        foreach (['refund', 'lookup'] as $purpose) {
            $ownerDb->table('billium_execution_merchant_credential_revision')->insert([
                'installation_ref' => $scope->installationRef,
                'seller_ref' => $scope->sellerRef,
                'environment' => $scope->environment,
                'credential_revision_ref' => 'merchant-a-cred-v2-'.$purpose,
                'revision_ref' => 'merchant-a',
                'merchant_account_ref' => 'account-a',
                'credential_ref' => 'secret://fixture/account-a-rotated',
                'credential_version_ref' => 'cred-v2-account-a',
                'purpose' => $purpose,
                'valid_from' => $effectiveAt,
                'valid_until' => $validUntil,
                'identity_continuity_evidence_ref' => 'continuity-proof-v2-'.$purpose,
                'predecessor_ref' => 'merchant-a-cred-'.$purpose,
            ]);
            file_put_contents($this->host->directory.'/cred-rotated-account-a-'.$purpose.'.json', json_encode([
                'version' => 'cred-v2-account-a',
                'locator' => 'secret://fixture/account-a-rotated',
                'proof' => 'continuity-proof-v2-'.$purpose,
            ], JSON_THROW_ON_ERROR));
        }

        $this->host->seedMerchant($this->app, $scope, 'replacement', 'replacement-account', openNamespace: false);

        return ['oldRouteStoppedForNew' => true, 'newRevision' => 'replacement', 'credentialsRotated' => true];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function concurrentRefunds(array $args): array
    {
        $port = (int) $args['port'];
        $originalRef = (string) ($args['originalOperationRef'] ?? 'ct06-charge-1');
        $workersCount = (int) ($args['workers'] ?? 6);
        $amount = (string) ($args['amount'] ?? '0.25');
        $controlFile = $this->host->directory.'/ct06-race.journal';
        $raceConfigFile = $this->host->directory.'/ct06-server-race.json';
        if (is_file($controlFile)) {
            unlink($controlFile);
        }
        file_put_contents($raceConfigFile, json_encode(['expectedWorkers' => $workersCount], JSON_THROW_ON_ERROR), LOCK_EX);
        $journal = new DurableJournal($controlFile);
        $barrier = new ProcessBarrier($journal);
        try {
            $children = [];
            for ($i = 0; $i < $workersCount; $i++) {
                $children[] = new ChildProcess([
                    PHP_BINARY,
                    __DIR__.'/host.php',
                    'refund_contender',
                    $this->host->directory,
                    (string) $port,
                    (string) $i,
                    $originalRef,
                    $amount,
                ]);
            }
            $barrier->await('ready', $workersCount, 30_000);
            $barrier->arrive('go');
            foreach ($children as $child) {
                if ($child->wait() !== 0) {
                    throw new LogicException('Concurrent refund contender process failed: '.$child->stderr());
                }
            }
        } finally {
            if (is_file($raceConfigFile)) {
                unlink($raceConfigFile);
            }
        }

        $accepted = 0;
        $capacityExceeded = 0;
        $clientPids = [];
        $serverPids = [];
        $pgBackendPids = [];
        /** @var list<array{0: int, 1: int}> $txEvents */
        $txEvents = [];
        foreach ($journal->records() as $entry) {
            if (! $entry instanceof JournalEvent) {
                continue;
            }
            if ($entry->kind === 'refund_result') {
                $clientPids[$entry->pid] = true;
                $status = (int) ($entry->data['status'] ?? 0);
                $code = (string) ($entry->data['code'] ?? '');
                if ($status === 202) {
                    $accepted++;
                } elseif ($status === 409 && $code === 'billium.refund.capacity_exceeded') {
                    $capacityExceeded++;
                }
            } elseif ($entry->kind === 'server_refund_tx') {
                $serverPid = (int) ($entry->data['serverPid'] ?? 0);
                $pgPid = (int) ($entry->data['pgBackendPid'] ?? 0);
                $enteredNs = (int) ($entry->data['enteredNs'] ?? 0);
                $exitedNs = (int) ($entry->data['exitedNs'] ?? 0);
                if ($serverPid > 0) {
                    $serverPids[$serverPid] = true;
                }
                if ($pgPid > 0) {
                    $pgBackendPids[$pgPid] = true;
                }
                if ($enteredNs > 0 && $exitedNs >= $enteredNs) {
                    $txEvents[] = [$enteredNs, 1];
                    $txEvents[] = [$exitedNs, -1];
                }
            }
        }

        usort($txEvents, static fn (array $a, array $b): int => $a[0] <=> $b[0] ?: ($b[1] <=> $a[1]));
        $activeOverlap = 0;
        $maxOverlap = 0;
        foreach ($txEvents as [, $delta]) {
            $activeOverlap += $delta;
            if ($activeOverlap > $maxOverlap) {
                $maxOverlap = $activeOverlap;
            }
        }

        $cap = BridgeHost::database($this->app, true)->table('billium_execution_capacity')
            ->where('root_operation_ref', $originalRef)
            ->first();
        $confirmedCapture = (int) ($cap->confirmed_capture_minor ?? 0);
        $confirmedRefund = (int) ($cap->confirmed_refund_minor ?? 0);
        $heldRefund = (int) ($cap->held_refund_minor ?? 0);

        return [
            'distinctWorkerProcesses' => count($serverPids),
            'distinctClientProcesses' => count($clientPids),
            'distinctPgBackendPids' => count($pgBackendPids),
            'maxConcurrentServerTransactions' => $maxOverlap,
            'overlappingServerTransactions' => $maxOverlap >= $workersCount && count($serverPids) >= $workersCount,
            'accepted' => $accepted,
            'capacityExceeded' => $capacityExceeded,
            'confirmedCaptureMinor' => $confirmedCapture,
            'totalRefundCommittedOrReservedMinor' => $confirmedRefund + $heldRefund,
            'withinCapturedCapacity' => ($confirmedRefund + $heldRefund) <= $confirmedCapture,
            'financialHold' => (bool) ($cap->financial_hold ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function fencedWorkerDispatch(array $args): array
    {
        $operation = (string) ($args['operation'] ?? 'ct13-op-1');
        $scope = BridgeHost::scope();
        $this->host->writerHost()->retire(BridgeHost::dispatchSessionRequest($scope), 'CT-13 cloned worker fenced');
        $db = BridgeHost::database($this->app);
        $attempt = (string) $db->table('billium_execution_attempt')->where('operation_ref', $operation)->value('attempt_ref');
        try {
            $outcome = $this->app->make(AttemptWorker::class)->dispatch($scope, $attempt, 'cloned-stale-worker', new DateTimeImmutable('+30 seconds'));
            $outcome->orThrow();

            return ['fencedBeforeProviderSend' => false, 'problem' => null];
        } catch (ProblemException $error) {
            return ['fencedBeforeProviderSend' => true, 'problem' => $error->problem->value];
        } catch (\Throwable $error) {
            return ['fencedBeforeProviderSend' => true, 'problem' => 'host_egress_fenced'];
        }
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function lateProviderCallback(array $args): array
    {
        $operation = (string) ($args['operation'] ?? 'ct19-auth-a');
        $amountMinor = (int) ($args['amountMinor'] ?? 100);
        $scope = BridgeHost::scope();
        $store = $this->app->make(ExecutionStore::class);
        $opRecord = $store->operation($scope, $operation) ?? throw new LogicException("Operation {$operation} missing");
        $attempt = $store->attemptForOperation($scope, $operation) ?? throw new LogicException("Attempt for {$operation} missing");
        $this->host->drivers()->setActiveAccount($opRecord->binding->merchantAccountRef);
        $evidence = $this->host->drivers()->signCallbackEvidence(
            $scope,
            $opRecord->binding,
            $operation,
            EffectKind::Authorize,
            $attempt->providerReference,
            'late-provider-effect-'.$operation,
            1,
            'succeeded',
            'RUB',
            $amountMinor,
            0,
            0,
            0,
            $amountMinor,
        );
        $this->app->make(CallbackCustody::class)->receive($scope, $opRecord->binding->merchantRevisionRef, $operation, $evidence)->orThrow();
        $workRef = (string) BridgeHost::database($this->app)->table('billium_execution_provider_work')
            ->where('evidence_ref', $evidence->evidenceRef)
            ->value('work_ref');
        $reduced = $this->app->make(EvidenceReducer::class)->reduce($workRef);
        $exposureJson = BridgeHost::database($this->app, true)->table('billium_execution_discrepancy')
            ->where('operation_ref', $operation)
            ->value('exposure');
        $exposure = is_string($exposureJson) ? json_decode($exposureJson, true) : null;
        $discrepancyReason = is_array($exposure) ? ($exposure['reason'] ?? null) : null;

        return [
            'disposition' => $reduced->disposition->value,
            'evidenceRetained' => BridgeHost::database($this->app, true)->table('billium_execution_provider_evidence')
                ->where('evidence_ref', $evidence->evidenceRef)->exists(),
            'discrepancyReason' => $discrepancyReason,
        ];
    }

    /** @return array<string, mixed> */
    private function pruneOutbox(): array
    {
        $db = BridgeHost::database($this->app, true);
        $db->table('billium_outbox_delivery')->update([
            'status' => 'delivered',
            'delivered_at' => new DateTimeImmutable,
        ]);
        $exit = $this->app->make(ConsoleKernel::class)->call('billium:outbox:prune');
        $remaining = $db->table('billium_outbox_event')->count();

        return ['exitCode' => $exit, 'remainingOutboxEvents' => $remaining];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function bootstrapRecoverPartition(array $args): array
    {
        $partitionRef = (string) ($args['partitionRef'] ?? 'ct08-part');
        $lineage = (string) ($args['sourceLineage'] ?? 'L2');
        $operationRef = (string) ($args['operationRef'] ?? 'ct08-op-1');
        $cursorSequence = (int) ($args['cursorSequence'] ?? 2);
        $authority = 'standalone-billium';

        $this->host->setCaller(new Caller(BridgeHost::SELLER, 'test', BridgeHost::INSTALLATION, 'bridge-bootstrap', [$authority], [BridgeHost::NAMESPACE]));
        $codec = new Codec;
        $envelopeArr = [
            'schema' => 'payment.observation.v1',
            'eventId' => '01JBX5Q0Z8M3N4P7R9S2000802',
            'sourceAuthority' => $authority,
            'sourceLineage' => $lineage,
            'partitionRef' => $partitionRef,
            'sourceSequence' => $cursorSequence,
            'installationRef' => BridgeHost::INSTALLATION,
            'environment' => 'test',
            'sellerRef' => BridgeHost::SELLER,
            'operationRef' => $operationRef,
            'operationKind' => 'charge',
            'businessIntentRef' => 'intent-100',
            'operationRevision' => 2,
            'state' => 'succeeded',
            'currency' => 'RUB',
            'requested' => ['amount' => '100.00', 'currency' => 'RUB'],
            'authorizedTotal' => ['amount' => '60.00', 'currency' => 'RUB'],
            'capturedTotal' => ['amount' => '60.00', 'currency' => 'RUB'],
            'refundedTotal' => ['amount' => '0.00', 'currency' => 'RUB'],
            'providerAccountRef' => 'pa-ext-1',
            'evidenceRef' => 'ev-ct08-bootstrap',
            'observedAt' => '2026-11-22T10:05:00Z',
            'recordedAt' => '2026-11-22T10:05:01Z',
        ];
        $envelopeArr['contentDigest'] = $codec->digest($envelopeArr, 'contentDigest');
        /** @var stdClass $envelopeObj */
        $envelopeObj = json_decode($codec->canonicalize($envelopeArr), false, 64, JSON_THROW_ON_ERROR);
        $materialDigest = $codec->digest([
            'schema' => 'billium.ledger.observation_material.v1',
            'installationRef' => BridgeHost::INSTALLATION,
            'sellerRef' => BridgeHost::SELLER,
            'environment' => 'test',
            'sourceAuthority' => $authority,
            'providerAccountRef' => 'pa-ext-1',
            'operationRef' => $operationRef,
            'operationKind' => 'charge',
            'originalPaymentRef' => null,
            'businessIntentRef' => 'intent-100',
            'operationRevision' => 2,
            'state' => 'succeeded',
            'currency' => 'RUB',
            'requested' => 10000,
            'authorized' => 6000,
            'captured' => 6000,
            'refunded' => 0,
            'voided' => 0,
            'discrepancy' => null,
        ]);

        $item = new BootstrapItem($operationRef, 2, $materialDigest, $envelopeObj);
        $namespace = [
            'channel' => 'payment.observation',
            'sourceAuthority' => $authority,
            'sourceLineage' => $lineage,
            'sellerRef' => BridgeHost::SELLER,
            'environment' => 'test',
            'partitionRef' => $partitionRef,
        ];
        $snapshot = new BootstrapPartition(
            'ct08-boot-1',
            'payment.observation',
            $authority,
            $lineage,
            BridgeHost::SELLER,
            'test',
            $partitionRef,
            $cursorSequence,
            BootstrapPartition::digestOf($cursorSequence, [$item], $namespace),
            'lease-ct08-1',
            1,
            [$item],
        );

        $contributions = $this->app->make(Extensions::class)->contributions(BootstrapItemHandler::class);
        $handlerClass = $contributions[0]->implementation ?? throw new LogicException('BootstrapItemHandler contribution missing');
        /** @var BootstrapItemHandler $handler */
        $handler = $this->app->make($handlerClass);
        $feed = $this->app->make(FeedPartitions::class);
        $runner = $this->app->make(OperationRunner::class);
        $finScope = new FinancialScope(BridgeHost::INSTALLATION, BridgeHost::SELLER, 'test', null, $authority);

        $runRoot = static function (Closure $fn) use ($runner, $finScope): mixed {
            $res = null;
            $runner->run(
                new OperationSpec('ledger.feed.bootstrap', BridgeHost::SELLER, 'test', FinancialMode::Active, ModuleOperationClass::UnknownResolution, ['platform', 'ledger'], financialScope: $finScope),
                static function (TransactionContext $ctx) use ($fn, &$res): PlatformReceipt {
                    $res = $fn($ctx);

                    return new PlatformReceipt('ledger.bootstrap', ['ok' => true]);
                },
            )->orThrow();

            return $res;
        };

        /** @var int $sessionId */
        $sessionId = $runRoot(static fn (TransactionContext $ctx): int => $feed->stageBootstrap($ctx, $snapshot));
        do {
            /** @var int $remaining */
            $remaining = $runRoot(static fn (TransactionContext $ctx): int => $feed->applyBootstrapItem($ctx, $sessionId, $handler));
        } while ($remaining > 0);
        /** @var int $newPartitionId */
        $newPartitionId = $runRoot(static fn (TransactionContext $ctx): int => $feed->adoptBootstrap($ctx, $sessionId));

        $recoveryState = BridgeHost::database($this->app)->transaction(function () use ($authority, $partitionRef): array {
            BridgeHost::database($this->app)->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');

            return $this->app->make(FeedRecoveryQueries::class)->state('server_runtime', 'payment.observation', $authority, $partitionRef);
        });

        return [
            'adopted' => $newPartitionId > 0,
            'recoveryRequired' => $recoveryState['recoveryRequired'],
            'bootstrapStatus' => $recoveryState['bootstrapStatus'],
        ];
    }

    /** @return array<string, mixed> */
    private function reconcileRefunds(): array
    {
        $exit = $this->app->make(ConsoleKernel::class)->call('billium:ledger:reconcile-refunds', ['--after' => '0']);
        $discrepancies = BridgeHost::database($this->app, true)->table('billium_ledger_discrepancy')->count();

        return ['exitCode' => $exit, 'discrepancies' => $discrepancies];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function exportAccountingFact(array $args): array
    {
        $paymentRef = (string) ($args['paymentRef'] ?? 'ct14-pay-1');
        $amountMinor = (int) ($args['amountMinor'] ?? 1000);
        $mapper = $this->app->make(AccountingMapper::class);
        $money = new Money($amountMinor, Currency::of('RUB'));
        $record = new AccountingSourceRecord(
            schemaVersion: 'accounting.source.v1',
            eventType: 'payment.captured.v1',
            installationRef: BridgeHost::INSTALLATION,
            sellerRef: BridgeHost::SELLER,
            environment: 'test',
            sourceLineage: '1',
            aggregateRef: $paymentRef,
            aggregateRevision: 1,
            occurredAt: new DateTimeImmutable('2026-11-22T10:00:00Z'),
            payload: [
                'status' => 'succeeded',
                'providerAccountRef' => 'account-a',
                'providerEffectRef' => 'effect:'.$paymentRef,
                'operationRef' => $paymentRef,
                'actualMoney' => $money->toWire(),
            ],
            deliveryAttemptRef: 'producer-export-1',
            snapshotDigest: 'sha256:'.hash('sha256', 'ct14-snapshot:'.$paymentRef),
        );
        $mapped = $mapper->map($record);
        $fact = $mapped->fact ?? throw new LogicException('Expected mapped accounting fact');
        $envelope = $mapper->toFinBridge($fact);

        return [
            'status' => $mapped->status->value,
            'sourceFactRef' => $envelope->sourceFactRef,
            'envelope' => $envelope->toArray(),
        ];
    }

    /** @return array<string, mixed> */
    private function authorityRouterCutover(): array
    {
        $router = $this->app->make(AuthorityRouter::class);
        $scope = new RoutingScope(BridgeHost::INSTALLATION, 'test', BridgeHost::SELLER, 'checkout');
        $epoch1 = new ExecutionRoute('external-executor-v1', 1);
        $epoch2 = new ExecutionRoute('remote-billium', 2);
        $epoch3 = new ExecutionRoute('external-executor-v2', 3);

        $oldCharge = new IntentBinding('checkout', 'ct20-old-intent', 'ct20-old-charge', $epoch1, 'ext-acc-1', 'ext-rev-1', '00000000-0000-4100-8800-000000002001', 'sha256:'.str_repeat('1', 64));
        $oldUnknown = new IntentBinding('checkout', 'ct20-unknown-intent', 'ct20-old-unknown', $epoch1, 'ext-acc-1', 'ext-rev-1', '00000000-0000-4100-8800-000000002002', 'sha256:'.str_repeat('2', 64));
        $router->initialize($scope, $epoch1, [$oldCharge, $oldUnknown]);

        $prematureEpoch2Refused = false;
        try {
            $router->reserve($scope, new IntentBinding('checkout', 'ct20-new-intent', 'ct20-new-op', $epoch2, 'account-a', 'merchant-a', '00000000-0000-4100-8800-000000002003', 'sha256:'.str_repeat('3', 64)));
        } catch (ProblemException) {
            $prematureEpoch2Refused = true;
        }

        $router->advance($scope, $epoch1, $epoch2);

        $staleEpoch1NewRefused = false;
        try {
            $router->reserve($scope, new IntentBinding('checkout', 'ct20-stale-intent', 'ct20-stale-op', $epoch1, 'ext-acc-1', 'ext-rev-1', '00000000-0000-4100-8800-000000002004', 'sha256:'.str_repeat('4', 64)));
        } catch (ProblemException) {
            $staleEpoch1NewRefused = true;
        }

        $unknownReKeyRefused = false;
        try {
            $router->reserve($scope, new IntentBinding('checkout', 'ct20-unknown-intent', 'ct20-old-unknown-rekey', $epoch2, 'account-a', 'merchant-a', '00000000-0000-4100-8800-000000002005', 'sha256:'.str_repeat('5', 64)));
        } catch (ProblemException) {
            $unknownReKeyRefused = true;
        }

        $newCharge = new IntentBinding('checkout', 'ct20-new-intent', 'ct20-new-op', $epoch2, 'account-a', 'merchant-a', '00000000-0000-4100-8800-000000002003', 'sha256:'.str_repeat('3', 64));
        $reservedNew = $router->reserve($scope, $newCharge);

        $wrongOwnerRefundRefused = false;
        try {
            $router->reserve($scope, new IntentBinding('checkout', 'ct20-refund-old-intent', 'ct20-refund-old', $epoch2, 'account-a', 'merchant-a', '00000000-0000-4100-8800-000000002006', 'sha256:'.str_repeat('6', 64), 'ct20-old-charge'));
        } catch (ProblemException) {
            $wrongOwnerRefundRefused = true;
        }

        $oldOwnerRefund = $router->reserve($scope, new IntentBinding('checkout', 'ct20-refund-old-intent', 'ct20-refund-old', $epoch1, 'ext-acc-1', 'ext-rev-1', '00000000-0000-4100-8800-000000002006', 'sha256:'.str_repeat('6', 64), 'ct20-old-charge'));

        // Advance to epoch 3 (remote -> external-v2) and verify refund of epoch-2 remote charge stays on remote-billium.
        $router->advance($scope, $epoch2, $epoch3);
        $remoteOwnerRefund = $router->reserve($scope, new IntentBinding('checkout', 'ct20-refund-new-intent', 'ct20-refund-new', $epoch2, 'account-a', 'merchant-a', '00000000-0000-4100-8800-000000002007', 'sha256:'.str_repeat('7', 64), 'ct20-new-op'));

        return [
            'prematureEpoch2Refused' => $prematureEpoch2Refused,
            'staleEpoch1NewRefused' => $staleEpoch1NewRefused,
            'unknownReKeyRefused' => $unknownReKeyRefused,
            'oldUnknownOwner' => $router->lookup($scope, 'ct20-old-unknown')?->route->executorRef,
            'newChargeOwner' => $reservedNew->route->executorRef,
            'wrongOwnerRefundRefused' => $wrongOwnerRefundRefused,
            'oldChargeRefundOwner' => $oldOwnerRefund->route->executorRef,
            'remoteChargeRefundOwnerAfterRollbackRoute' => $remoteOwnerRefund->route->executorRef,
        ];
    }
}
