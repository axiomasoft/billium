<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Feature;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Core\Tests\Support\ObservationFactory;
use Billium\Core\Tests\Support\SimulatedFinancialAuthorityEvidence;
use Billium\Ledger\Tests\Observation\ObservationTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * D54/D60: raw custody of an authenticated observation commits in its own RawCustody root even while the financial
 * session is sealed; the economic root is refused, so no money moves. After a new session is admitted, a drain applies
 * the delivery exactly once and a redelivery replays its receipt.
 */
#[Group('D60')]
#[Group('pg')]
final class SealedSessionCustodyTest extends ObservationTestCase
{
    public function test_custody_survives_a_sealed_session_and_a_later_admitted_drain_applies_it_once(): void
    {
        $this->debt('o1', 1000);
        $this->intent('intent-a', ['o1']);
        SimulatedFinancialAuthorityEvidence::install($this->application())->issueTransition('seal', 'seal-1', [1]);
        $this->installation()->seal('seal-1', 1);
        $charge = ObservationFactory::charge('op-1', 1, 1000, sequence: 1);

        self::assertSame(ProblemCode::RecoveryRequired->value, $this->tryObserve($charge), 'financial admission refused after custody committed');

        self::assertSame('ready', $this->inboxStatus(self::eventId($charge)));
        self::assertSame(1, self::integer($this->partition()['custody_sequence']));
        self::assertSame(0, self::integer($this->partition()['accounted_sequence']));
        foreach (['billium_observed_payment', 'billium_payment', 'billium_allocation'] as $table) {
            self::assertSame(0, $this->rows($table), $table.' unchanged while sealed');
        }
        self::assertSame(0, $this->allocated('o1'));

        SimulatedFinancialAuthorityEvidence::install($this->application())->issueTransition('open', 'open-2', ['owner-1', 1, 1, 1, 'evidence:open-2']);
        $this->installation()->openSession('open-2', 'owner-1', 1, 1, 1, 'evidence:open-2');
        SimulatedFinancialAuthorityEvidence::install($this->application())->issueTransition('activate', 'activate-2', [2, 'certificate:2', 'admission:2', 'evidence:activate-2']);
        $this->installation()->activateSession('activate-2', 2, 'certificate:2', 'admission:2', 'evidence:activate-2');
        $drained = $this->drainer()->drainPartition(self::integer($this->partition()['id']));

        self::assertCount(1, $drained);
        self::assertSame('applied', $this->inboxStatus(self::eventId($charge)));
        self::assertSame(1000, $this->allocated('o1'));
        self::assertSame('observation:embedded-commerz:op-1#1', $this->observe($charge)->receiptRef);
        self::assertSame(1, $this->rows('billium_allocation'));
        self::assertSame([], $this->drainer()->drainPartition(self::integer($this->partition()['id'])));
    }
}
