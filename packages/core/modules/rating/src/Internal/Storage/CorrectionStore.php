<?php

declare(strict_types=1);

namespace Billium\Rating\Internal\Storage;

use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Rating\Data\Correction\PreparedCorrection;
use Billium\Rating\Data\Correction\RatingCorrectionReceipt;
use Billium\Rating\Data\Rating\PreparedRating;
use Billium\Rating\Internal\Pipeline\RatingComputation;
use LogicException;
use Packman\Attributes\Internal;
use stdClass;

#[Internal]
final readonly class CorrectionStore
{
    public function __construct(private RatingStore $store) {}

    public function byFrontier(TransactionContext $context, string $parent, string $digest): ?stdClass
    {
        return $this->store->query(table: 'correction', context: $context)
            ->where('parent_occurrence_ref', $parent)->where('source_frontier_digest', $digest)->first();
    }

    /** @return list<string> */
    public function childObligations(string $parent, ?TransactionContext $context = null): array
    {
        $caller = $context?->caller();
        $query = $this->store->database($context)->table('billium_rating_correction AS c')
            ->join('billium_rating_correction_booking AS b', 'b.correction_id', '=', 'c.id')
            ->where('c.parent_occurrence_ref', $parent)->where('c.outcome', 'obligation')
            ->whereNotNull('b.obligation_ref')->orderBy('c.correction_revision');
        if ($caller !== null) {
            $query->where('c.seller_ref', $caller->sellerRef)->where('c.environment', $caller->environment);
        } else {
            // The scoped query also enforces the authenticated caller on read-only preparation.
            $query->whereIn('c.id', $this->store->query('correction')->select('id'));
        }

        return array_values(array_map(RatingRows::text(...), $query->pluck('b.obligation_ref')->all()));
    }

    public function receipt(TransactionContext $context, stdClass $correction, string $currency): RatingCorrectionReceipt
    {
        $booking = $this->store->database($context)->table('billium_rating_correction_booking')->where('correction_id', $correction->id)->first();
        if ($booking === null) {
            throw new LogicException('Correction booking is missing');
        }

        return new RatingCorrectionReceipt(correctionRef: RatingRows::text($correction->correction_ref),
            correctionRevision: RatingRows::integer($correction->correction_revision), outcome: 'existing',
            deltaGrossMinor: RatingRows::decimal($correction->delta_gross_minor),
            obligationRef: RatingRows::nullableText($booking->obligation_ref), creditNoteRef: RatingRows::nullableText($booking->credit_note_ref),
            currency: $currency, legalRequirementPending: $correction->outcome !== 'none');
    }

    /** @return array{basisId: int, correctionId: int} */
    public function insert(TransactionContext $context, PreparedCorrection $prepared, string $childRef, string $branch): array
    {
        $c = $prepared->computation;
        $basisId = $this->store->insertBasis(context: $context, prepared: new PreparedRating(inputs: $prepared->inputs, draft: $c->correctedBasis),
            correctionRevision: $c->correctionRevision, predecessorBasisRef: $c->predecessorBasisRef);
        $id = $this->store->database($context)->table('billium_rating_correction')->insertGetId([
            'seller_ref' => $prepared->inputs->sellerRef, 'environment' => $prepared->inputs->environment,
            'correction_ref' => $childRef, 'correction_kind' => $prepared->command->kind->value,
            'parent_occurrence_ref' => $prepared->inputs->occurrenceRef, 'correction_revision' => $c->correctionRevision,
            'predecessor_basis_ref' => $c->predecessorBasisRef, 'basis_id' => $basisId, 'source_frontier_digest' => $c->sourceFrontierDigest,
            'tax_policy_ref' => $prepared->taxPolicy->policyRef, 'tax_policy_version' => $prepared->taxPolicy->policyVersion,
            'tax_snapshot_ref' => $prepared->taxPolicy->selectedRate->snapshotRef, 'reason_code' => $prepared->command->reasonCode,
            'delta_net_minor' => $c->deltaNetMinor, 'delta_tax_minor' => $c->deltaTaxMinor, 'delta_gross_minor' => $c->deltaGrossMinor,
            'outcome' => $branch]);

        return ['basisId' => $basisId, 'correctionId' => $id];
    }

    public function book(TransactionContext $context, int $id, ?string $obligation, ?string $creditNote, ?string $release): void
    {
        $this->store->database($context)->table('billium_rating_correction_booking')->insert([
            'correction_id' => $id, 'obligation_ref' => $obligation, 'credit_note_ref' => $creditNote, 'release_policy' => $release]);
    }

    public function advance(TransactionContext $context, PreparedCorrection $prepared): void
    {
        $c = $prepared->computation;
        $changed = $this->store->query(table: 'source_frontier', context: $context)
            ->where('frontier_key', 'occurrence:'.$prepared->inputs->occurrenceRef)
            ->where('row_version', $prepared->expectedFrontierRowVersion)->where('head_digest', $prepared->expectedFrontierDigest)
            ->where('correction_head_revision', $c->correctionRevision - 1)
            ->update(['row_version' => $prepared->expectedFrontierRowVersion + 1, 'head_basis_ref' => $c->correctedBasis->basisRef,
                'head_digest' => $c->correctedBasis->ratedDigest, 'correction_head_revision' => $c->correctionRevision]);
        if ($changed !== 1) {
            RatingComputation::refuse('correction_frontier_stale');
        }
        $scopes = [];
        foreach ($prepared->inputs->prices as $price) {
            if ($price->commitmentScopeDigest !== null) {
                $scopes[$price->commitmentScopeDigest] = true;
            }
        }
        foreach (array_keys($scopes) as $scope) {
            $query = $this->store->query(table: 'source_frontier', context: $context)->where('frontier_key', 'commitment:'.$scope);
            $version = RatingRows::integer((clone $query)->value('row_version'));
            $current = $this->store->commitment(scope: $scope, context: $context);
            if ($query->where('row_version', $version)->update(['row_version' => $version + 1,
                'head_basis_ref' => $c->correctedBasis->basisRef, 'head_digest' => $current['basisDigest']]) !== 1) {
                RatingComputation::refuse('correction_frontier_stale');
            }
        }
    }
}
