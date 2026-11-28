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
use Illuminate\Foundation\Application;

/**
 * Cross-source same refs across sellers:
 * Concurrent workers for seller-alpha (workers 0, 1) and seller-beta (workers 2, 3)
 * simultaneously import batches containing identical sourceInstallation, sourceBook,
 * sourceRecordId, obligationRef, paymentRef, originalOperationRef and historical sourceNumber.
 */
final readonly class ConcurrentCrossSellerSameRefsScenario implements Scenario
{
    private string $sellerRef;

    private GenericExportReader $reader;

    /**
     * @param  array<string, mixed>  $args
     */
    public function __construct(Application $app, int $index, array $args)
    {
        $installationRef = is_string($args['installationRef'] ?? null) ? $args['installationRef'] : 'inst-test';
        $this->sellerRef = ($index % 2 === 0) ? 'seller-alpha' : 'seller-beta';

        $caller = new Caller(
            sellerRef: $this->sellerRef,
            environment: 'test',
            installationRef: $installationRef,
            actorRef: 'cross-seller-worker-'.$index,
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
        $artifact = is_array($args['artifactBySeller'][$this->sellerRef] ?? null)
            ? $args['artifactBySeller'][$this->sellerRef]
            : [];
        $artifact['batchId'] = 'batch-cross-'.$this->sellerRef.'-w'.$index;
        $artifact['importPolicyVersion'] = 'policy-w'.$index;
        unset($artifact['artifactDigest']);

        $this->reader = GenericExportReader::fromJson(
            json_encode($artifact, JSON_THROW_ON_ERROR),
            new GenericExportProfile(version: (1 + $index).'.0.0'),
            'policy-w'.$index,
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

        $reconcile = $app->make(ImportReconciler::class)->reconcileBatch($manifest->batchId);
        $factRefs = [];
        foreach ($reconcile->accountingFacts as $fact) {
            $factRefs[$fact['sourceRecordId']] = $fact['sourceFactRef'];
        }
        ksort($factRefs, SORT_STRING);

        return [
            'sellerRef' => $this->sellerRef,
            'batchId' => $result->batchId,
            'batchStatus' => $result->batchStatus->value,
            'counts' => $result->counts,
            'reconciled' => $reconcile->reconciled,
            'factRefs' => $factRefs,
        ];
    }
}
