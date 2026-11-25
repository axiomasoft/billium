<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Unit;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Billium\Ledger\Data\Settlements\CloseSettlementIntentNoEffect;
use Billium\Ledger\Data\Settlements\NoEffectProof;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The no-effect closure command and its proof refuse an incomplete shape before any database work. */
final class SettlementCommandShapeTest extends TestCase
{
    /** @return iterable<string, array{Closure(): object}> */
    public static function incomplete(): iterable
    {
        $scope = new BillingAccountScope('seller-a', 'test', 'account-a');
        yield 'closure without intent' => [static fn (): object => new CloseSettlementIntentNoEffect($scope, '', 1, 'step-1', 'closure-a', 'RUB', 'cancelled', null)];
        yield 'closure with a non-ISO currency' => [static fn (): object => new CloseSettlementIntentNoEffect($scope, 'intent-a', 1, 'step-1', 'closure-a', 'rub', 'cancelled', null)];
        yield 'proof without intent' => [static fn (): object => new NoEffectProof('root', '', 1, 'step-1', 'not_dispatched', 'evidence:no-dispatch')];
    }

    /** @param Closure(): object $make */
    #[DataProvider('incomplete')]
    public function test_an_incomplete_shape_is_refused(Closure $make): void
    {
        try {
            $make();
            self::fail('incomplete shape');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::ValidationInvalid, $e->problem);
        }
    }
}
