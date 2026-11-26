<?php

declare(strict_types=1);

namespace Billium\Platform\Contracts\Idempotency;

use Billium\Contracts\Data\Envelopes\FindReceipt;
use Billium\Contracts\Data\Envelopes\ReceiptResult;
use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Platform\Data\Idempotency\StoredCommandReceipt;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** Authenticated, exact-scope reads; an unqualified historical receipt supplies no scope proof. */
#[Spi(kind: SpiKind::Call, since: '1.0')]
interface IdempotencyReceiptQueries
{
    public function byCommandKey(FinancialScope $scope, string $schema, string $qualifiedKey): ?StoredCommandReceipt;

    public function byReceiptRef(FinancialScope $scope, string $class, string $receiptRef): ?StoredCommandReceipt;

    public function byScopedReceiptRef(FinancialScope $scope, string $receiptRef): ?StoredCommandReceipt;

    /** Replay a wire command only when its original input digest matches. */
    public function replayCommand(FinancialScope $scope, string $schema, string $key, string $inputDigest): ?StoredOutcome;

    /** Look up a receipt by its opaque reference within an authenticated selection. */
    public function find(FinancialScope $scope, FindReceipt $query, ?ReceiptAbsenceAuthority $authority = null): ReceiptResult;
}
