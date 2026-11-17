<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Observation;

use Billium\Contracts\Data\Money;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Contracts\Runtime\FinancialSessionAdmission;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Tests\Support\Concurrency\Scenario;
use Billium\Core\Tests\Support\FixedCallerContext;
use Billium\Core\Tests\Support\FixedSessionAdmission;
use Billium\Core\Tests\Support\SimulatedFinancialAuthorityEvidence;
use Billium\Ledger\Contracts\Ledger;
use Billium\Ledger\Contracts\ObservationApplier;
use Billium\Ledger\Data\Allocations\Allocate;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Billium\Platform\Tests\PlatformTestCase;
use Illuminate\Foundation\Application;
use InvalidArgumentException;

/**
 * One worker of the observation races (ACC-CON-24/26, allocation vs observation): `plan[index]` is either
 * {action: observe, json} — one delivery through the public ObservationApplier, which drains whatever becomes next in
 * new roots — or {action: allocate, obligation, payment, amount} through the public Ledger root of this process.
 */
final readonly class ObservationScenario implements Scenario
{
    /** @param array<string, mixed> $args */
    public function __construct(Application $app, int $index, array $args = [])
    {
        $granted = $args['authorities'] ?? ObservationTestCase::AUTHORITIES;
        $authorities = is_array($granted) ? array_values(array_filter($granted, is_string(...))) : ObservationTestCase::AUTHORITIES;
        $app->instance(CallerContext::class, new FixedCallerContext(new Caller('seller-a', 'test', PlatformTestCase::INSTALLATION, 'actor-'.$index, $authorities)));
        $app->instance(FinancialSessionAdmission::class, new FixedSessionAdmission(FixedSessionAdmission::token(PlatformTestCase::INSTALLATION, processRef: 'process-'.$index)));
        SimulatedFinancialAuthorityEvidence::admitCurrent($app);
    }

    /** @param array<string, mixed> $args */
    public function run(Application $app, int $index, array $args): array
    {
        $step = self::step($args, $index);
        $delay = $step['delay_ms'] ?? 0;
        usleep((is_int($delay) ? $delay : 0) * 1000);
        if (self::text($step, 'action') === 'allocate') {
            $outcome = $app->make(Ledger::class)->allocate(new Allocate('k-race-'.$index, new BillingAccountScope('seller-a', 'test', 'account-a'), 'race-'.$index,
                self::text($step, 'obligation'), Money::parse(self::text($step, 'amount'), 'RUB'), self::text($step, 'payment')));

            return ['action' => 'allocate', 'receipt' => $outcome->accepted ? 'allocation:race-'.$index : null, 'problem' => $outcome->problem?->value];
        }
        try {
            $receipt = $app->make(ObservationApplier::class)->apply(self::text($step, 'json'));

            return ['action' => 'observe', 'receipt' => $receipt->receiptRef, 'mode' => $receipt->mode(), 'problem' => null];
        } catch (ProblemException $problem) {
            return ['action' => 'observe', 'receipt' => null, 'mode' => null, 'problem' => $problem->problem->value];
        }
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private static function step(array $args, int $index): array
    {
        $plan = $args['plan'] ?? null;
        $step = is_array($plan) ? ($plan[$index] ?? null) : null;
        if (! is_array($step)) {
            throw new InvalidArgumentException('missing plan step '.$index);
        }
        $typed = [];
        foreach ($step as $key => $value) {
            $typed[(string) $key] = $value;
        }

        return $typed;
    }

    /** @param array<string, mixed> $step */
    private static function text(array $step, string $key): string
    {
        $value = $step[$key] ?? null;

        return is_string($value) ? $value : throw new InvalidArgumentException('missing '.$key);
    }
}
