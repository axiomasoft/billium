<?php

declare(strict_types=1);

namespace Billium\Rating\Internal\Storage;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\CallerContext;
use Billium\Core\Contracts\OperationRunner;
use Billium\Core\Contracts\TransactionContext;
use Billium\Core\Data\LockRequest;
use Billium\Core\Data\OperationSpec;
use Billium\Core\Enums\FinancialMode;
use Billium\Core\Enums\LockRank;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Rating\Contracts\PriceVersionStore;
use Billium\Rating\Contracts\PricingStrategyRegistry;
use Billium\Rating\Contracts\TaxSnapshotStore;
use Billium\Rating\Data\Pricing\CommitmentTerms;
use Billium\Rating\Data\Pricing\FixedTerms;
use Billium\Rating\Data\Pricing\GraduatedTerms;
use Billium\Rating\Data\Pricing\PerUnitTerms;
use Billium\Rating\Data\Pricing\PriceVersion;
use Billium\Rating\Data\Pricing\QuantityRules;
use Billium\Rating\Data\Pricing\Tier;
use Billium\Rating\Data\Pricing\VolumeTerms;
use Billium\Rating\Data\Tax\TaxCorrectionPolicySnapshot;
use Billium\Rating\Data\Tax\TaxSnapshot;
use Billium\Rating\Enums\IncrementMode;
use Billium\Rating\Enums\RoundingMode;
use Billium\Rating\Enums\RoundingStage;
use Billium\Rating\Enums\StrategyId;
use Billium\Rating\Enums\TaxSnapshotKind;
use Billium\Rating\Internal\Canonical\CanonicalJson;
use Billium\Rating\Internal\Tax\TaxCalculator;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

