<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Commands;

use Packman\Attributes\Internal;

/** The only place that knows how a multi-invoice credit names its parts and how the group reference lists them. */
#[Internal]
final class CreditNoteGroupRef
{
    private const string GROUP = ':group:';

    private const string RECEIPT = 'credit-note:';

    public static function part(string $creditNoteRef, string $invoiceRef): string
    {
        return $creditNoteRef.':'.self::suffix($invoiceRef);
    }

    /** @param list<string> $invoiceRefs */
    public static function group(string $creditNoteRef, array $invoiceRefs): string
    {
        return $creditNoteRef.self::GROUP.implode('.', array_map(self::suffix(...), $invoiceRefs));
    }

    /**
     * Receipt refs of every part named by a group receipt ref, or null when the ref is not a well-formed group.
     *
     * @return list<string>|null
     */
    public static function partReceipts(string $receiptRef): ?array
    {
        $marker = strrpos($receiptRef, self::GROUP);
        if (! str_starts_with($receiptRef, self::RECEIPT) || $marker === false || $marker === strlen(self::RECEIPT)) {
            return null;
        }
        $prefix = substr($receiptRef, 0, $marker).':';
        $suffixes = explode('.', substr($receiptRef, $marker + strlen(self::GROUP)));
        if (count($suffixes) < 2 || count($suffixes) !== count(array_unique($suffixes))) {
            return null;
        }
        foreach ($suffixes as $suffix) {
            if (preg_match('/^[a-f0-9]{16}$/D', $suffix) !== 1) {
                return null;
            }
        }

        return array_map(static fn (string $suffix): string => $prefix.$suffix, $suffixes);
    }

    private static function suffix(string $invoiceRef): string
    {
        return substr(hash('sha256', $invoiceRef), 0, 16);
    }
}
