<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Concurrency\Scenarios;

use Billium\Core\Contracts\CallerContext;
use Billium\Core\Contracts\FinancialSessionAdmission;
use Billium\Core\Contracts\Modules\ModuleTransitionEvidence;
use Billium\Core\Contracts\OperationRunner;
use Billium\Core\Contracts\TransactionContext;
use Billium\Core\Data\Modules\ModuleTransitionFacts;
use Billium\Core\Data\OperationSpec;
use Billium\Core\Data\Receipt;
use Billium\Core\Enums\FinancialMode;
use Billium\Core\Enums\Modules\ModuleLifecycleState;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Tests\Support\Concurrency\Scenario;
use Billium\Core\Tests\Support\FixedCallerContext;
use Billium\Core\Tests\Support\FixedSessionAdmission;
use Billium\Platform\Contracts\Installation;
use Billium\Platform\Contracts\Outbox;
use Billium\Platform\Data\ModuleTransitionRequest;
use Billium\Platform\Data\OutboxEvent;
use Billium\Platform\Exceptions\TransitionRefused;
use Billium\Platform\Tests\PlatformTestCase;
use Billium\Platform\Tests\Support\CurrentTransitionEvidence;
use DateTimeImmutable;
use Illuminate\Foundation\Application;
use RuntimeException;

/** A real lock wait, with no sleep-based ordering: recovery commits only after retirement is blocked by its root. */
final readonly class RetirementEvidenceScenario implements Scenario
{
    /** @param array<string, mixed> $args */
    public function __construct(Application $app, int $index, array $args)
    {
        $app->instance(CallerContext::class, FixedCallerContext::default(installationRef: PlatformTestCase::INSTALLATION));
        $app->instance(FinancialSessionAdmission::class, new FixedSessionAdmission(FixedSessionAdmission::token(PlatformTestCase::INSTALLATION)));
        $app->instance(ModuleTransitionEvidence::class, new CurrentTransitionEvidence);
        if ($index === 0) {
            // Held before the stand releases its start barrier; retirement cannot overtake admission.
            PlatformTestCase::connection('pgsql')->select('SELECT pg_advisory_lock(?, ?)', [41933, $args['barrier']]);
        }
    }

    public function run(Application $app, int $index, array $args): array
    {
        $db = PlatformTestCase::connection('pgsql');
        if ($index === 1) {
            $request = new ModuleTransitionRequest('retire-after-recovery', 'ledger', ModuleLifecycleState::Retired, 'race', 'evidence:captured-zero', new ModuleTransitionFacts);
            $db->select('SELECT pg_advisory_lock_shared(?, ?)', [41933, $args['barrier']]);
            $db->select('SELECT pg_advisory_unlock_shared(?, ?)', [41933, $args['barrier']]);
            try {
                $outcome = $app->make(Installation::class)->transitionModule($request);

                return ['accepted' => $outcome->accepted];
            } catch (TransitionRefused $refused) {
                return ['refused' => $refused->reason, 'blockers' => $refused->blockers];
            }
        }

        $caller = $app->make(CallerContext::class)->current();
        $spec = new OperationSpec('ledger.recovery_event', $caller->sellerRef, $caller->environment, FinancialMode::Active,
            ModuleOperationClass::UnknownResolution, ['ledger', 'platform']);
        $outcome = $app->make(OperationRunner::class)->run($spec, static function (TransactionContext $context) use ($app, $db, $args): Receipt {
            $db->select('SELECT pg_advisory_unlock(?, ?)', [41933, $args['barrier']]);
            $deadline = microtime(true) + 10;
            do {
                $waiting = $db->selectOne('SELECT EXISTS (SELECT 1 FROM pg_stat_activity WHERE pg_backend_pid() = ANY(pg_blocking_pids(pid))) AS waiting');
                if (is_object($waiting) && ($waiting->waiting ?? false) === true) {
                    break;
                }
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Retirement never waited for the admitted recovery root');
                }
                usleep(10_000);
            } while (true);
            $app->make(Outbox::class)->record($context, new OutboxEvent(
                '6JYHEE84XV4DZPXY5HP39QSKBR', 'obligation.created.v1', 'billium.event.v1', 'x:1', 1, new DateTimeImmutable, [],
            ));

            return PlatformTestCase::receipt(['retirement_waited' => true]);
        });

        return ['accepted' => $outcome->accepted] + $outcome->body;
    }
}
