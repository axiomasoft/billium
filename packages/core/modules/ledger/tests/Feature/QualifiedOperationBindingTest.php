<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Feature;

use Billium\Ledger\Tests\Schema\LedgerTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
final class QualifiedOperationBindingTest extends LedgerTestCase
{
    public function test_refund_first_claim_serializes_root_and_operation_and_rejects_immutable_identity_changes(): void
    {
        $claim = fn (string $ref, string $root, ?string $owner, string $account = 'pa1', string $source = 'remote-billium'): mixed => $this->value("SELECT public.billium_claim_observation_owner(?,?,?,?,?,?,?,?, '{}'::jsonb)",
            [self::INSTALLATION, 'seller-a', 'test', $source, $account, $ref, $root, $owner]);
        $root = str_repeat('r', 200);
        self::assertSame('accepted', $claim('refund', $root, 'a'));
        self::assertSame('accepted', $claim($root, $root, 'a'));
        self::assertSame('denied', $claim('refund', 'different-root', 'a'));
        self::assertSame('denied', $claim($root, $root, 'b'));
        self::assertSame('denied', $claim($root, $root, 'a', 'another-provider-account'));
        self::assertSame('denied', $claim($root, $root, null));
        self::assertSame('accepted', $claim($root, $root, 'b', 'pa2', 'standalone-billium'));
        self::assertSame(3, $this->rows('billium_observation_owner_binding'));
        self::assertSame(0, $this->rows('billium_observed_payment'));
        self::assertSame(0, $this->rows('billium_allocation'));
    }
}
