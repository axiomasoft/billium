<?php

declare(strict_types=1);

namespace Billium\Core\Tests\P7\Support;

use Billium\Collection\Contracts\ConsentBindingService;
use Billium\Collection\Data\CollectionScope;
use Billium\Collection\Data\RevokeConsentCommand;
use Billium\Collection\Enums\TombstoneKind;
use Billium\Collection\Laravel\CollectionServiceProvider;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Contracts\Runtime\FinancialSessionAdmission;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Tests\Support\Concurrency\Scenario;
use Billium\Core\Tests\Support\FixedCallerContext;
use Billium\Core\Tests\Support\FixedSessionAdmission;
use Billium\Core\Tests\Support\SimulatedExecutionReadiness;
use Billium\Core\Tests\Support\SimulatedFinancialAuthorityEvidence;
use Billium\Execution\Laravel\ExecutionServiceProvider;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;

final class ConsentGuardRaceScenario implements Scenario
{
    public static function prepare(Application $app): void
    {
        $base = FixedCallerContext::default()->current();
        $app->instance(CallerContext::class, new FixedCallerContext(new Caller(
            $base->sellerRef,
            $base->environment,
            $base->installationRef,
            $base->actorRef,
            ['remote-billium'],
            ['source-a'],
        )));
        $app->instance(FinancialSessionAdmission::class, new FixedSessionAdmission(FixedSessionAdmission::token()));
        SimulatedFinancialAuthorityEvidence::admitCurrent($app);
        SimulatedExecutionReadiness::install($app);
        $config = $app->make(Repository::class);
        $config->set('billium.modules.composition', 'standalone-billium');
        $config->set('billium.modules.enabled', ['platform', 'ledger', 'execution', 'collection']);
        $config->set('billium.collection.enabled', true);
        $app->register(ExecutionServiceProvider::class);
        $app->register(CollectionServiceProvider::class);
    }

    /** @param array<string, mixed> $args */
    public function run(Application $app, int $index, array $args): array
    {
        self::prepare($app);

        /** @var ConsentBindingService $service */
        $service = $app->make(ConsentBindingService::class);
        $consentRef = (string) ($args['consentRef'] ?? 'consent-race');
        $expectedGuardRevision = isset($args['expectedGuardRevision']) && is_int($args['expectedGuardRevision'])
            ? $args['expectedGuardRevision']
            : null;
        $digest = 'sha256:'.str_repeat('a', 64);

        try {
            $receipt = $service->revokeOrCancel(new RevokeConsentCommand(
                commandKey: 'race-revoke-'.$index,
                scope: new CollectionScope('seller-a', 'test'),
                consentRef: $consentRef,
                tombstoneRef: 'tomb-race-'.$index,
                sourceRevision: 5,
                cancellationGeneration: 1,
                reason: 'concurrent_customer_revoke_'.$index,
                evidenceDigest: $digest,
                tombstoneKind: TombstoneKind::ConsentRevoked,
                targetAuthorizationRef: 'auth-race-1',
                targetBusinessIntentRef: 'intent-race-1',
                expectedGuardRevision: $expectedGuardRevision,
            ));

            return [
                'accepted' => true,
                'tombstoneRef' => $receipt->tombstoneRef,
                'guardRevision' => $receipt->guardRevision,
                'status' => $receipt->status->value,
            ];
        } catch (ProblemException $error) {
            return [
                'accepted' => false,
                'problem' => $error->problem->value,
                'detail' => $error->detail,
            ];
        }
    }
}
