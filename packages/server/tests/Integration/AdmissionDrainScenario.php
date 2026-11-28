<?php

declare(strict_types=1);

namespace Billium\Server\Tests\Integration;

use Billium\Conformance\Http\LoopbackHost;
use Billium\Contracts\Contracts\Codec;
use Billium\Core\Contracts\Modules\ModuleAdmissionGate;
use Billium\Core\Contracts\Modules\ModuleTransitionPlanner;
use Billium\Core\Data\Modules\ModuleAdmission;
use Billium\Core\Data\Modules\ModuleState;
use Billium\Core\Data\Modules\ModuleTransitionFacts;
use Billium\Core\Data\Modules\ModuleTransitionPreview;
use Billium\Core\Diagnostics\BilliumCompatibilityDoctorCheck;
use Billium\Core\Enums\Modules\ModuleLifecycleState;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Tests\Support\Concurrency\Scenario;
use Billium\Core\Tests\TestEnvironment;
use Billium\Execution\Tests\Concurrency\AdmissionIdentityTest as Fixture;
use BilliumMinimalHost\Host;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Application;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Enums\CheckStatus;
use RuntimeException;

/** The contenders share a deployment and wait on the real module row lock. */
final class AdmissionDrainScenario implements Scenario
{
    public function run(Application $app, int $index, array $args): array
    {
        require_once dirname(__DIR__, 4).'/conformance/http/bootstrap.php';
        $directory = $args['directory'];
        $host = new LoopbackHost($directory, 'execution');
        $http = $host->application(TestEnvironment::databaseName());
        $http['config']->set('app.debug', true);
        $host->admitProcess($http);
        $db = LoopbackHost::database($http);
        $drainFirst = $args['drainFirst'] ?? false;
        if ($index === 0) {
            $http->extend(ModuleAdmissionGate::class, static fn (ModuleAdmissionGate&ModuleTransitionPlanner $gate) => new readonly class($gate, $db, $directory, $drainFirst) implements ModuleAdmissionGate, ModuleTransitionPlanner
            {
                public function __construct(
                    private ModuleAdmissionGate&ModuleTransitionPlanner $gate,
                    private Connection $db,
                    private string $directory,
                    private bool $drainFirst,
                ) {}

                public function admit(ModuleState $state, ModuleOperationClass $class): ModuleAdmission
                {
                    if (! $this->drainFirst && $state->moduleId === 'execution' && $class === ModuleOperationClass::NewIntent) {
                        AdmissionDrainScenario::waitWithModuleLock($this->db, $this->directory);
                    }

                    return $this->gate->admit($state, $class);
                }

                public function previewTransition(ModuleState $current, ModuleLifecycleState $target, ModuleTransitionFacts $facts): ModuleTransitionPreview
                {
                    if ($this->drainFirst) {
                        AdmissionDrainScenario::waitWithModuleLock($this->db, $this->directory);
                    }

                    return $this->gate->previewTransition($current, $target, $facts);
                }

                public function previewUninstall(ModuleState $current, ModuleTransitionFacts $facts): ModuleTransitionPreview
                {
                    return $this->gate->previewUninstall($current, $facts);
                }
            });
        }
        if ($drainFirst) {
            if ($index === 0) {
                $host->drain($http);

                return ['waited' => is_file($directory.'/contender-waited')];
            }
            self::await(static fn (): bool => is_file($directory.'/module-lock-held'));

            return self::admit($http, []);
        }

        if ($index === 0) {
            $changes = [];
        } elseif ($index === 1) {
            self::await(static fn (): bool => is_file($directory.'/module-lock-held'));
            $host->drain($http);
            touch($directory.'/drain-committed');

            return ['waited' => is_file($directory.'/contender-waited')];
        } else {
            self::await(static fn (): bool => is_file($directory.'/drain-committed'));
            $changes = $index === 2 ? ['operationRef' => 'after-drain', 'commandKey' => Fixture::key(3)] : [];
        }

        return self::admit($http, $changes);
    }

    /** @param array<string,mixed> $changes
     * @return array<string,mixed>
     */
    private static function admit(Application $http, array $changes): array
    {
        $request = new Codec()->encode(Fixture::wire($changes));
        $response = new Host('owner')->request($http, '/billium/v1/sellers/seller-a/payments/execute', $request);
        if ($response->getStatusCode() === 503) {
            $findings = $http->make(BilliumCompatibilityDoctorCheck::class)->run(new DoctorContext(production: true));
            $failures = array_filter($findings, static fn ($finding): bool => $finding->status === CheckStatus::Error);
            throw new RuntimeException('Unexpected admission availability: '.json_encode($failures, JSON_THROW_ON_ERROR));
        }
        if ($response->getStatusCode() >= 500) {
            $failure = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
            throw new RuntimeException('Unexpected HTTP failure: '.($failure['message'] ?? 'unknown'));
        }

        return ['status' => $response->getStatusCode(), 'body' => json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)];
    }

    public static function waitWithModuleLock(Connection $db, string $directory): void
    {
        touch($directory.'/module-lock-held');
        self::await(static fn (): bool => $db->scalar(
            'SELECT EXISTS (SELECT 1 FROM pg_stat_activity WHERE datname=current_database() AND pg_backend_pid() = ANY(pg_blocking_pids(pid)))',
        ) === true);
        touch($directory.'/contender-waited');
    }

    public static function await(callable $condition): void
    {
        $deadline = hrtime(true) + 10_000_000_000;
        while (! $condition()) {
            if (hrtime(true) >= $deadline) {
                throw new RuntimeException('Timed out waiting for the admission/drain checkpoint');
            }
            clearstatcache();
            usleep(10_000);
        }
    }
}
