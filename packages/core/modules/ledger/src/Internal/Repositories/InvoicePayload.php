<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Repositories;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Ledger\Data\CreateInvoice;
use Billium\Ledger\Data\DiscardInvoice;
use Billium\Ledger\Data\IssueInvoice;
use Billium\Ledger\Data\MarkUncollectible;
use Billium\Ledger\Data\UpdateInvoiceMetadata;
use Billium\Ledger\Data\VoidInvoice;

/** Semantic payload, digest and shape validation of the invoice commands; the command key is not part of the identity. */
final class InvoicePayload
{
    public const int MAX_LINES = 500;

    /** @return array<string, mixed> */
    public static function values(CreateInvoice|UpdateInvoiceMetadata|IssueInvoice|DiscardInvoice|VoidInvoice|MarkUncollectible $command, ?string $legalDocumentRef = null): array
    {
        $values = get_object_vars($command);
        unset($values['commandKey']);
        $values['scope'] = get_object_vars($command->scope);
        if ($command instanceof CreateInvoice) {
            $values['lines'] = array_map(get_object_vars(...), $command->lines);
        }
        if ($command instanceof IssueInvoice) {
            $values['legalDocumentRef'] = $legalDocumentRef;
        }
        $payload = [];
        foreach ($values as $key => $value) {
            $payload[(string) $key] = $value;
        }

        return $payload;
    }

    public static function digest(CreateInvoice|UpdateInvoiceMetadata|IssueInvoice|DiscardInvoice|VoidInvoice|MarkUncollectible $command, ?string $legalDocumentRef = null): string
    {
        return 'sha256:'.hash('sha256', (new Codec)->canonicalize(self::values($command, $legalDocumentRef)));
    }

    public static function validate(CreateInvoice|UpdateInvoiceMetadata|IssueInvoice|DiscardInvoice|VoidInvoice|MarkUncollectible $command, ?string $legalDocumentRef = null): void
    {
        foreach (get_object_vars($command) as $key => $value) {
            if (is_string($value) && ($value === '' || strlen($value) > 200)) {
                throw new ProblemException(ProblemCode::ValidationInvalid, $key.' is a nonempty bounded string');
            }
        }
        foreach ([$command->scope->sellerRef, $command->scope->environment, $command->scope->billingAccountRef] as $value) {
            if (strlen($value) > 200) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'scope references are bounded strings');
            }
        }
        if (! in_array($command->scope->environment, ['test', 'live'], true)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'unknown environment');
        }
        if ($legalDocumentRef !== null && ($legalDocumentRef === '' || strlen($legalDocumentRef) > 200)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'legalDocumentRef is a nonempty bounded string');
        }
        if ($command instanceof CreateInvoice) {
            $refs = array_map(static fn ($line): string => $line->obligationRef, $command->lines);
            if ($refs === [] || count($refs) > self::MAX_LINES || count($refs) !== count(array_unique($refs))) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'an invoice has 1..'.self::MAX_LINES.' distinct obligation lines');
            }
            foreach ($command->lines as $line) {
                if ($line->obligationRef === '' || strlen($line->obligationRef) > 200 || $line->taxMinor < 0) {
                    throw new ProblemException(ProblemCode::ValidationInvalid, 'invalid invoice line');
                }
            }
        }
        if ($command instanceof UpdateInvoiceMetadata) {
            // Unknown keys are refused by the guarded routine by state (frozen after issue); a non-object is refused there too.
            if ($command->metadata === []) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'metadata is a nonempty object');
            }
        }
    }
}
