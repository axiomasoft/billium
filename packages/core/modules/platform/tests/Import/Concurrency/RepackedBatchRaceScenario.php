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
use Billium\Platform\Contracts\Import\ImportReconciler;
use Billium\Platform\Data\Import\ImportApplyOptions;
use Billium\Platform\Internal\Import\Sources\GenericExportProfile;
use Billium\Platform\Internal\Import\Sources\GenericExportReader;
use Billium\Platform\Internal\Import\Sources\RecurringExportProfile;
use Illuminate\Foundation\Application;

/**
 * IM-02 + IM-05 + IM-11:
 * N concurrent OS processes cross the P3 PostgreSQL advisory barrier simultaneously,
 * applying the exact same source economic identities packaged into different batchIds
 * and different mapper profile versions / importPolicyVersions.
 */
final readonly class RepackedBatchRaceScenario implements Scenario
{
    private GenericExportReader $reader;

    private string $profileVersion;

    private string $policyVersion;

    /**
     * @param  array<string, mixed>  $args
     */
    public function __construct(Application $app, int $index, array $args)
    {
        $installationRef = is_string($args['installationRef'] ?? null) ? $args['installationRef'] : 'inst-test';
        $sellerRef = is_string($args['sellerRef'] ?? null) ? $args['sellerRef'] : 'seller-recurring-1';

        $caller = new Caller(
            sellerRef: $sellerRef,
            environment: 'test',
            installationRef: $installationRef,
            actorRef: 'repacked-race-worker-'.$index,
            sourceAuthorities: [ImportApplyOptions::DEFAULT_MIGRATION_SOURCE_AUTHORITY],
        );
        $callers = new FixedCallerContext($caller);
        $tokens = new FixedSessionAdmission;
        $tokens->set(FixedSessionAdmission::token($installationRef));

        $app->instance(CallerContext::class, $callers);
        $app->instance(FinancialSessionAdmission::class, $tokens);
        SimulatedFinancialAuthorityEvidence::install($app);
        SimulatedFinancialAuthorityEvidence::admitCurrent($app);

        $this->profileVersion = (1 + $index).'.0.0';
        $this->policyVersion = 'import-policy-v'.(1 + $index);

        /** @var array<string, mixed> $artifact */
        $artifact = is_array($args['artifact'] ?? null) ? $args['artifact'] : [];
        $artifact['batchId'] = 'batch-repacked-race-worker-'.$index;
        $artifact['sourceExportRevision'] = 10 + $index;
        $artifact['importPolicyVersion'] = $this->policyVersion;
        unset($artifact['artifactDigest']);

        $profileName = is_string($artifact['profile'] ?? null) ? $artifact['profile'] : 'generic';
        $profile = $profileName === 'reference-recurring'
            ? new RecurringExportProfile(version: $this->profileVersion)
            : new GenericExportProfile(version: $this->profileVersion);

        $this->reader = GenericExportReader::fromJson(
            json_encode($artifact, JSON_THROW_ON_ERROR),
            $profile,
            $this->policyVersion,
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

        $reconciler = $app->make(ImportReconciler::class);
        $reconcile = $reconciler->reconcileBatch($manifest->batchId);
        $factRefs = [];
        foreach ($reconcile->accountingFacts as $fact) {
            $factRefs[$fact['sourceRecordId']] = $fact['sourceFactRef'];
        }
        ksort($factRefs, SORT_STRING);

        return [
            'batchId' => $result->batchId,
            'batchStatus' => $result->batchStatus->value,
            'profileVersion' => $this->profileVersion,
            'policyVersion' => $this->policyVersion,
            'counts' => $result->counts,
            'reconciled' => $reconcile->reconciled,
            'factRefs' => $factRefs,
        ];
    }
}
