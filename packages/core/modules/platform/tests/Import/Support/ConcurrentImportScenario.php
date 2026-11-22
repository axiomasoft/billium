<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Import\Support;

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

final readonly class ConcurrentImportScenario implements Scenario
{
    private GenericExportReader $reader;

    /**
     * @param  array<string, mixed>  $args
     */
    public function __construct(Application $app, int $index, array $args)
    {
        $installationRef = is_string($args['installationRef'] ?? null) ? $args['installationRef'] : 'inst-test';
        $sellerRef = is_string($args['sellerRef'] ?? null) ? $args['sellerRef'] : 'seller-conc-1';

        $caller = new Caller(
            sellerRef: $sellerRef,
            environment: 'test',
            installationRef: $installationRef,
            actorRef: 'worker-actor-'.$index,
            sourceAuthorities: [ImportApplyOptions::DEFAULT_MIGRATION_SOURCE_AUTHORITY],
        );
        $callers = new FixedCallerContext($caller);
        $tokens = new FixedSessionAdmission;
        $tokens->set(FixedSessionAdmission::token($installationRef));

        $app->instance(CallerContext::class, $callers);
        $app->instance(FinancialSessionAdmission::class, $tokens);
        SimulatedFinancialAuthorityEvidence::install($app);
        SimulatedFinancialAuthorityEvidence::admitCurrent($app);

        /** @var array<string, mixed> $artifact */
        $artifact = is_array($args['artifact'] ?? null) ? $args['artifact'] : [];
        $artifact['batchId'] = 'batch-conc-worker-'.$index;
        $artifact['importPolicyVersion'] = 'policy-worker-'.$index;
        unset($artifact['artifactDigest']);

        $this->reader = GenericExportReader::fromJson(
            json_encode($artifact, JSON_THROW_ON_ERROR),
            new GenericExportProfile,
            'policy-worker-'.$index,
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
