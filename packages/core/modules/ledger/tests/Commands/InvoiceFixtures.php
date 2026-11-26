<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Commands;

use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Ledger\Data\Invoices\CreateInvoice;
use Billium\Ledger\Data\Invoices\InvoiceLineInput;
use Billium\Ledger\Data\Invoices\IssueInvoice;
use Billium\Ledger\Data\Invoices\UpdateInvoiceMetadata;
use PDO;
use PDOException;

/** Draft/issue helpers over the public Ledger root and small raw-SQL probes (D50: committed data, owner fixtures). */
trait InvoiceFixtures
{
    /** @param array<string, int> $lines obligationRef => tax minor */
    protected function draft(array $lines = ['obligation-a' => 0], string $ref = 'invoice-a', ?string $key = null): StoredOutcome
    {
        $inputs = [];
        foreach ($lines as $obligation => $tax) {
            $inputs[] = new InvoiceLineInput($obligation, $tax, ['name' => $obligation]);
        }

        return $this->ledger()->createInvoice(new CreateInvoice($key ?? 'create-'.$ref, $this->account(), $ref, 'customer-a', $inputs, ['buyer' => 'Buyer']));
    }

    protected function issue(string $ref = 'invoice-a', ?string $key = null): StoredOutcome
    {
        return $this->ledger()->issueInvoice(new IssueInvoice($key ?? 'issue-'.$ref, $this->account(), $ref));
    }

    /** @param array<string, mixed> $metadata */
    protected function updateMetadata(string $updateRef, array $metadata, string $invoice = 'invoice-a'): StoredOutcome
    {
        return $this->ledger()->updateInvoiceMetadata(new UpdateInvoiceMetadata('meta-'.$updateRef, $this->account(), $updateRef, $invoice, $metadata));
    }

    /** @return array<string, mixed> */
    protected function invoiceRow(string $ref = 'invoice-a'): array
    {
        $rows = $this->table('SELECT * FROM billium_invoice WHERE invoice_ref=?', [$ref]);

        return $rows[0] ?? self::fail('no invoice '.$ref);
    }

    protected function invoiceStatus(string $ref = 'invoice-a'): string
    {
        return self::text($this->invoiceRow($ref)['status']);
    }

    /** @return array{0: ?string, 1: string} SQLSTATE and message of a refused statement, [null, ''] when it ran */
    protected static function refusal(PDO $pdo, string $sql): array
    {
        try {
            $pdo->exec($sql);

            return [null, ''];
        } catch (PDOException $exception) {
            return [is_string($exception->errorInfo[0] ?? null) ? $exception->errorInfo[0] : (string) $exception->getCode(), $exception->getMessage()];
        }
    }
}
