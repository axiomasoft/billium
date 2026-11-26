<?php

declare(strict_types=1);

namespace Billium\Execution\Contracts;

use Billium\Contracts\Data\Money;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Execution\Data\AbsenceCertificate;
use Billium\Execution\Data\CapacitySnapshot;
use Billium\Execution\Data\MaterialSnapshot;
use Billium\Execution\Data\Reservation;
use Billium\Execution\Enums\EffectKind;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * current(TransactionContext,string $rootRef): CapacitySnapshot; reserve(TransactionContext,string $rootRef,string $operationRef,EffectKind,Money,string $reservationRef): Reservation; settle(TransactionContext,string $reservationRef,MaterialSnapshot,?AbsenceCertificate): CapacitySnapshot. Locks part of preplanned root/child/capacity set; implementation only EVIDENCE_CAPACITY.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
interface CapacityParticipants
{
    public function current(TransactionContext $transactionContext, string $rootRef): CapacitySnapshot;

    public function reserve(TransactionContext $transactionContext, string $rootRef, string $operationRef, EffectKind $effectKind, Money $money, string $reservationRef): Reservation;

    public function settle(TransactionContext $transactionContext, string $reservationRef, MaterialSnapshot $materialSnapshot, ?AbsenceCertificate $absenceCertificate): CapacitySnapshot;
}
