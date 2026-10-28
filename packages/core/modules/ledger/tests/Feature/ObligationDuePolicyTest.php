<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Feature;

use Billium\Contracts\Data\Money;
use Billium\Core\Tests\TestEnvironment;
use Billium\Ledger\Data\ObligationDuePolicy;
use Billium\Ledger\Data\RecordObligation;
use Billium\Ledger\Tests\Schema\LedgerTestCase;
use DateTimeImmutable;

final class ObligationDuePolicyTest extends LedgerTestCase
{
    public function test_null_due_date_never_becomes_automatic_and_explicit_boundary_is_retained(): void
    {
        $this->obligation('no-due');
        $due = new DateTimeImmutable('2026-10-28T10:00:00Z');
        $policy = new ObligationDuePolicy($due, 'host/1', 'policy', self::digest(), $due);
        $this->ledger()->recordObligation(new RecordObligation('due-key', $this->account(), 'due', 'due-basis', 'customer', 'component', Money::parse('1.00', 'RUB'), self::digest(), duePolicy: $policy));
        self::assertSame(['due'], array_column($this->table("SELECT obligation_ref FROM billium_obligation WHERE seller_ref='seller-a' AND environment='test' AND status='open' AND due_at <= '2099-01-01' ORDER BY due_at,id"), 'obligation_ref'));
        self::assertSame(0, $this->rows('billium_obligation', "due_at < '2026-10-28 10:00:00+00'"));
        self::assertSame('host/1', $this->value("SELECT due_policy_version FROM billium_obligation WHERE obligation_ref='due'"));
        self::assertSame('23514', self::sqlState(TestEnvironment::pdo(owner: true), "UPDATE billium_obligation SET due_at=now(),revision=revision+1 WHERE obligation_ref='no-due'"));
        self::assertStringContainsString('WHERE (due_at IS NOT NULL)', self::text($this->value("SELECT indexdef FROM pg_indexes WHERE indexname='billium_obligation_due_idx'")));
    }
}