final readonly class DatabasePriceVersionStore implements PriceVersionStore, TaxSnapshotStore
{
    public function __construct(private DatabaseManager $databases, private OperationRunner $runner, private CallerContext $callers, private PricingStrategyRegistry $registry) {}

    public function freeze(TaxSnapshot $snapshot, string $commandKey): void
    {
        if ($snapshot->kind !== TaxSnapshotKind::Standard) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.rating.terms_invalid: standard tax snapshot required');
        }
        $this->freezeTax(snapshot: $snapshot, policy: null, commandKey: $commandKey);
    }

    public function freezeCorrection(TaxCorrectionPolicySnapshot $policy, string $commandKey): void
    {
        if ($policy->selectedRate->kind !== TaxSnapshotKind::Correction || $policy->inputDigest === '' || ! in_array($policy->treatment, ['original_rate', 'current_legal_rate', 'other_supported'], true) || $policy->policyRef === '' || $policy->policyVersion === '' || $policy->resolverVersion === '' || $policy->outputDigest === '' || ($policy->effectiveTo !== null && $policy->effectiveTo < $policy->effectiveFrom)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.rating.tax_policy_unsupported');
        }
        $this->freezeTax(snapshot: $policy->selectedRate, policy: $policy, commandKey: $commandKey);
    }

    private function freezeTax(TaxSnapshot $snapshot, ?TaxCorrectionPolicySnapshot $policy, string $commandKey): void
    {
        (new TaxCalculator)->calculateSnapshot(amounts: [], snapshot: $snapshot, exp: 2, rounding: 'half_up');
        $caller = $this->callers->current();
        $digest = CanonicalJson::digest(['snapshot' => $snapshot, 'policy' => $policy]);
        $outcome = $this->runner->run(
            spec: new OperationSpec(commandKind: 'rating.tax_snapshot.freeze.v1', sellerRef: $caller->sellerRef, environment: $caller->environment,
                mode: FinancialMode::Administrative, operationClass: ModuleOperationClass::NewIntent, modules: ['platform', 'rating'], commandKey: $commandKey, digest: $digest),
            body: function (TransactionContext $context) use ($snapshot, $policy, $caller, $digest): PricePublishedReceipt {
                $this->runner->assertCurrent(context: $context, moduleId: 'rating');
                $db = $this->databases->connection($context->connectionName());
                $scope = ['seller_ref' => $caller->sellerRef, 'environment' => $caller->environment, 'snapshot_ref' => $snapshot->snapshotRef];
                $db->table('billium_rating_tax_snapshot')->insertOrIgnore([...$scope, 'kind' => $snapshot->kind->value, 'mode' => $snapshot->mode->value,
                    'jurisdiction' => $snapshot->jurisdiction, 'tax_code' => $snapshot->taxCode, 'rate' => $snapshot->rate, 'rate_text' => $snapshot->rate,
                    'effective_date' => $snapshot->effectiveDate->format('Y-m-d'), 'tax_point' => $snapshot->taxPoint->format('Y-m-d'),
                    'no_tax_reason' => $snapshot->noTaxReason, 'resolver' => $snapshot->resolver, 'resolver_version' => $snapshot->resolverVersion,
                    'input_digest' => $snapshot->inputDigest, 'evidence_digest' => $snapshot->evidenceDigest, 'snapshot_digest' => $digest,
                    'policy_ref' => $policy?->policyRef, 'policy_version' => $policy?->policyVersion, 'policy_treatment' => $policy?->treatment,
                    'policy_effective_from' => $policy?->effectiveFrom->format('Y-m-d'), 'policy_effective_to' => $policy?->effectiveTo?->format('Y-m-d'),
                    'policy_input_digest' => $policy?->inputDigest, 'policy_output_digest' => $policy?->outputDigest, 'policy_resolver_version' => $policy?->resolverVersion]);
                $existing = $db->table('billium_rating_tax_snapshot')->where($scope)->first();
                if ($existing === null || $existing->snapshot_digest !== $digest) {
                    throw new ProblemException(ProblemCode::PolicyUnknown, 'billium.rating.tax_snapshot_conflict');
                }

                return new PricePublishedReceipt(payload: ['snapshotRef' => $snapshot->snapshotRef, 'snapshotDigest' => $digest], class: 'rating.tax_snapshot_frozen');
            },
        );
        $outcome->orThrow();
    }

    public function publish(PriceVersion $price, string $commandKey): array
    {
        $caller = $this->callers->current();
        if ($caller->sellerRef !== $price->sellerRef) {
            throw new ProblemException(ProblemCode::AuthDenied, 'billium.rating.seller_scope_mismatch');
        }
        $this->registry->validate($price);
        $publicationKey = CanonicalJson::digest([$price->priceRef, $price->version]);
        $priceDigest = CanonicalJson::digest($price);
        $outcome = $this->runner->run(
            spec: new OperationSpec(commandKind: 'rating.price_version.publish.v1', sellerRef: $caller->sellerRef, environment: $caller->environment,
                mode: FinancialMode::Administrative, operationClass: ModuleOperationClass::NewIntent, modules: ['platform', 'rating'],
                commandKey: $commandKey, digest: $priceDigest),
            body: function (TransactionContext $context) use ($price, $caller, $publicationKey, $priceDigest): PricePublishedReceipt {
                $this->runner->assertCurrent(context: $context, moduleId: 'rating');
                $db = $this->databases->connection($context->connectionName());
                $scope = ['seller_ref' => $caller->sellerRef, 'environment' => $caller->environment, 'publication_key' => $publicationKey];
                $db->table('billium_rating_price_publication')->insertOrIgnore([...$scope, 'price_ref' => $price->priceRef,
                    'version' => $price->version, 'terms_digest' => $price->termsDigest, 'event_type' => 'rating.price_version.published.v1',
                    'payload' => CanonicalJson::encode(['priceRef' => $price->priceRef, 'version' => $price->version, 'priceDigest' => $priceDigest])]);
                $context->lock(new LockRequest(rank: LockRank::RatingPriceVersion, table: 'billium_rating_price_publication', keys: [$publicationKey], keyColumn: 'publication_key'));
                $head = $db->table('billium_rating_price_publication')->where($scope)->first();
                $existing = $db->table('billium_rating_price_version')->where($scope)->first();
                if ($head === null) {
                    throw new ProblemException(ProblemCode::RecoveryRequired, 'billium.rating.publication_missing');
                }
                $payload = RatingRows::map(json_decode(RatingRows::text($head->payload), true, 32, JSON_THROW_ON_ERROR));
                if (($payload['priceDigest'] ?? null) !== $priceDigest || $head->terms_digest !== $price->termsDigest) {
                    throw new ProblemException(ProblemCode::PolicyUnknown, 'billium.rating.price_version_conflict');
                }
                if ($existing === null) {
                    $this->insert(db: $db, price: $price, environment: $caller->environment, publicationKey: $publicationKey);
                }

                return new PricePublishedReceipt(['priceRef' => $price->priceRef, 'version' => $price->version, 'termsDigest' => $price->termsDigest]);
            },
        );

        return $outcome->orThrow()->body;
    }

    public function find(string $priceRef, int $version): ?PriceVersion
    {
        $caller = $this->callers->current();
        $name = config('billium.connection');
        $db = $this->databases->connection(is_string($name) ? $name : null);
        $row = $db->table('billium_rating_price_version')->where(['seller_ref' => $caller->sellerRef, 'environment' => $caller->environment, 'price_ref' => $priceRef, 'version' => $version])->first();
        if ($row === null) {
            return null;
        }
        $data = RatingRows::map(json_decode(RatingRows::text($row->terms), true, 64, JSON_THROW_ON_ERROR));
        $termData = RatingRows::map($data['terms'] ?? null);
        $strategy = StrategyId::from(RatingRows::text($row->strategy_id));
        $terms = match ($strategy) {
            StrategyId::Fixed => new FixedTerms(amount: RatingRows::text($termData['amount'] ?? null), scope: RatingRows::text($termData['scope'] ?? null)),
            StrategyId::PerUnit => new PerUnitTerms(unitPrice: RatingRows::text($termData['unitPrice'] ?? null)),
            StrategyId::TieredGraduated => new GraduatedTerms(tiers: $this->tiers(RatingRows::items($termData['tiers'] ?? null))),
            StrategyId::TieredVolume => new VolumeTerms(tiers: $this->tiers(RatingRows::items($termData['tiers'] ?? null))),
        };
        $r = RatingRows::map($data['quantityRules'] ?? null);
        $c = ($data['commitment'] ?? null) === null ? null : RatingRows::map($data['commitment']);
        $commitment = $c === null ? null : new CommitmentTerms(sellerRef: RatingRows::text($c['sellerRef'] ?? null), billingAccountRef: RatingRows::text($c['billingAccountRef'] ?? null), componentRef: RatingRows::text($c['componentRef'] ?? null), currency: RatingRows::text($c['currency'] ?? null), periodRef: RatingRows::text($c['periodRef'] ?? null), minimumMinor: RatingRows::nullableInteger($c['minimumMinor'] ?? null), maximumMinor: RatingRows::nullableInteger($c['maximumMinor'] ?? null), consumedByAlreadyBilled: RatingRows::boolean($c['consumedByAlreadyBilled'] ?? null), appliesAt: RatingRows::text($c['appliesAt'] ?? null));
        $price = new PriceVersion(sellerRef: RatingRows::text($row->seller_ref), priceRef: RatingRows::text($row->price_ref), version: RatingRows::integer($row->version),
            strategyId: $strategy, strategyVersion: RatingRows::integer($row->strategy_version), currency: RatingRows::text($row->currency), currencyExponent: RatingRows::integer($row->currency_exponent),
            exponentVersion: RatingRows::text($row->exponent_version), unit: RatingRows::text($row->unit),
            quantityRules: new QuantityRules(quantityScale: RatingRows::integer($r['quantityScale'] ?? null), conversionNumerator: RatingRows::text($r['conversionNumerator'] ?? null), conversionDenominator: RatingRows::text($r['conversionDenominator'] ?? null), billingIncrement: RatingRows::text($r['billingIncrement'] ?? null), incrementMode: IncrementMode::from(RatingRows::text($r['incrementMode'] ?? null))),
            terms: $terms, roundingMode: RoundingMode::from(RatingRows::text($row->rounding_mode)), roundingStage: RoundingStage::from(RatingRows::text($row->rounding_stage)),
            roundingPolicyVersion: RatingRows::text($row->rounding_policy_version), commitment: $commitment, termsDigest: RatingRows::text($row->terms_digest));
        $this->registry->validate($price);

        return $price;
    }

    private function insert(Connection $db, PriceVersion $price, string $environment, string $publicationKey): void
    {
        $rules = $price->quantityRules;
        $id = $db->table('billium_rating_price_version')->insertGetId([
            'seller_ref' => $price->sellerRef, 'environment' => $environment, 'publication_key' => $publicationKey, 'price_ref' => $price->priceRef,
            'version' => $price->version, 'strategy_id' => $price->strategyId->value, 'strategy_version' => $price->strategyVersion,
            'currency' => $price->currency, 'currency_exponent' => $price->currencyExponent, 'exponent_version' => $price->exponentVersion,
            'unit' => $price->unit, 'quantity_scale' => $rules->quantityScale, 'conversion_numerator' => $rules->conversionNumerator,
            'conversion_denominator' => $rules->conversionDenominator, 'billing_increment' => $rules->billingIncrement, 'increment_mode' => $rules->incrementMode->value,
            'fixed_amount' => $price->terms instanceof FixedTerms ? $price->terms->amount : null,
            'per_unit_price' => $price->terms instanceof PerUnitTerms ? $price->terms->unitPrice : null,
            'rounding_mode' => $price->roundingMode->value, 'rounding_stage' => $price->roundingStage->value, 'rounding_policy_version' => $price->roundingPolicyVersion,
            'commitment_declared' => $price->commitment !== null, 'terms' => CanonicalJson::encode(['terms' => $price->terms, 'quantityRules' => $rules, 'commitment' => $price->commitment]), 'terms_digest' => $price->termsDigest,
        ]);
        if ($price->terms instanceof GraduatedTerms || $price->terms instanceof VolumeTerms) {
            foreach ($price->terms->tiers as $tier) {
                $db->table('billium_rating_price_tier')->insert(['price_version_id' => $id, 'tier_index' => $tier->index, 'lower_bound' => $tier->lower, 'upper_bound' => $tier->upper, 'unit_price' => $tier->unitPrice, 'flat_fee' => $tier->flatFee]);
            }
        }
        $c = $price->commitment;
        if ($c !== null) {
            $db->table('billium_rating_commitment_term')->insert(['price_version_id' => $id, 'seller_ref' => $c->sellerRef, 'billing_account_ref' => $c->billingAccountRef, 'component_ref' => $c->componentRef, 'currency' => $c->currency, 'period_ref' => $c->periodRef, 'minimum_minor' => $c->minimumMinor, 'maximum_minor' => $c->maximumMinor, 'consumed_by_already_billed' => $c->consumedByAlreadyBilled, 'applies_at' => $c->appliesAt]);
        }
    }

    /**
     * @param  list<mixed>  $rows
     * @return list<Tier>
     */
    private function tiers(array $rows): array
    {
        return array_map(static function (mixed $item): Tier {
            $tier = RatingRows::map($item);
            $upper = $tier['upper'] ?? null;

            return new Tier(index: RatingRows::integer($tier['index'] ?? null), lower: RatingRows::text($tier['lower'] ?? null), upper: $upper === null ? null : RatingRows::text($upper), unitPrice: RatingRows::text($tier['unitPrice'] ?? null), flatFee: RatingRows::text($tier['flatFee'] ?? null));
        }, $rows);
    }
}
