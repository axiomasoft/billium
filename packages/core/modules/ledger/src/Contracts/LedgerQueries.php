<?php

declare(strict_types=1);

namespace Billium\Ledger\Contracts;

use Billium\Contracts\Data\Money;
use Billium\Ledger\Data\Allocations\AllocationReceipt;
use DateTimeImmutable;
use Packman\Attributes\Api;

/** Authenticated, side-effect-free recovery reads (REQ-CON-14, INV-14, D35, D60). All amounts are MoneyWire. */
#[Api(since: '1.0')]
interface LedgerQueries
{
    /** @return array<string, mixed> */
    public function paymentStatus(string $paymentRef, ?string $sourceAuthority = null): array;

    /** @return array<string, mixed> */
    public function obligation(string $obligationRef): array;

    /** @return array<string, mixed> */
    public function invoice(string $invoiceRef): array;

    /** @return array<string, mixed> Original immutable receipts, including a known operation with no allocation yet. */
    public function allocationByOperation(string $operationRef, ?string $sourceAuthority = null): array;

    /** @return array<string, mixed> Original immutable business receipt body (ACC-RST-05). */
    public function receipt(string $receiptRef): array;

    /** Original scoped row, with its kind and body. A miss carries no proof of absence. */
    public function allocationReceipt(string $receiptRef): ?AllocationReceipt;

    /** @return array<string, mixed> Advisory D35 capacity; null amount asks about refunding all remaining normal capacity. */
    public function refundAuthorization(string $paymentRef, ?Money $amount = null): array;

    /** @return array<string, mixed> D60 eligibility candidates, at most 1000 per page; never collection authorization. */
    public function dueObligations(string $billingAccountRef, DateTimeImmutable $asOf, int $limit = 100, ?string $cursor = null): array;

    /** @return array<string, mixed> Validated billium.query.v1 dispatch. readRender belongs to the documents module. */
    public function dispatch(string $json): array;
}
