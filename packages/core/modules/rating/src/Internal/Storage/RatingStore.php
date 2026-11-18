<?php

declare(strict_types=1);

namespace Billium\Rating\Internal\Storage;

use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Data\Runner\LockRequest;
use Billium\Core\Enums\Runner\LockRank;
use Billium\Rating\Data\Rating\BookingReceipt;
use Billium\Rating\Data\Rating\FrozenRatingInputs;
use Billium\Rating\Data\Rating\PreparedRating;
use Billium\Rating\Data\Rating\RatedBasisDraft;
use Billium\Rating\Data\Rating\RatedLineDraft;
use Billium\Rating\Enums\ChargeKind;
use Billium\Rating\Internal\Canonical\CanonicalJson;
use Billium\Rating\Internal\Pipeline\RatingComputation;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Packman\Attributes\Internal;
use stdClass;

#[Internal]
final readonly class RatingStore
{
    public function __construct(private DatabaseManager $databases, private CallerContext $callers, private OperationRunner $runner, private BilliumOptions $options) {}

    public function database(?TransactionContext $context = null): Connection
    {
        if ($context !== null) {
            $this->runner->assertCurrent(context: $context, moduleId: 'rating');
        }
        $name = $context?->connectionName() ?? $this->options->connection;

        return $this->databases->connection(is_string($name) ? $name : null);
    }

    public function query(string $table, ?TransactionContext $context = null): Builder
    {
        $caller = $context?->caller() ?? $this->callers->current();

        return $this->database($context)->table('billium_rating_'.$table)->where('seller_ref', $caller->sellerRef)->where('environment', $caller->environment);
    }

    public function basis(string $ref, ?TransactionContext $context = null): ?stdClass
    {
        return $this->query(table: 'rated_basis', context: $context)->where('basis_ref', $ref)->first();
    }

    public function booking(int $id, ?TransactionContext $context = null): ?stdClass
    {
        return $this->query(table: 'booking', context: $context)->where('basis_id', $id)->first();
    }

    public function inputs(stdClass $basis): FrozenRatingInputs
    {
        $run = $this->query('run')->where('id', $basis->run_id)->first();

        return FrozenInputsCodec::decode(RatingRows::text($run?->frozen_inputs));
    }

    public function draft(stdClass $basis): RatedBasisDraft
    {
        $lines = $this->database()->table('billium_rating_rated_line')->where('basis_id', $basis->id)->orderBy('line_no')->get();

        return new RatedBasisDraft(basisRef: RatingRows::text($basis->basis_ref), inputsDigest: RatingRows::text($basis->inputs_digest),
            ratedDigest: RatingRows::text($basis->rated_digest), chargeKind: ChargeKind::from(RatingRows::text($basis->charge_kind)),
            noChargeReason: $basis->no_charge_reason === null ? null : RatingRows::text($basis->no_charge_reason),
            lines: array_values($lines->map(static fn (stdClass $line): RatedLineDraft => FrozenInputsCodec::line(RatingRows::map(json_decode(RatingRows::text($line->line_snapshot), true, 128, JSON_THROW_ON_ERROR))))->all()),
            netMinor: RatingRows::decimal($basis->net_minor), taxMinor: RatingRows::decimal($basis->tax_minor), grossMinor: RatingRows::decimal($basis->gross_minor));
    }

    /** @return array{rowVersion: int, headDigest: string, headBasisRef: ?string, correctionRevision: int} */
    public function frontier(string $occurrenceRef, ?TransactionContext $context = null): array
    {
        $row = $this->query(table: 'source_frontier', context: $context)->where('frontier_key', 'occurrence:'.$occurrenceRef)->first();

        return ['rowVersion' => $row === null ? 0 : RatingRows::integer($row->row_version),
            'headDigest' => $row === null ? '' : RatingRows::text($row->head_digest),
            'headBasisRef' => $row === null || $row->head_basis_ref === null ? null : RatingRows::text($row->head_basis_ref),
            'correctionRevision' => $row === null ? 0 : RatingRows::integer($row->correction_head_revision)];
    }

    /** @return array{alreadyBilledNetMinor: string, basisDigest: string} */
    public function commitment(string $scope, ?TransactionContext $context = null): array
    {
        $caller = $context?->caller() ?? $this->callers->current();
        $rows = $this->database($context)->table('billium_rating_rated_line AS l')
            ->join('billium_rating_rated_basis AS b', 'b.id', '=', 'l.basis_id')
            ->join('billium_rating_source_frontier AS h', static function (JoinClause $join): void {
                $join->on('h.seller_ref', '=', 'b.seller_ref')->on('h.environment', '=', 'b.environment')
                    ->on('h.occurrence_ref', '=', 'b.occurrence_ref')->on('h.head_basis_ref', '=', 'b.basis_ref');
            })
            ->join('billium_rating_rated_basis AS original', static function (JoinClause $join): void {
                $join->on('original.seller_ref', '=', 'b.seller_ref')->on('original.environment', '=', 'b.environment')
                    ->on('original.occurrence_ref', '=', 'b.occurrence_ref')->where('original.kind', '=', 'original');
            })
            ->join('billium_rating_booking AS k', 'k.basis_id', '=', 'original.id')
            ->where('b.seller_ref', $caller->sellerRef)->where('b.environment', $caller->environment)
            ->where('l.commitment_scope_digest', $scope)->where('k.outcome', 'booked')
            ->orderBy('b.basis_ref')->orderBy('l.line_no')->get(['b.basis_ref', 'l.line_no', 'l.net_minor']);
        $sum = '0';
        $evidence = [];
        foreach ($rows as $row) {
            $sum = bcadd($sum, RatingRows::decimal($row->net_minor), 0);
            $evidence[] = [$row->basis_ref, $row->line_no, RatingRows::decimal($row->net_minor)];
        }

        return ['alreadyBilledNetMinor' => $sum, 'basisDigest' => CanonicalJson::digest($evidence)];
    }

    /** @param list<string> $additionalKeys */
    public function lockFrontiers(TransactionContext $context, FrozenRatingInputs $inputs, array $additionalKeys = []): void
    {
        $keys = ['occurrence:'.$inputs->occurrenceRef => $inputs->occurrenceRef];
        foreach ($additionalKeys as $key) {
            $keys[$key] = null;
        }
        foreach ($inputs->prices as $price) {
            if ($price->commitmentScopeDigest !== null) {
                $keys['commitment:'.$price->commitmentScopeDigest] = null;
            }
        }
        ksort($keys, SORT_STRING);
        $caller = $context->caller();
        foreach ($keys as $key => $occurrence) {
            $this->database($context)->table('billium_rating_source_frontier')->insertOrIgnore([
                'seller_ref' => $caller->sellerRef, 'environment' => $caller->environment,
                'frontier_key' => $key, 'occurrence_ref' => $occurrence]);
        }
        $context->lock(new LockRequest(rank: LockRank::RatingFrontier, table: 'billium_rating_source_frontier',
            keys: array_keys($keys), keyColumn: 'frontier_key'));
    }

    public function assertFrontier(TransactionContext $context, FrozenRatingInputs $inputs): void
    {
        $head = $this->frontier(occurrenceRef: $inputs->occurrenceRef, context: $context);
        if ($head['rowVersion'] !== $inputs->frontierExpectedRowVersion || $head['headDigest'] !== $inputs->frontierExpectedDigest) {
            RatingComputation::refuse('frozen_input_stale');
        }
    }

    public function assertCommitments(TransactionContext $context, FrozenRatingInputs $inputs): void
    {
        foreach ($inputs->prices as $price) {
            if ($price->commitmentScopeDigest === null) {
                continue;
            }
            $current = $this->commitment(scope: $price->commitmentScopeDigest, context: $context);
            if ($current['alreadyBilledNetMinor'] !== $price->alreadyBilledNetMinor || $current['basisDigest'] !== $price->commitmentBasisDigest) {
                RatingComputation::refuse('frozen_input_stale');
            }
        }
    }

    public function insertBasis(TransactionContext $context, PreparedRating $prepared, int $correctionRevision = 0, ?string $predecessorBasisRef = null): int
    {
        $in = $prepared->inputs;
        $draft = $prepared->draft;
        $db = $this->database($context);
        $scope = ['seller_ref' => $in->sellerRef, 'environment' => $in->environment];
        $run = $db->table('billium_rating_run')->insertGetId([...$scope, 'run_ref' => 'run:'.$draft->basisRef, 'occurrence_ref' => $in->occurrenceRef,
            'billing_account_ref' => $in->billingAccountRef, 'inputs_digest' => $draft->inputsDigest, 'agreement_revision' => $in->agreementRevision,
            'period_start_micros' => $in->periodStartMicros, 'period_end_micros' => $in->periodEndMicros, 'frozen_inputs' => CanonicalJson::encode($in)]);
        $id = $db->table('billium_rating_rated_basis')->insertGetId([...$scope, 'basis_ref' => $draft->basisRef,
            'run_id' => $run, 'occurrence_ref' => $in->occurrenceRef, 'kind' => $correctionRevision === 0 ? 'original' : 'correction',
            'correction_revision' => $correctionRevision === 0 ? null : $correctionRevision, 'predecessor_basis_ref' => $predecessorBasisRef,
            'currency' => $in->currency, 'currency_exponent' => $in->currencyExponent,
            'net_minor' => $draft->netMinor, 'tax_minor' => $draft->taxMinor, 'gross_minor' => $draft->grossMinor,
            'charge_kind' => $draft->chargeKind->value, 'no_charge_reason' => $draft->noChargeReason,
            'inputs_digest' => $draft->inputsDigest, 'rated_digest' => $draft->ratedDigest]);
        // Every line has the same columns, so the basis stores them in one statement, in line order.
        $rows = [];
        foreach ($draft->lines as $line) {
            $row = ['basis_id' => $id, 'line_snapshot' => CanonicalJson::encode($line)];
            foreach (get_object_vars($line) as $key => $value) {
                $column = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $key));
                $row[$key === 'intermediatesJson' ? 'intermediates' : $column] = $value;
            }
            $rows[] = $row;
        }
        if ($rows !== []) {
            $db->table('billium_rating_rated_line')->insert($rows);
        }

        return $id;
    }

    public function insertBooking(TransactionContext $context, int $id, ?string $obligation, string $basis): void
    {
        $caller = $context->caller();
        $this->database($context)->table('billium_rating_booking')->insert(['seller_ref' => $caller->sellerRef, 'environment' => $caller->environment,
            'basis_id' => $id, 'outcome' => $obligation === null ? 'no_charge' : 'booked', 'obligation_ref' => $obligation,
            'obligation_basis_ref' => $obligation === null ? null : 'rating:'.$basis]);
    }

    public function advanceFrontier(TransactionContext $context, FrozenRatingInputs $inputs, RatedBasisDraft $draft, bool $alreadyFrozen): void
    {
        $head = $this->frontier(occurrenceRef: $inputs->occurrenceRef, context: $context);
        $expectedVersion = $alreadyFrozen ? $head['rowVersion'] : $inputs->frontierExpectedRowVersion;
        $expectedDigest = $alreadyFrozen ? $head['headDigest'] : $inputs->frontierExpectedDigest;
        if (($alreadyFrozen && $head['headBasisRef'] !== $draft->basisRef)
            || $this->query(table: 'source_frontier', context: $context)->where('frontier_key', 'occurrence:'.$inputs->occurrenceRef)
                ->where('row_version', $expectedVersion)->where('head_digest', $expectedDigest)->update([
                    'row_version' => $expectedVersion + 1, 'head_basis_ref' => $draft->basisRef, 'head_digest' => $draft->ratedDigest]) !== 1) {
            RatingComputation::refuse('frozen_input_stale');
        }
        foreach ($inputs->prices as $price) {
            if ($price->commitmentScopeDigest !== null) {
                $query = $this->query(table: 'source_frontier', context: $context)->where('frontier_key', 'commitment:'.$price->commitmentScopeDigest);
                $version = RatingRows::integer((clone $query)->value('row_version'));
                $current = $this->commitment(scope: $price->commitmentScopeDigest, context: $context);
                if ($query->where('row_version', $version)->update(['row_version' => $version + 1,
                    'head_basis_ref' => $draft->basisRef, 'head_digest' => $current['basisDigest']]) !== 1) {
                    RatingComputation::refuse('frozen_input_stale');
                }
            }
        }
    }

    public function event(TransactionContext $context, int $id, string $kind, BookingReceipt $receipt): void
    {
        $caller = $context->caller();
        $this->database($context)->table('billium_rating_event')->insert(['seller_ref' => $caller->sellerRef, 'environment' => $caller->environment,
            'basis_id' => $id, 'event_type' => $kind, 'payload' => CanonicalJson::encode($receipt->body())]);
    }
}
