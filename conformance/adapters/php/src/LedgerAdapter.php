<?php

declare(strict_types=1);

namespace BilliumConformance;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\PaymentObservation;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\BilliumCoreServiceProvider;
use Billium\Core\Contracts\Modules\ModuleRegistry;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Contracts\Runtime\FinancialSessionAdmission;
use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Tests\Support\FixedCallerContext;
use Billium\Core\Tests\Support\FixedSessionAdmission;
use Billium\Core\Tests\Support\SimulatedFinancialAuthorityEvidence;
use Billium\Core\Tests\TestEnvironment;
use Billium\Ledger\Contracts\Ledger;
use Billium\Ledger\Contracts\LedgerQueries;
use Billium\Ledger\Contracts\ObservationApplier;
use Billium\Ledger\Data\Allocations\Allocate;
use Billium\Ledger\Data\Allocations\AllocationReceipt;
use Billium\Ledger\Data\Allocations\ReallocatePayment;
use Billium\Ledger\Data\Allocations\ReverseAllocation;
use Billium\Ledger\Data\Credits\RecordCustomerCredit;
use Billium\Ledger\Data\Invoices\CreateInvoice;
use Billium\Ledger\Data\Invoices\CreditNoteLine;
use Billium\Ledger\Data\Invoices\CreditNoteMode;
use Billium\Ledger\Data\Invoices\InvoiceLineInput;
use Billium\Ledger\Data\Invoices\IssueCreditNote;
use Billium\Ledger\Data\Invoices\IssueInvoice;
use Billium\Ledger\Data\Invoices\UpdateInvoiceMetadata;
use Billium\Ledger\Data\Invoices\VoidInvoice;
use Billium\Ledger\Data\Obligations\ObligationDuePolicy;
use Billium\Ledger\Data\Obligations\RecordObligation;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Billium\Ledger\Data\Settlements\DeclareSettlementIntent;
use Billium\Ledger\Internal\Commands\BootstrapPartitionCommand;
use Billium\Ledger\Internal\Observation\EconomicDigest;
use Billium\Ledger\Internal\Observation\ObservationHandler;
use Billium\Ledger\Internal\Observation\ParkedDrainer;
use Billium\Ledger\Internal\Observation\RefundReconciler;
use Billium\Platform\Contracts\Feed\FeedPartitions;
use Billium\Platform\Contracts\Installation\Installation;
use Billium\Platform\Data\Inbox\BootstrapItem;
use Billium\Platform\Data\Inbox\BootstrapPartition;
use Billium\Platform\Internal\EventContracts\DatabaseEventContractRegistrar;
use Billium\Rating\Laravel\RatingServiceProvider;
use Billium\Schedule\Laravel\ScheduleServiceProvider;
use Billium\Usage\Laravel\UsageServiceProvider;
use DateTimeImmutable;
use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use LogicException;
use Orchestra\Testbench\Foundation\Application as Testbench;
use stdClass;
use Symfony\Component\Console\Output\BufferedOutput;

/** BC-CNF-10: a test host of the real ledger; no alternative monetary reducer or fixture DML. */
final class LedgerAdapter
{
    private ?Application $app = null;

    private stdClass $callerInput;

    private FixedCallerContext $callers;

    /** @var list<string> */
    private array $runtimeModules = [];

    /** @var list<string> */
    private array $bootModules = [];

    /** @var array<string, class-string> */
    public const array RUNTIME_MODULE_PROVIDERS = [
        'usage' => UsageServiceProvider::class,
        'rating' => RatingServiceProvider::class,
        'schedule' => ScheduleServiceProvider::class,
    ];

    public function p4(): P4RuntimeAdapter
    {
        $available = array_filter($this->runtimeModules, static fn (string $module): bool => isset(self::RUNTIME_MODULE_PROVIDERS[$module]));
        if ($available !== [] && ($this->app === null || $this->bootModules !== $this->runtimeModules)) {
            $this->app = null;
            $this->boot();
        }

        // Unavailable implementations refuse without opening a DB or installing future SQL.
        return new P4RuntimeAdapter($this->app ?? new Container);
    }

