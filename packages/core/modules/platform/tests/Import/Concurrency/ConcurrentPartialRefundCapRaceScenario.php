<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Import\Concurrency;

use Billium\Contracts\Contracts\Codec;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Contracts\Runtime\FinancialSessionAdmission;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Tests\Support\Concurrency\Scenario;
use Billium\Core\Tests\Support\FixedCallerContext;
use Billium\Core\Tests\Support\FixedSessionAdmission;
use Billium\Core\Tests\Support\SimulatedFinancialAuthorityEvidence;
use Billium\Platform\Contracts\Import\ImportBatchApplier;
use Billium\Platform\Data\Import\ImportApplyOptions;
use Billium\Platform\Internal\Import\Sources\GenericExportProfile;
use Billium\Platform\Internal\Import\Sources\GenericExportReader;
use Illuminate\Foundation\Application;

/**
 * IM-05 + IM-10:
 * N concurrent OS processes attempt to apply partial refund observations against the same original
 * charge owner across repacked batches and distinct over-capacity refund batches at the P3 advisory barrier.
 */
final readonly class ConcurrentPartialRefundCapRaceScenario implements Scenario
{
    private GenericExportReader $reader;

    /**
     * @param  array<string, mixed>  $args
     */
    public function __construct(Application $app, int $index, array $args)
    {
        $installationRef = is_string($args['installationRef'] ?? null) ? $args['installationRef'] : 'inst-test';
        $sellerRef = is_string($args['sellerRef'] ?? null) ? $args['sellerRef'] : 'seller-rfnd-race-1';

        $caller = new Caller(
            sellerRef: $sellerRef,
            environment: 'test',
            installationRef: $installationRef,
            actorRef: 'refund-race-worker-'.$index,
            sourceAuthorities: [ImportApplyOptions::DEFAULT_MIGRATION_SOURCE_AUTHORITY],
        );
        $callers = new FixedCallerContext($caller);
        $tokens = new FixedSessionAdmission;
        $tokens->set(FixedSessionAdmission::token($installationRef));

        $app->instance(CallerContext::class, $callers);
        $app->instance(FinancialSessionAdmission::class, $tokens);
        SimulatedFinancialAuthorityEvidence::install($app);
        SimulatedFinancialAuthorityEvidence::admitCurrent($app);

        /** @var list<array<string, mixed>> $workerArtifacts */
        $workerArtifacts = is_array($args['workerArtifacts'] ?? null) ? $args['workerArtifacts'] : [];
        $artifact = $workerArtifacts[$index] ?? $workerArtifacts[0];
        $artifact['batchId'] = 'batch-rfnd-race-w'.$index;
        $artifact['importPolicyVersion'] = 'policy-rfnd-w'.$index;
        unset($artifact['artifactDigest']);

        $this->reader = GenericExportReader::fromJson(
            json_encode($artifact, JSON_THROW_ON_ERROR),
            new GenericExportProfile(version: (1 + $index).'.0.0'),
            'policy-rfnd-w'.$index,
            new Codec,
        );
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function run(Application $app, int $index, array $args): array
    {
        $applier = $app->make(ImportBatchApplier::class);
        $manifest = $this->reader->manifest();
        $result = $applier->apply(
            $manifest,
            $this->reader->records(),
            new ImportApplyOptions(
                capabilityScope: ImportApplyOptions::REQUIRED_SCOPE,
                expectedManifestDigest: $manifest->manifestDigest,
            ),
        );

        return [
            'batchId' => $result->batchId,
            'batchStatus' => $result->batchStatus->value,
            'counts' => $result->counts,
        ];
    }
}
