<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Repositories;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runner\LockRequest;
use Billium\Core\Enums\Runner\LockRank;
use Billium\Core\Exceptions\Runner\LockPlanChanged;
use Billium\Ledger\Data\Invoices\CreateInvoice;
use Billium\Ledger\Data\Invoices\DiscardInvoice;
use Billium\Ledger\Data\Invoices\InvoiceLineInput;
use Billium\Ledger\Data\Invoices\IssueInvoice;
use Billium\Ledger\Data\Invoices\MarkUncollectible;
use Billium\Ledger\Data\Invoices\UpdateInvoiceMetadata;
use Billium\Ledger\Data\Invoices\VoidInvoice;
use Billium\Ledger\Data\Runtime\LedgerReceipt;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use LogicException;
use Packman\Attributes\Internal;
use stdClass;

/**
 * Plans the D27 locks of the invoice commands (obligations of the lines, ids ascending, then the invoice) and calls the
 * guarded routine that alone mutates invoices, lines, obligation attachment and receipts (INV-13, D60). No PHP counter
 * or status write.
 */
#[Internal]
final readonly class InvoiceRepository
{
    public function __construct(private DatabaseManager $databases, private OperationRunner $runner, private LedgerRepository $routines) {}

    public function execute(TransactionContext $context, CreateInvoice|UpdateInvoiceMetadata|IssueInvoice|DiscardInvoice|VoidInvoice|MarkUncollectible $command, ?string $legalDocumentRef = null): LedgerReceipt
    {
        $this->runner->assertCurrent($context, 'ledger');
        InvoicePayload::validate($command, $legalDocumentRef);
        $caller = $context->caller();
        if ($caller->sellerRef !== $command->scope->sellerRef || $caller->environment !== $command->scope->environment) {
            throw new ProblemException(ProblemCode::AuthDenied, 'participant scope differs from caller');
        }
        $connection = $this->databases->connection($context->connectionName());
        $digest = InvoicePayload::digest($command, $legalDocumentRef);
        $prefix = [$caller->installationRef, $caller->sellerRef, $caller->environment, $command->scope->billingAccountRef];
        if ($command instanceof CreateInvoice) {
            return $this->create($context, $connection, $command, $prefix, $digest);
        }
        $invoice = $this->invoice($connection, $caller->sellerRef, $caller->environment, $command->invoiceRef);
        $lineObligations = $command instanceof UpdateInvoiceMetadata || $command instanceof MarkUncollectible ? [] : $this->lineObligations($connection, $invoice['id']);
        $requests = [];
        if ($lineObligations !== []) {
            $requests[] = new LockRequest(LockRank::Obligation, 'billium_obligation', $lineObligations, guardedRoutine: 'billium_lock_obligation');
        }
        $requests[] = new LockRequest(LockRank::Invoice, 'billium_invoice', [$invoice['id']], guardedRoutine: 'billium_lock_invoice');
        $context->lock(...$requests);
        if ($this->invoice($connection, $caller->sellerRef, $caller->environment, $command->invoiceRef) !== $invoice
            || (! $command instanceof UpdateInvoiceMetadata && ! $command instanceof MarkUncollectible && $this->lineObligations($connection, $invoice['id']) !== $lineObligations)) {
            throw new LockPlanChanged('invoice or its lines changed');
        }
        $bindings = [...$prefix, $invoice['currency'], $invoice['id']];

        return match (true) {
            $command instanceof UpdateInvoiceMetadata => $this->routines->call($connection, 'update_invoice_metadata',
                [...$bindings, $command->updateRef, self::json($command->metadata), $digest], 'ledger.invoice_metadata'),
            $command instanceof IssueInvoice => $this->routines->call($connection, 'issue_invoice', [...$bindings, $legalDocumentRef, $digest], 'ledger.invoice_issue'),
            $command instanceof DiscardInvoice => $this->routines->call($connection, 'close_invoice', [...$bindings, 'discarded', $command->causeRef, $digest], 'ledger.invoice_discard'),
            $command instanceof VoidInvoice => $this->routines->call($connection, 'close_invoice', [...$bindings, 'void', $command->causeRef, $digest], 'ledger.invoice_void'),
            default => $this->routines->call($connection, 'mark_invoice_uncollectible', [...$bindings, $command->reasonRef, $command->authorizationRef, $digest], 'ledger.invoice_uncollectible'),
        };
    }

    /** @param list<string> $prefix */
    private function create(TransactionContext $context, Connection $connection, CreateInvoice $command, array $prefix, string $digest): LedgerReceipt
    {
        $caller = $context->caller();
        $read = fn (): array => $this->obligations($connection, $caller->sellerRef, $caller->environment, array_map(static fn (InvoiceLineInput $line): string => $line->obligationRef, $command->lines));
        $before = $read();
        $currencies = array_unique(array_column($before, 'currency'));
        if (count($currencies) !== 1) {
            throw new ProblemException(ProblemCode::MoneyCurrencyMismatch, 'invoice lines share one currency');
        }
        $ids = array_column($before, 'id');
        $context->lock(new LockRequest(LockRank::Obligation, 'billium_obligation', $ids, guardedRoutine: 'billium_lock_obligation'));
        if ($read() !== $before) {
            throw new LockPlanChanged('invoice obligations changed');
        }
        $lines = [];
        foreach ($command->lines as $line) {
            $lines[] = ['obligation_id' => $before[$line->obligationRef]['id'], 'tax_minor' => $line->taxMinor, 'snapshot' => self::object($line->snapshot)];
        }

        return $this->routines->call($connection, 'create_invoice', [...$prefix, reset($currencies), $command->invoiceRef, $command->customerRef,
            self::json($command->snapshot), json_encode($lines, JSON_THROW_ON_ERROR), $digest], 'ledger.invoice_create');
    }

    /**
     * @param  list<string>  $refs
     * @return array<string, array{id: int, currency: string}>
     */
    private function obligations(Connection $connection, string $seller, string $environment, array $refs): array
    {
        $rows = $connection->table('billium_obligation')->where('seller_ref', $seller)->where('environment', $environment)->whereIn('obligation_ref', $refs)->get(['id', 'obligation_ref', 'currency']);
        $found = [];
        foreach ($rows as $row) {
            $found[self::string($row->obligation_ref)] = ['id' => self::id($row->id), 'currency' => self::string($row->currency)];
        }
        foreach ($refs as $ref) {
            if (! isset($found[$ref])) {
                throw new ProblemException(ProblemCode::ReferenceUnknown, 'unknown invoice obligation');
            }
        }
        ksort($found);

        return $found;
    }

    /** @return array{id: int, currency: string} */
    private function invoice(Connection $connection, string $seller, string $environment, string $ref): array
    {
        $row = $connection->table('billium_invoice')->where('seller_ref', $seller)->where('environment', $environment)->where('invoice_ref', $ref)->first(['id', 'currency']);
        if ($row === null) {
            throw new ProblemException(ProblemCode::ReferenceUnknown, 'unknown invoice');
        }

        return ['id' => self::id($row->id), 'currency' => self::string($row->currency)];
    }

    /** @return list<int> */
    private function lineObligations(Connection $connection, int $invoice): array
    {
        $ids = [];
        foreach ($connection->table('billium_invoice_line')->where('invoice_id', $invoice)->orderBy('obligation_id')->pluck('obligation_id') as $id) {
            $ids[] = self::id($id);
        }

        return $ids;
    }

    /** @param array<string, mixed> $value */
    private static function json(array $value): string
    {
        return json_encode(self::object($value), JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $value
     * @return stdClass|array<string, mixed>
     */
    private static function object(array $value): stdClass|array
    {
        return $value === [] ? new stdClass : $value;
    }

    private static function id(mixed $value): int
    {
        return is_int($value) ? $value : throw new LogicException('Expected a PostgreSQL bigint id');
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : throw new LogicException('Expected a PostgreSQL text value');
    }
}
