<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Concurrency\Scenarios;

use Billium\Core\Contracts\Modules\ModuleTransitionPlanner;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Contracts\Runtime\FinancialSessionAdmission;
use Billium\Core\Data\Modules\ModuleState;
use Billium\Core\Data\Modules\ModuleTransitionFacts;
use Billium\Core\Data\Modules\ModuleTransitionPreview;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Data\Runner\Receipt;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Enums\Modules\ModuleLifecycleState;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Exceptions\Modules\ModuleAdmissionRefused;
use Billium\Core\Tests\Support\Concurrency\Scenario;
use Billium\Core\Tests\Support\FixedCallerContext;
use Billium\Core\Tests\Support\FixedSessionAdmission;
use Billium\Core\Tests\Support\SimulatedFinancialAuthorityEvidence;
use Billium\Platform\Contracts\Installation\Installation;
use Billium\Platform\Data\Installation\ModuleTransitionRequest;
use Billium\Platform\Exceptions\TransitionRefused;
use Billium\Platform\Tests\PlatformTestCase;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;

/**
 * One worker of a platform race: a root operation, a seal or a module transition, driven by JSON arguments. Each
 * worker joins the session with its own host process token. Typed refusals are results, not crashes.
 */
final readonly class RootOperationScenario implements Scenario
{
    /** @param array<string, mixed> $args */
    public function __construct(Application $app, int $index, array $args)
    {
        $args = self::forWorker($args, $index);
        $caller = self::map($args, 'caller');
        $app->instance(CallerContext::class, new FixedCallerContext(new Caller(
            self::str($caller, 'seller', 'seller-a'), self::str($caller, 'environment', 'test'), self::str($caller, 'installation', PlatformTestCase::INSTALLATION), 'actor-'.$index,
        )));
        $token = self::map($args, 'token') + ['processRef' => 'process-'.$index];
        $app->instance(FinancialSessionAdmission::class, new FixedSessionAdmission(FixedSessionAdmission::fromArray($token)));
        SimulatedFinancialAuthorityEvidence::admitCurrent($app);
        $plannerDelay = self::int($args, 'planner_delay_ms', 0);
        if ($plannerDelay > 0) {
            $inner = $app->make(ModuleTransitionPlanner::class);
            $app->instance(ModuleTransitionPlanner::class, new readonly class($inner, $plannerDelay) implements ModuleTransitionPlanner
            {
                public function __construct(private ModuleTransitionPlanner $inner, private int $delayMs) {}

                public function previewTransition(ModuleState $current, ModuleLifecycleState $target, ModuleTransitionFacts $facts): ModuleTransitionPreview
                {
                    usleep($this->delayMs * 1000); // runs while the transition holds its module row locks

                    return $this->inner->previewTransition($current, $target, $facts);
                }

                public function previewUninstall(ModuleState $current, ModuleTransitionFacts $facts): ModuleTransitionPreview
                {
                    return $this->inner->previewUninstall($current, $facts);
                }
            });
        }
        $app->make(OperationRunner::class);
    }

    public function run(Application $app, int $index, array $args): array
    {
        $args = self::forWorker($args, $index);
        usleep(self::int($args, 'delay_ms', 0) * 1000);
        $started = hrtime(true);
        $action = self::str($args, 'action', 'run');
        if ($action === 'seal') {
            SimulatedFinancialAuthorityEvidence::install($app)->issueTransition('seal', self::str($args, 'ref', 'seal-1'), [self::int($args, 'epoch', 1)]);
        }
        try {
            $result = match ($action) {
                'run' => $this->operation($app, $index, $args),
                'seal' => ['state' => $app->make(Installation::class)->seal(self::str($args, 'ref', 'seal-1'), self::int($args, 'epoch', 1))->state],
                'transition' => $app->make(Installation::class)->transitionModule(new ModuleTransitionRequest(
                    self::str($args, 'ref', 'transition-1'), self::str($args, 'module', 'ledger'),
                    ModuleLifecycleState::from(self::str($args, 'target', 'draining')), 'race', null,
                ))->body,
                default => throw new \InvalidArgumentException("unknown action {$action}"),
            };
        } catch (ModuleAdmissionRefused $refused) {
            $result = ['refused' => $refused->reason, 'module' => $refused->moduleId];
        } catch (TransitionRefused $refused) {
            $result = ['refused' => $refused->reason];
        }

        return $result + ['process_id' => getmypid(), 'backend_pid' => (int) $app->make(DatabaseManager::class)->connection()->scalar('SELECT pg_backend_pid()'), 'elapsed_ms' => intdiv(hrtime(true) - $started, 1_000_000), 'finished_at' => microtime(true)];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function operation(Application $app, int $index, array $args): array
    {
        $caller = $app->make(CallerContext::class)->current();
        $key = isset($args['key']) && is_string($args['key']) ? $args['key'] : null;
        $digests = isset($args['digests']) && is_array($args['digests']) ? array_values(array_filter($args['digests'], is_string(...))) : [];
        $digest = $digests === [] ? 'sha256:'.str_repeat('a', 64) : $digests[$index % count($digests)];
        $mode = FinancialMode::from(self::str($args, 'mode', 'active'));
        $modules = isset($args['modules']) && is_array($args['modules']) ? array_values(array_filter($args['modules'], is_string(...))) : ['ledger', 'platform'];
        $spec = new OperationSpec(
            self::str($args, 'kind', 'ledger.test_effect'), $caller->sellerRef, $caller->environment, $mode,
            ModuleOperationClass::from(self::str($args, 'class', 'new_intent')), $modules,
            $key, $key === null ? null : $digest, $mode->financial() ? 'ledger.test_effect' : null,
        );
        $sleep = self::int($args, 'body_sleep_ms', 0);
        $outcome = $app->make(OperationRunner::class)->run($spec, static function (TransactionContext $context) use ($index, $sleep): Receipt {
            $effect = PlatformTestCase::effect($context, 'worker '.$index);
            if ($sleep > 0) {
                PlatformTestCase::connection($context->connectionName())->select('SELECT pg_sleep(?)', [$sleep / 1000]);
            }

            return PlatformTestCase::receipt(['worker' => $index, 'effect' => $effect, 'generations' => $context->admittedModules()]);
        });

        return ['accepted' => $outcome->accepted, 'replayed' => $outcome->replayed, 'receipt' => $outcome->receiptId, 'body' => $outcome->body];
    }

    /**
     * `workers` gives each index its own role in a race; other keys are shared.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private static function forWorker(array $args, int $index): array
    {
        $workers = $args['workers'] ?? null;
        $own = is_array($workers) && is_array($workers[$index] ?? null) ? $workers[$index] : [];
        unset($args['workers']);

        /** @var array<string, mixed> */
        return $own + $args;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private static function map(array $args, string $key): array
    {
        $value = $args[$key] ?? [];
        $map = [];
        foreach (is_array($value) ? $value : [] as $name => $item) {
            $map[(string) $name] = $item;
        }

        return $map;
    }

    /** @param array<string, mixed> $args */
    private static function str(array $args, string $key, string $default): string
    {
        return isset($args[$key]) && is_string($args[$key]) ? $args[$key] : $default;
    }

    /** @param array<string, mixed> $args */
    private static function int(array $args, string $key, int $default): int
    {
        return isset($args[$key]) && is_int($args[$key]) ? $args[$key] : $default;
    }
}
