<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Contract;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Ledger\Data\AmendObligationDuePolicy;
use Billium\Ledger\Data\BillingAccountScope;
use Billium\Ledger\Data\DeclareSettlementIntent;
use Billium\Ledger\Data\ObligationDuePolicy;
use Billium\Ledger\Data\RecordCustomerCredit;
use Billium\Ledger\Data\RecordObligation;
use Closure;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class LedgerQueryShapeTest extends TestCase
{
    public function test_money_commands_require_an_explicit_account_scope(): void
    {
        foreach ([['', 'test', 'account-1'], ['seller-1', '', 'account-1'], ['seller-1', 'test', '']] as $parts) {
            self::refuses(fn () => new BillingAccountScope(...$parts));
        }
        $scope = new BillingAccountScope('seller-1', 'test', 'account-1');
        $money = (new Codec)->decodeMoney('{"amount":"10.00","currency":"RUB"}');
        $obligation = new RecordObligation('key-1', $scope, 'obl-1', 'basis-1', 'customer-1', 'component-1', $money, 'sha256:'.str_repeat('a', 64));
        $credit = new RecordCustomerCredit('key-2', $scope, 'credit-1', 'source-1', $money);
        $intent = new DeclareSettlementIntent('key-3', $scope, 'intent-1', 'RUB', ['obl-2', 'obl-1']);
        self::assertSame($scope, $obligation->scope);
        self::assertSame($scope, $credit->scope);
        self::assertSame($scope, $intent->scope);
        self::assertSame(['obl-2', 'obl-1'], $intent->obligationRefs);
        self::assertNull($obligation->duePolicy->dueAt);
        self::assertNull($obligation->duePolicy->collectionEligibleAt);
        self::assertFalse($obligation->duePolicy->hasProvenance());
        // Reflection models an untyped PHP caller violating the documented list<string> input.
        self::refuses(fn () => new \ReflectionClass(DeclareSettlementIntent::class)->newInstanceArgs(['key', $scope, 'intent', 'RUB', [2 => 'obl-1']]));
        self::refuses(fn () => new DeclareSettlementIntent('key', $scope, 'intent', 'RUB', ['']));
    }

    public function test_nonnull_due_dates_require_complete_frozen_host_provenance(): void
    {
        $date = new DateTimeImmutable('2026-10-28T00:00:00Z');
        self::refuses(fn () => new ObligationDuePolicy(dueAt: $date));
        self::refuses(fn () => new ObligationDuePolicy(collectionEligibleAt: $date));
        self::refuses(fn () => new ObligationDuePolicy(duePolicyVersion: 'host/1'));
        self::refuses(fn () => new ObligationDuePolicy($date, 'host/1', 'host-policy', 'bad-digest'));
        $policy = new ObligationDuePolicy($date, 'host/1', 'host-policy', 'sha256:'.str_repeat('a', 64), $date);
        self::assertSame($date, $policy->dueAt);
        self::assertSame($date, $policy->collectionEligibleAt);
        self::assertTrue($policy->hasProvenance());
    }

    public function test_disabling_automatic_due_collection_is_explicit_and_keeps_provenance(): void
    {
        $scope = new BillingAccountScope('seller-1', 'test', 'account-1');
        self::refuses(fn () => new AmendObligationDuePolicy('key-1', $scope, 'obl-1', new ObligationDuePolicy));
        $disabled = new ObligationDuePolicy(duePolicyVersion: 'host/2', sourcePolicyRef: 'host-policy', sourcePolicySnapshotDigest: 'sha256:'.str_repeat('b', 64));
        $command = new AmendObligationDuePolicy('key-2', $scope, 'obl-1', $disabled);
        self::assertSame('key-2', $command->commandKey);
        self::assertNull($command->duePolicy->dueAt);
        self::assertSame('host/2', $command->duePolicy->duePolicyVersion);
    }

    private static function refuses(Closure $operation): void
    {
        try {
            $operation();
        } catch (ProblemException $exception) {
            self::assertSame(ProblemCode::ValidationInvalid, $exception->problem);

            return;
        }
        self::fail('expected a validation refusal');
    }
}
