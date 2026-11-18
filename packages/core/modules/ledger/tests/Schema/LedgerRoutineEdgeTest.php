<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Schema;

use PDOException;
use PHPUnit\Framework\Attributes\Group;

/** Edge cases of the guarded money routines, called directly as the runtime role. */
#[Group('pg')]
final class LedgerRoutineEdgeTest extends LedgerTestCase
{
    public function test_a_missing_detail_total_never_resolves_an_open_refund_basis_mismatch(): void
    {
        $payment = $this->payment();
        $this->observedRevision($this->observedCharge());
        $this->owner()->update('UPDATE billium_payment SET applied_revision=1');
        $recorded = $this->reconcile($payment, 0, 100);
        self::assertTrue(self::arr($recorded['body'] ?? null)['recorded'] ?? null);
        self::assertSame('billium.validation.invalid', $this->refusal(fn (): array => $this->reconcile($payment, 0, null)));
        self::assertSame('billium.validation.invalid', $this->refusal(fn (): array => $this->reconcile($payment, null, 0)));
        self::assertSame(0, $this->rows('billium_discrepancy_event', "kind='resolved'"));
    }

    private function observedCharge(): int
    {
        return self::integer($this->value(<<<'SQL'
            INSERT INTO billium_observed_payment(installation_ref,seller_ref,environment,source_authority,source_lineage,operation_ref,kind,revision,material_digest,state,currency,requested_minor,authorized_minor,captured_minor,refunded_minor,voided_minor,provider_account_ref,evidence_ref)
            VALUES('inst-test','seller-a','test','authority-a','lineage-a','operation-a','charge',1,?,'succeeded','RUB',1000,1000,1000,0,0,'merchant','evidence') RETURNING id
            SQL, [self::digest('b')]));
    }

    private function observedRevision(int $observed): void
    {
        $this->owner()->insert(<<<'SQL'
            INSERT INTO billium_observed_payment_revision(installation_ref,seller_ref,environment,observed_payment_id,revision,material_digest,state,
                requested_minor,authorized_minor,captured_minor,refunded_minor,voided_minor,currency,provider_account_ref,evidence_ref,payload)
            VALUES('inst-test','seller-a','test',?,1,?,'succeeded',1000,1000,1000,0,0,'RUB','merchant','evidence','{}')
            SQL, [$observed, self::digest('b')]);
    }

    /** @return array<string,mixed> */
    private function reconcile(int $payment, ?int $chargeRefunded, ?int $detailRefunded): array
    {
        return $this->routine('reconcile_refund_basis', ['inst-test', 'seller-a', 'test', $payment, 1, self::digest('c'), 1, $chargeRefunded, $detailRefunded, 'evidence']);
    }

    /** @param callable(): array<string,mixed> $call */
    private function refusal(callable $call): string
    {
        try {
            $call();
        } catch (PDOException $e) {
            return preg_match('/billium\.[a-z_.]+/', $e->getMessage(), $m) === 1 ? $m[0] : $e->getMessage();
        }
        self::fail('the routine accepted the call');
    }
}
