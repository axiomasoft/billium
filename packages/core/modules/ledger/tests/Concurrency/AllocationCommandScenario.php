<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Concurrency;

use Billium\Contracts\Data\Money;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Contracts\Runtime\FinancialSessionAdmission;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Tests\Support\Concurrency\Scenario;
use Billium\Core\Tests\Support\FixedCallerContext;
use Billium\Core\Tests\Support\FixedSessionAdmission;
use Billium\Core\Tests\Support\SimulatedFinancialAuthorityEvidence;
use Billium\Ledger\Contracts\Ledger;
use Billium\Ledger\Data\Allocations\Allocate;
use Billium\Ledger\Data\Invoices\CreditNoteLine;
use Billium\Ledger\Data\Invoices\CreditNoteMode;
use Billium\Ledger\Data\Invoices\IssueCreditNote;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Billium\Platform\Tests\PlatformTestCase;
use Illuminate\Foundation\Application;
use InvalidArgumentException;

/**
 * One worker of the allocation races (ACC-CON-08/09/19): `plan[index]` is either
 * {action: allocate, obligation, payment, amount} or {action: credit_note, obligation, invoice, amount}; every worker
 * has its own command key and business identity and runs the public Ledger root of its own process.
 */
final readonly class AllocationCommandScenario implements Scenario
{
    /** @param array<string, mixed> $args */
    public function __construct(Application $app, int $index, array $args = [])
    {
        $step = self::step($args, $index);
        $app->instance(CallerContext::class, new FixedCallerContext(new Caller(self::text($step, 'seller', 'seller-a'), 'test', PlatformTestCase::INSTALLATION, 'actor-'.$index)));
        $app->instance(FinancialSessionAdmission::class, new FixedSessionAdmission(FixedSessionAdmission::token(PlatformTestCase::INSTALLATION, processRef: 'process-'.$index)));
        SimulatedFinancialAuthorityEvidence::admitCurrent($app);
    }

    /** @param array<string, mixed> $args */
    public function run(Application $app, int $index, array $args): array
    {
        $step = self::step($args, $index);
        $ledger = $app->make(Ledger::class);
        $scope = new BillingAccountScope(self::text($step, 'seller', 'seller-a'), 'test', 'account-a');
        $amount = Money::parse(self::text($step, 'amount'), 'RUB');
        $ref = 'race-'.$index;
        $outcome = match (self::text($step, 'action')) {
            'allocate' => $ledger->allocate(new Allocate('k-'.$ref, $scope, $ref, self::text($step, 'obligation'), $amount, self::text($step, 'payment'))),
            'credit_note' => $ledger->issueCreditNote(new IssueCreditNote('k-'.$ref, $scope, $ref, self::text($step, 'invoice'), CreditNoteMode::OpenOnly,
                [new CreditNoteLine(self::text($step, 'obligation'), $amount)], 'race')),
            default => throw new InvalidArgumentException('unknown action'),
        };

        return ['action' => self::text($step, 'action'), 'accepted' => $outcome->accepted, 'problem' => $outcome->problem?->value];
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
    private static function text(array $step, string $key, ?string $default = null): string
    {
        $value = $step[$key] ?? $default;

        return is_string($value) ? $value : throw new InvalidArgumentException('missing '.$key);
    }
}
