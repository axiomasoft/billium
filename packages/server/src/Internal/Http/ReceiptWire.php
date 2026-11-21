<?php

declare(strict_types=1);

namespace Billium\Server\Internal\Http;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\AllocationReceiptResult;
use Billium\Ledger\Data\Allocations\AllocationReceipt;
use Billium\Server\Data\ExecutionContext;
use Packman\Attributes\Internal;

#[Internal]
final readonly class ReceiptWire
{
    public function __construct(private Codec $codec) {}

    /** @return array<string, mixed> */
    public function allocation(ExecutionContext $context, AllocationReceipt $receipt): array
    {
        $body = [
            'schema' => AllocationReceiptResult::SCHEMA,
            'installationRef' => $context->installationRef,
            'environment' => $context->environment,
            'sellerRef' => $context->sellerRef,
            'receiptRef' => $receipt->receiptRef,
            'kind' => $receipt->kind,
            'replayed' => $receipt->replayed,
            'body' => (object) $receipt->body,
        ];

        return [...$body, 'digest' => $this->codec->digest($body)];
    }
}
