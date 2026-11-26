<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Queries;

use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Tests\Support\ObservationFactory;
use Billium\Ledger\Contracts\LedgerQueries;
use Billium\Ledger\Data\Obligations\AdjustObligation;
use Billium\Ledger\Tests\Commands\AllocationFixtures;
use Billium\Ledger\Tests\Commands\InvoiceFixtures;
use Billium\Ledger\Tests\Observation\ObservationTestCase;
use Closure;

abstract class QueryTestCase extends ObservationTestCase
{
    use AllocationFixtures;
    use InvoiceFixtures;

    protected function queries(): LedgerQueries
    {
        return $this->application()->make(LedgerQueries::class);
    }

    protected function seedQueryState(): void
    {
        $this->debt('obligation-a', 1000);
        $this->intent('intent-a', ['obligation-a']);
        $this->observe(ObservationFactory::charge('op-1', 1, 10000));
        $this->ledger()->adjustObligation(new AdjustObligation('adjust-key', $this->account(), 'adjustment-a', 'obligation-a', Money::parse('100.00', 'RUB'), 'basis-adjustment'))->orThrow();
        $this->draft()->orThrow();
        $this->issue()->orThrow();
        $this->allocateFrom('allocation-a', 'obligation-a', '50.00', 'op-1')->orThrow();
    }

    /** @param array<string, string> $selector
     * @param  array<string, mixed>  $extra
     */
    protected static function request(string $query, array $selector, array $extra = []): string
    {
        return json_encode(['schema' => 'billium.query.v1', 'installationRef' => self::INSTALLATION, 'sellerRef' => 'seller-a', 'environment' => 'test',
            'query' => $query, 'selector' => (object) $selector, ...$extra], JSON_THROW_ON_ERROR);
    }

    protected static function problem(ProblemCode $expected, Closure $read): void
    {
        try {
            $read();
            self::fail('Expected '.$expected->value);
        } catch (ProblemException $problem) {
            self::assertSame($expected, $problem->problem);
        }
    }
}
