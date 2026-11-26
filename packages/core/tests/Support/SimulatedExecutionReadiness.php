<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Support;

use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Execution\Contracts\ExecutionReadiness;
use Billium\Execution\Data\FrozenBinding;
use Billium\Execution\Enums\EffectKind;
use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use LogicException;

/** Explicit test-only dossier covers simulated test tenants; it never qualifies a production account. */
final readonly class SimulatedExecutionReadiness implements ExecutionReadiness
{
    public const string RELEASE = 'simulated:p5-release-v1';

    public const string RETENTION = 'simulated:p5-retention-indefinite-v1';

    /** @param Closure():bool $inTransaction */
    public function __construct(private Closure $inTransaction) {}

    public static function install(Application $app, bool $recompileOptions = false): void
    {
        $config = $app->make(Repository::class);
        $config->set('billium.modules.composition', 'remote-billium');
        $config->set('billium.execution', ['release_evidence_ref' => self::RELEASE, 'retention_policy_ref' => self::RETENTION, 'outgoing_enabled' => true]);
        $app->bind(ExecutionReadiness::class, static fn (Application $app): self => new self(static function () use ($app): bool {
            $db = $app->make(DatabaseManager::class)->connection();

            return $db->transactionLevel() !== 0 || $db->getPdo()->inTransaction();
        }));
        if ($recompileOptions) {
            $raw = $config->get('billium');
            if (! is_array($raw)) {
                throw new LogicException('Simulated worker configuration is missing');
            }
            $app->instance(BilliumOptions::class, BilliumOptions::fromArray($raw));
        }
    }

    public function assertConfigured(string $releaseEvidenceRef, string $retentionPolicyRef): void
    {
        if (($this->inTransaction)() || $releaseEvidenceRef !== self::RELEASE || $retentionPolicyRef !== self::RETENTION) {
            throw new ProblemException(ProblemCode::RecoveryRequired, 'Explicit simulated startup dossier is unavailable');
        }
    }

    public function assertReady(FrozenBinding $binding, EffectKind $kind, Money $amount, string $releaseEvidenceRef, string $retentionPolicyRef): void
    {
        if (($this->inTransaction)()) {
            throw new LogicException('Simulated readiness verification is outside database transactions');
        }
        if ($releaseEvidenceRef !== self::RELEASE || $retentionPolicyRef !== self::RETENTION
            || ! in_array($binding->scope->environment, ['test', 'testing', 'fixture-second-environment'], true)
            || ! in_array($binding->executionProfile, ['remote-billium', 'standalone-billium'], true)
            || $binding->origin === null || $amount->minor <= 0) {
            throw new ProblemException(ProblemCode::RecoveryRequired, 'No matching simulated release and retention dossier');
        }
    }
}
