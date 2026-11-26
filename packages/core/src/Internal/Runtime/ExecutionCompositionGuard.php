<?php

declare(strict_types=1);

namespace Billium\Core\Internal\Runtime;

use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Execution\Contracts\ExecutionReadiness;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use LogicException;
use Packman\Attributes\Internal;
use Packman\Config\Laravel\ConfigurationValidator;

/** Validates outgoing startup without resolving a provider effect or opening a financial root. */
#[Internal]
final readonly class ExecutionCompositionGuard
{
    public function assertReady(Application $app, BilliumOptions $options, bool $ledgerActive): void
    {
        $validator = new ConfigurationValidator;
        if (! in_array($options->modules->composition, ['remote-billium', 'standalone-billium'], true)) {
            $validator->add('billium.modules.composition', 'execution requires remote-billium or standalone-billium');
        }
        if (! in_array('platform', $options->modules->enabled, true) || ! $ledgerActive) {
            $validator->add('billium.modules.enabled', 'an execution composition requires platform and ledger');
        }
        $validator->failIfAny();
        if ($options->execution->outgoingEnabled) {
            $release = $options->execution->releaseEvidenceRef;
            $retention = $options->execution->retentionPolicyRef;
            if ($release === null) {
                $validator->add('billium.execution.release_evidence_ref', 'is required for outgoing startup');
            }
            if ($retention === null) {
                $validator->add('billium.execution.retention_policy_ref', 'is required for outgoing startup');
            }
            if (! $app->bound(ExecutionReadiness::class)) {
                $validator->add('billium.execution.outgoing_enabled', 'requires ExecutionReadiness verification of qualification, authority and hard-fence integration');
            }
            $validator->failIfAny();
            if ($release !== null && $retention !== null) {
                foreach ($app->make(DatabaseManager::class)->getConnections() as $connection) {
                    if ($connection->transactionLevel() !== 0 || $connection->getPdo()->inTransaction()) {
                        throw new LogicException('Execution startup verification requires transaction level zero');
                    }
                }
                try {
                    $app->make(ExecutionReadiness::class)->assertConfigured($release, $retention);
                } catch (ProblemException $error) {
                    $validator->add('billium.execution.outgoing_enabled', 'ExecutionReadiness refused configured operational evidence: '.$error->problem->value);
                    $validator->failIfAny();
                }
            }
        }
    }
}