    public function given(stdClass $given): void
    {
        $this->callerInput = $given->caller ?? new stdClass;
        $modules = $given->modules ?? [];
        if (! is_array($modules) || ! array_is_list($modules)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'given.modules must be a module list');
        }
        $this->runtimeModules = [];
        foreach ($modules as $module) {
            if (! is_string($module) || ! in_array($module, ['usage', 'rating', 'schedule'], true)) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'unknown P4 runtime module');
            }
            $this->runtimeModules[] = $module;
        }
    }

    /** @return array<string, mixed> */
    public function run(string $op, stdClass $args): array
    {
        $this->boot();
        if ($op === 'ledger.reset') {
            $this->reset();

            return [];
        }
        if ($op === 'ledger.caller') {
            $this->setCaller($args);

            return [];
        }
        $app = $this->app ?? throw new LogicException('Ledger host is not booted');
        $ledger = $app->make(Ledger::class);
        $scope = fn (): BillingAccountScope => new BillingAccountScope(
            $args->sellerRef ?? $this->callers->current()->sellerRef,
            $args->environment ?? $this->callers->current()->environment,
            self::text($args, 'billingAccountRef'),
        );
        $key = fn (): string => self::text($args, 'commandKey');
        $outcome = match ($op) {
            'ledger.obligation.record' => $ledger->recordObligation(new RecordObligation(
                $key(), $scope(), self::text($args, 'obligationRef'), self::text($args, 'basisRef'), self::text($args, 'customerRef'),
                self::text($args, 'componentRef'), Money::fromWire($args->amount ?? null), self::text($args, 'termsDigest'),
                $args->priceVersionRef ?? null, $args->taxSnapshotRef ?? null,
                new ObligationDuePolicy(isset($args->dueAt) ? new DateTimeImmutable($args->dueAt) : null,
                    $args->duePolicyVersion ?? null, $args->sourcePolicyRef ?? null, $args->sourcePolicySnapshotDigest ?? null,
                    isset($args->collectionEligibleAt) ? new DateTimeImmutable($args->collectionEligibleAt) : null),
            )),
            'ledger.intent.declare' => $ledger->declareSettlementIntent(new DeclareSettlementIntent(
                $key(), $scope(), self::text($args, 'businessIntentRef'), self::text($args, 'currency'),
                $args->obligationRefs, $args->policyVersion ?? 'pro_rata_open/1',
            )),
            'ledger.credit.record' => $ledger->recordCustomerCredit(new RecordCustomerCredit(
                $key(), $scope(), self::text($args, 'creditRef'), self::text($args, 'sourceRef'), Money::fromWire($args->amount ?? null),
            )),
            'ledger.invoice.create' => $ledger->createInvoice(new CreateInvoice(
                $key(), $scope(), self::text($args, 'invoiceRef'), self::text($args, 'customerRef'),
                array_map(static fn (stdClass $line): InvoiceLineInput => new InvoiceLineInput(
                    self::text($line, 'obligationRef'), $line->taxMinor ?? 0, get_object_vars($line->snapshot ?? new stdClass),
                ), $args->lines), get_object_vars($args->snapshot ?? new stdClass),
            )),
            'ledger.invoice.issue' => $ledger->issueInvoice(new IssueInvoice($key(), $scope(), self::text($args, 'invoiceRef'))),
            'ledger.invoice.void' => $ledger->voidInvoice(new VoidInvoice($key(), $scope(), self::text($args, 'invoiceRef'), self::text($args, 'causeRef'))),
            'ledger.invoice.metadata' => $ledger->updateInvoiceMetadata(new UpdateInvoiceMetadata(
                $key(), $scope(), self::text($args, 'updateRef'), self::text($args, 'invoiceRef'), get_object_vars($args->metadata),
            )),
            'ledger.allocate' => $ledger->allocate(new Allocate(
                $key(), $scope(), self::text($args, 'allocationRef'), self::text($args, 'obligationRef'), Money::fromWire($args->amount ?? null),
                $args->paymentRef ?? null, $args->customerCreditRef ?? null, $args->cause ?? 'manual',
            )),
            'ledger.reverse' => $ledger->reverseAllocation(new ReverseAllocation(
                $key(), $scope(), self::text($args, 'reversalRef'),
                is_int($args->allocationRef ?? null) ? $args->allocationRef : self::text($args, 'allocationRef'), self::text($args, 'causeRef'),
                isset($args->remainder) ? Money::fromWire($args->remainder) : null,
            )),
            'ledger.credit_note' => $ledger->issueCreditNote(new IssueCreditNote(
                $key(), $scope(), self::text($args, 'creditNoteRef'), self::text($args, 'invoiceRef'), CreditNoteMode::from(self::text($args, 'mode')),
                array_map(static fn (stdClass $line): CreditNoteLine => new CreditNoteLine(
                    self::text($line, 'obligationRef'), Money::fromWire($line->amount ?? null),
                ), $args->lines), self::text($args, 'reason'), $args->legalDocumentRef ?? null,
            )),
            'ledger.reallocate' => $ledger->reallocatePayment(ReallocatePayment::fromWire(self::text($args, 'json_text'))),
            'ledger.observe' => $app->make(ObservationApplier::class)->apply(self::text($args, 'json_text')),
            default => null,
        };
        if ($outcome instanceof StoredOutcome) {
            $outcome->orThrow();

            return [...$outcome->body, 'receiptClass' => $outcome->receiptClass, 'receiptId' => $outcome->receiptId, 'replayed' => $outcome->replayed];
        }
        if ($outcome instanceof AllocationReceipt) {
            return [...$outcome->body, 'receiptRef' => $outcome->receiptRef, 'replayed' => $outcome->replayed];
        }

        return match ($op) {
            'ledger.drain' => ['drained' => count(array_filter($app->make(ParkedDrainer::class)->retryParked(), static fn (string $status): bool => $status === 'applied'))],
            'ledger.reconcile_refunds' => ['discrepancies' => $app->make(RefundReconciler::class)->reconcile($args->after_seconds ?? null)->recorded],
            'ledger.query' => $this->query($args),
            'ledger.bootstrap' => $this->bootstrap($args),
            default => throw new LogicException("Unknown ledger op {$op}"),
        };
    }

    private static function text(stdClass $args, string $name): string
    {
        $value = $args->{$name} ?? null;

        return is_string($value) ? $value : throw new ProblemException(ProblemCode::ValidationInvalid, "argument {$name} must be a string");
    }

    public function close(): void
    {
        if ($this->app === null) {
            return;
        }
        foreach (array_keys($this->app['db']->getConnections()) as $name) {
            $this->app['db']->purge($name);
        }
        $this->app->terminate();
        $this->app->flush();
        ConsoleApplication::forgetBootstrappers();
        Container::setInstance(null);
        $this->app = null;
    }

    private function boot(): void
    {
        if ($this->app !== null) {
            return;
        }
        TestEnvironment::preflight(2);
        TestEnvironment::acquireSuiteLease();
        // Configure before providers boot: LedgerPrivileges captures the runtime connection at boot.
        try {
            $host = new class extends Testbench
            {
                /** @var list<string> */
                public array $runtimeModules = [];

                /** @return list<class-string> */
                protected function getPackageProviders($app): array
                {
                    $providers = [BilliumCoreServiceProvider::class];
                    // Testbench configures the environment after provider registration.
                    // Enroll the selected binaries explicitly, before their boot/migration discovery.
                    foreach ($this->runtimeModules as $module) {
                        $provider = LedgerAdapter::RUNTIME_MODULE_PROVIDERS[$module] ?? null;
                        if ($provider !== null) {
                            $providers[] = $provider;
                        }
                    }

                    return $providers;
                }

                /** @param Application $app */
                protected function defineEnvironment($app): void
                {
                    TestEnvironment::configure($app);
                    $app['config']->set('billium.modules.enabled', ['platform', 'ledger', ...$this->runtimeModules]);
                    foreach ($this->runtimeModules as $module) {
                        $app['config']->set("billium.{$module}.enabled", true);
                    }
                }
            };
            $host->runtimeModules = $this->runtimeModules;
            $app = $host->configure(['extra' => ['providers' => [BilliumCoreServiceProvider::class]]])->createApplication();
        } finally {
            // The CLI protocol (and PHPUnit host) owns diagnostics, not Laravel's global handlers.
            restore_error_handler();
            restore_exception_handler();
        }
        $output = new BufferedOutput;
        TestEnvironment::noteMigrationRun();
        if ($app->make(Kernel::class)->call('packman:schema:migrate', ['--scope' => 'central', '--database' => 'billium_owner', '--modules' => true, '--force' => true], $output) !== 0) {
            throw new LogicException('Ledger migrations failed: '.$output->fetch());
        }
        TestEnvironment::assertRuntimeSafe($app['db']->connection('pgsql')->getPdo());
        $this->app = $app;
        $this->bootModules = $this->runtimeModules;
        $this->callers = FixedCallerContext::default();
        $app->instance(CallerContext::class, $this->callers);
    }

    private function setCaller(stdClass $input): void
    {
        $this->callers->set(new Caller(self::text($input, 'sellerRef'), self::text($input, 'environment'),
            self::text($input, 'installationRef'), 'conformance', $input->sourceAuthorities ?? []));
    }

    private function reset(): void
    {
        $app = $this->app ?? throw new LogicException('Ledger host is not booted');
        $this->setCaller($this->callerInput);
        $owner = $app['db']->connection('billium_owner');
        // D50: committed fixtures, no RefreshDatabase transaction. Never truncate host tables.
        TestEnvironment::truncateBilliumTables($owner->getPdo());
        $contractsIntact = $owner->scalar(<<<'SQL'
            SELECT (SELECT count(*) FROM public.billium_event_contract) = 7 AND NOT EXISTS (
                SELECT 1 FROM public.billium_event_contract
                WHERE contract_revision <> 'events-2026-10-v1' OR status <> 'registered'
            )
            SQL);
        if (! $contractsIntact) {
            $owner->unprepared('TRUNCATE public.billium_event_contract RESTART IDENTITY CASCADE');
            $modules = $app->make(ModuleRegistry::class);
            $descriptor = $modules->descriptor('ledger') ?? throw new LogicException('Ledger module is missing');
            $registrar = new DatabaseEventContractRegistrar($owner, $modules);
            $registrar->register($registrar->plan($descriptor, true));
        }
        $ref = $this->callers->current()->installationRef;
        // Each reset starts a separate fixture history, including the simulated host's signed journal.
        SimulatedFinancialAuthorityEvidence::reset();
        $app->forgetInstance(SimulatedFinancialAuthorityEvidence::class);
        $authority = SimulatedFinancialAuthorityEvidence::install($app);
        $installation = $app->make(Installation::class);
        $installation->init($ref);
        $authority->issueTransition('open', 'open-1', ['owner-1', 1, 1, 1, 'evidence:conformance-open']);
        $installation->openSession('open-1', 'owner-1', 1, 1, 1, 'evidence:conformance-open');
        $authority->issueTransition('activate', 'activate-1', [1, 'certificate:conformance', 'admission:conformance', 'evidence:conformance-activate']);
        $installation->activateSession('activate-1', 1, 'certificate:conformance', 'admission:conformance', 'evidence:conformance-activate');
        $token = FixedSessionAdmission::token($ref);
        // The ledger vectors use two independently admitted sellers; changing the caller never issues a grant.
        $authority->issueProcess($token, [
            new FinancialScope($ref, 'seller-a', 'test', null),
            new FinancialScope($ref, 'seller-b', 'test', null),
        ]);
        $app->instance(FinancialSessionAdmission::class, new FixedSessionAdmission($token));
    }

    /** @return array<string, mixed> */
    private function query(stdClass $args): array
    {
        $app = $this->app ?? throw new LogicException('Ledger host is not booted');
        $queries = $app->make(LedgerQueries::class);
        if ($args->query === 'receipt') {
            return $queries->receipt(self::text($args->selector, 'receiptRef'));
        }
        $caller = $this->callers->current();
        $wire = ['schema' => 'billium.query.v1', 'installationRef' => $caller->installationRef,
            'sellerRef' => $caller->sellerRef, 'environment' => $caller->environment, ...get_object_vars($args)];

        return $queries->dispatch(json_encode($wire, JSON_THROW_ON_ERROR))['result'];
    }

    /** @return array<string, mixed> */
    private function bootstrap(stdClass $args): array
    {
        $action = $args->action ?? 'resume';
        if (! in_array($action, ['stage', 'step', 'resume'], true)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Bootstrap action is stage, step or resume');
        }
        $app = $this->app ?? throw new LogicException('Ledger host is not booted');
        $roots = $app->make(ParkedDrainer::class);
        $feed = $app->make(FeedPartitions::class);
        $items = [];
        foreach ($args->items as $raw) {
            $decoded = (new Codec)->decode(json_encode($raw, JSON_THROW_ON_ERROR));
            if (! $decoded instanceof PaymentObservation) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'Bootstrap items are payment observations');
            }
            $items[] = new BootstrapItem($decoded->operationRef, $decoded->operationRevision, EconomicDigest::of($decoded), $decoded->raw);
        }
        $caller = $this->callers->current();
        $snapshot = new BootstrapPartition(self::text($args, 'bootstrapRef'), ParkedDrainer::CHANNEL,
            self::text($args, 'sourceAuthority'), self::text($args, 'sourceLineage'), $caller->sellerRef, $caller->environment,
            self::text($args, 'partitionRef'), $args->cursorSequence, $args->snapshotDigest ?? BootstrapPartition::digestOf($args->cursorSequence, $items, [
                'channel' => ParkedDrainer::CHANNEL, 'sourceAuthority' => self::text($args, 'sourceAuthority'),
                'sourceLineage' => self::text($args, 'sourceLineage'), 'sellerRef' => $caller->sellerRef,
                'environment' => $caller->environment, 'partitionRef' => self::text($args, 'partitionRef'),
            ]),
            self::text($args, 'sourceRetentionLeaseRef'), $args->expectedItemCount ?? count($items), $items);
        if ($action === 'resume') {
            $status = $app->make(BootstrapPartitionCommand::class)->execute($snapshot);
        } else {
            $session = $roots->root(fn (TransactionContext $context): int => $feed->stageBootstrap($context, $snapshot), BootstrapPartitionCommand::KIND);
            if ($action === 'step') {
                $handler = $app->make(ObservationHandler::class);
                $roots->root(fn (TransactionContext $context): int => $feed->applyBootstrapItem($context, $session, $handler), BootstrapPartitionCommand::KIND);
            }
            $status = $roots->root(fn (TransactionContext $context) => $feed->bootstrapStatus($context, $session), BootstrapPartitionCommand::KIND, commitKind: null);
        }

        return get_object_vars($status);
    }
}
