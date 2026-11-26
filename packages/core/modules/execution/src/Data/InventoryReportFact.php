<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use DateTimeImmutable;
use Packman\Attributes\Api;

/** A qualified statement line. balance_comparison is the provider's gross cumulative refunds for the referenced root. */
#[Api(since: '1.0')]
final readonly class InventoryReportFact
{
    public function __construct(
        public string $kind,
        public string $factRef,
        public string $revision,
        public ?string $rootOperationRef,
        public Money $amount,
        public DateTimeImmutable $observedAt,
        public string $sourceDigest,
        public ?DateTimeImmutable $finalAfter = null,
    ) {
        if ($amount->currency->scale !== Currency::of($amount->currency->code)->scale) {
            throw new ProblemException(ProblemCode::MoneyScaleInvalid, 'Statement amount must use the canonical currency scale');
        }
        foreach ([$factRef, $revision, $rootOperationRef] as $ref) {
            if ($ref !== null && (trim($ref) === '' || strlen($ref) > 200)) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'Invalid statement reference');
            }
        }
        if (! in_array($kind, ['dispute', 'chargeback', 'fee', 'settlement', 'external_manual', 'balance_comparison'], true)
            || ($kind === 'balance_comparison' && ($rootOperationRef === null || $amount->minor < 0))
            || ($finalAfter !== null && ($kind !== 'balance_comparison' || $finalAfter < $observedAt))
            || preg_match('/^sha256:[0-9a-f]{64}$/D', $sourceDigest) !== 1) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Invalid qualified statement line');
        }
    }
}
