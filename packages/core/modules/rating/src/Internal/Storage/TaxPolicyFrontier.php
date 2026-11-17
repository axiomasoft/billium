<?php

declare(strict_types=1);

namespace Billium\Rating\Internal\Storage;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Data\Runner\LockRequest;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runner\LockRank;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Rating\Contracts\TaxCorrectionPolicyFrontier;
use Billium\Rating\Data\Tax\TaxCorrectionPolicySnapshot;
use Billium\Rating\Internal\Canonical\CanonicalJson;
use Packman\Attributes\Internal;

#[Internal]
final readonly class TaxPolicyFrontier implements TaxCorrectionPolicyFrontier
{
    public function __construct(private RatingStore $store, private CallerContext $callers, private OperationRunner $runner) {}

    public static function key(string $policyRef): string
    {
        return 'tax-policy:'.hash('sha256', $policyRef);
    }

    public static function digest(TaxCorrectionPolicySnapshot $policy): string
    {
        return CanonicalJson::digest([$policy->policyRef, $policy->policyVersion, $policy->effectiveFrom, $policy->effectiveTo]);
    }

    /** @return array{version: int, digest: string} */
    public function head(TaxCorrectionPolicySnapshot $policy, ?TransactionContext $context = null): array
    {
        $row = $this->store->query(table: 'source_frontier', context: $context)->where('frontier_key', self::key($policy->policyRef))->first();

        return ['version' => $row === null ? 0 : RatingRows::integer($row->row_version),
            'digest' => $row === null ? '' : RatingRows::text($row->head_digest)];
    }

    public function publish(TaxCorrectionPolicySnapshot $policy, string $commandKey): void
    {
        if ($policy->policyRef === '' || $policy->policyVersion === '' || ($policy->effectiveTo !== null && $policy->effectiveTo < $policy->effectiveFrom)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.rating.tax_policy_unsupported');
        }
        $caller = $this->callers->current();
        $digest = self::digest($policy);
        $this->runner->run(spec: new OperationSpec(commandKind: 'rating.tax_policy.publish.v1', sellerRef: $caller->sellerRef,
            environment: $caller->environment, mode: FinancialMode::Administrative, operationClass: ModuleOperationClass::NewIntent,
            modules: ['platform', 'rating'], commandKey: $commandKey, digest: $digest),
            body: function (TransactionContext $context) use ($caller, $policy, $digest): PricePublishedReceipt {
                $this->runner->assertCurrent(context: $context, moduleId: 'rating');
                $key = self::key($policy->policyRef);
                $this->store->database($context)->table('billium_rating_source_frontier')->insertOrIgnore([
                    'seller_ref' => $caller->sellerRef, 'environment' => $caller->environment, 'frontier_key' => $key]);
                $context->lock(new LockRequest(rank: LockRank::RatingFrontier, table: 'billium_rating_source_frontier', keys: [$key], keyColumn: 'frontier_key'));
                $head = $this->head(policy: $policy, context: $context);
                if ($head['digest'] !== $digest) {
                    $this->store->query(table: 'source_frontier', context: $context)->where('frontier_key', $key)
                        ->where('row_version', $head['version'])->update(['row_version' => $head['version'] + 1,
                            'head_basis_ref' => $policy->policyVersion, 'head_digest' => $digest]);
                }

                return new PricePublishedReceipt(payload: ['policyRef' => $policy->policyRef, 'version' => $policy->policyVersion]);
            })->orThrow();
    }
}
