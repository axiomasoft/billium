<?php

declare(strict_types=1);

namespace Billium\Schedule\Internal;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Core\Contracts\CallerContext;
use Billium\Core\Contracts\OperationRunner;
use Billium\Core\Contracts\TransactionContext;
use Billium\Core\Data\LockRequest;
use Billium\Core\Data\OperationSpec;
use Billium\Core\Enums\FinancialMode;
use Billium\Core\Enums\LockRank;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Schedule\Contracts\AgreementRevisionCommit;
use Billium\Schedule\Contracts\AgreementRevisionGuard;
use Billium\Schedule\Contracts\Clock;
use Billium\Schedule\Contracts\IntervalResolver;
use Billium\Schedule\Data\PeriodQuote;
use Billium\Schedule\Data\ScheduleReceipt;
use Billium\Schedule\Data\ScheduleRevisionTerms;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\JoinClause;
use LogicException;

final readonly class DatabaseAgreementRevisionCommit implements AgreementRevisionCommit
{
    public function __construct(
        private DatabaseManager $databases,
        private OperationRunner $runner,
        private CallerContext $callers,
        private IntervalResolver $resolver,
        private AgreementRevisionGuard $guard,
        private Clock $clock,
    ) {}

    public function preview(ScheduleRevisionTerms $terms, int $ordinal, string $validUntil, string $commandKey): PeriodQuote
    {
        $this->assertScope($terms);
        Instants::utc($validUntil);
        $outcome = $this->runner->run(
            spec: $this->spec(kind: 'schedule.quote.v1', key: $commandKey,
                digest: Canonical::digest([$terms->toArray(), $ordinal, $validUntil]), financial: false),
            body: function (TransactionContext $context) use ($terms, $ordinal, $validUntil): PeriodQuote {
                $this->runner->assertCurrent(context: $context, moduleId: 'schedule');
                $period = $this->resolver->resolve(terms: $terms, ordinal: $ordinal);
                $now = $this->clock->now();
                if (Instants::utc($validUntil) <= $now || Instants::utc($validUntil) > Instants::utc($period->cutoffAt)) {
                    ScheduleProblems::refuse('invalid_quote_validity_or_cutoff');
                }
                $quote = new PeriodQuote(terms: $terms, ordinal: $ordinal, period: $period,
                    issuedAt: Instants::format($now), validUntil: $validUntil);
                $this->database($context->connectionName())->table('billium_schedule_quote')->insertOrIgnore([
                    'seller_ref' => $terms->sellerRef, 'environment' => $terms->environment, 'quote_ref' => $quote->quoteRef,
                    'component_ref' => $terms->componentRef, 'agreement_ref' => $terms->agreementRef,
                    'agreement_revision' => $terms->agreementRevision, 'cancellation_generation' => $terms->cancellationGeneration,
                    'terms_digest' => $terms->digest(), 'period_digest' => $period->digest(), 'quote_digest' => $quote->digest,
                    'valid_until' => $validUntil, 'body' => Canonical::encode($quote->body()),
                ]);

                return $quote;
            },
        )->orThrow();

        return PeriodQuote::fromArray($outcome->body);
    }

    public function commit(string $quoteRef, string $quoteDigest, int $expectedRevision, int $cancellationGeneration, string $commandKey): ScheduleReceipt
    {
        $outcome = $this->runner->run(
            spec: $this->spec(kind: 'schedule.commit.v1', key: $commandKey,
                digest: Canonical::digest([$quoteRef, $quoteDigest, $expectedRevision, $cancellationGeneration]), financial: true),
            body: function (TransactionContext $context) use ($quoteRef, $quoteDigest, $expectedRevision, $cancellationGeneration): ScheduleReceipt {
                $this->runner->assertCurrent(context: $context, moduleId: 'schedule');
                $db = $this->database($context->connectionName());
                $caller = $context->caller();
                $scope = ['seller_ref' => $caller->sellerRef, 'environment' => $caller->environment];
                $row = $db->table('billium_schedule_quote')->where([...$scope, 'quote_ref' => $quoteRef])->first();
                if ($row === null) {
                    ScheduleProblems::refuse('quote_unknown', ProblemCode::ReferenceUnknown);
                }
                $quote = PeriodQuote::fromArray(Payload::decode($row->body));
                $terms = $quote->terms;
                if ($quoteDigest !== $quote->digest || $expectedRevision !== $terms->agreementRevision
                    || $cancellationGeneration !== $terms->cancellationGeneration) {
                    ScheduleProblems::refuse('quote_expectation_conflict', ProblemCode::IdempotencyConflict);
                }
                // A business replay returns the immutable receipt even after expiry, amendment or cancellation.
                $existing = $db->table('billium_schedule_revision')->where([...$scope, 'quote_ref' => $quoteRef])->first();
                if ($existing !== null) {
                    return new ScheduleReceipt(Payload::decode($existing->receipt));
                }
                $this->guard->assertCurrent(context: $context, terms: $terms);
                $key = Canonical::digest([$caller->sellerRef, $caller->environment, $terms->componentRef]);
                $db->table('billium_schedule_component')->insertOrIgnore([...$scope, 'component_key' => $key,
                    'component_ref' => $terms->componentRef, 'agreement_ref' => $terms->agreementRef]);
                $context->lock(new LockRequest(rank: LockRank::BillingSchedule, table: 'billium_schedule_component', keys: [$key], keyColumn: 'component_key'));
                // Repeat under the component lock: another key may have committed this quote while we waited.
                $existing = $db->table('billium_schedule_revision')->where([...$scope, 'quote_ref' => $quoteRef])->first();
                if ($existing !== null) {
                    return new ScheduleReceipt(Payload::decode($existing->receipt));
                }
                $head = $db->table('billium_schedule_component')->where([...$scope, 'component_key' => $key])->first()
                    ?? throw new LogicException('Schedule component disappeared under its lock');
                $this->assertRevision(quote: $quote, head: ComponentHead::fromRow($head));
                $receipt = $this->receipt($quote);
                $body = $receipt->body();
                if ($terms->action !== 'pause') {
                    $db->table('billium_schedule_period')->insert([
                        ...$scope, 'period_ref' => $body['periodRef'], 'coverage_ref' => $body['coverageRef'],
                        'occurrence_ref' => $body['occurrenceRef'], 'component_ref' => $terms->componentRef, 'purpose' => $terms->purpose,
                        'period_start' => $quote->period->start, 'period_end' => $quote->period->end,
                        'agreement_ref' => $terms->agreementRef, 'agreement_revision' => $terms->agreementRevision,
                        'funding_revision' => $terms->fundingRevision, 'period_digest' => $quote->period->digest(),
                        'terms_digest' => $terms->digest(), 'body' => Canonical::encode($quote->period->toArray()),
                    ]);
                }
                $db->table('billium_schedule_revision')->insert([
                    ...$scope, 'component_ref' => $terms->componentRef, 'agreement_ref' => $terms->agreementRef,
                    'agreement_revision' => $terms->agreementRevision, 'cancellation_generation' => $terms->cancellationGeneration,
                    'funding_revision' => $terms->fundingRevision, 'quote_ref' => $quoteRef, 'action' => $terms->action,
                    'effective_at' => $quote->period->start, 'terms_digest' => $terms->digest(),
                    'body' => Canonical::encode($terms->toArray()), 'receipt' => Canonical::encode($body),
                ]);
                $db->table('billium_schedule_component')->where([...$scope, 'component_key' => $key])->update([
                    'agreement_revision' => $terms->agreementRevision, 'cancellation_generation' => $terms->cancellationGeneration,
                    'funding_revision' => $terms->fundingRevision, 'action' => $terms->action,
                    'effective_at' => $quote->period->start,
                    'covered_until' => $terms->action === 'pause' ? $quote->period->start : $quote->period->end,
                    'terms' => Canonical::encode($terms->toArray()),
                ]);

                return $receipt;
            },
        )->orThrow();

        return new ScheduleReceipt($outcome->body);
    }

    public function coverage(string $coverageRef): ?ScheduleReceipt
    {
        $caller = $this->callers->current();
        $row = $this->database()->table('billium_schedule_period as p')
            ->join('billium_schedule_revision as r', function (JoinClause $join): void {
                $join->on('p.seller_ref', '=', 'r.seller_ref')->on('p.environment', '=', 'r.environment')
                    ->on('p.component_ref', '=', 'r.component_ref')->on('p.agreement_revision', '=', 'r.agreement_revision');
            })->where(['p.seller_ref' => $caller->sellerRef, 'p.environment' => $caller->environment, 'p.coverage_ref' => $coverageRef])
            ->first(['r.receipt']);

        return $row === null ? null : new ScheduleReceipt(Payload::decode($row->receipt));
    }

    private function assertRevision(PeriodQuote $quote, ComponentHead $head): void
    {
        $terms = $quote->terms;
        $now = $this->clock->now();
        if ($now < Instants::utc($quote->issuedAt) || $now >= Instants::utc($quote->validUntil)
            || $now > Instants::utc($quote->period->cutoffAt)) {
            ScheduleProblems::refuse('quote_expired_or_cutoff');
        }
        if ($head->agreementRef !== $terms->agreementRef || $terms->agreementRevision <= $head->revision
            || $terms->cancellationGeneration < $head->cancellationGeneration || $terms->fundingRevision < $head->fundingRevision) {
            ScheduleProblems::refuse('stale_agreement_revision');
        }
        if ($head->revision === 0) {
            if ($terms->action !== 'apply') {
                ScheduleProblems::refuse('initial_revision_must_apply');
            }

            return;
        }
        $old = $head->terms ?? throw new LogicException('Accepted schedule head has no terms');
        $coveredUntil = $head->coveredUntil ?? throw new LogicException('Accepted schedule head has no boundary');
        if ($terms->purpose !== $old->purpose || $terms->externalCorrelationRef !== $old->externalCorrelationRef) {
            ScheduleProblems::refuse('component_identity_changed');
        }
        if (($terms->action === 'resume') !== ($head->action === 'pause') || ($terms->action === 'pause' && $head->action === 'pause')) {
            ScheduleProblems::refuse('unsupported_pause_resume_transition');
        }
        if (Instants::utc($quote->period->start) < $coveredUntil
            || ($terms->anchorPolicy === 'preserve' && $terms->action !== 'resume' && Instants::utc($quote->period->start) != $coveredUntil)) {
            ScheduleProblems::refuse('midperiod_or_backdated_correction_required');
        }
        if ($terms->anchorPolicy === 'preserve' && ($terms->anchorLocal !== $old->anchorLocal
            || $terms->policy->timezone !== $old->policy->timezone || $terms->policy->frequency !== $old->policy->frequency
            || $terms->policy->intervalCount !== $old->policy->intervalCount || $terms->policy->endOfMonth !== $old->policy->endOfMonth)) {
            ScheduleProblems::refuse('explicit_reanchor_required');
        }
    }

    private function receipt(PeriodQuote $quote): ScheduleReceipt
    {
        $terms = $quote->terms;
        $identity = substr(Canonical::digest([$terms->sellerRef, $terms->environment, $terms->componentRef,
            $terms->purpose, $quote->period->start, $quote->period->end]), 7);

        return new ScheduleReceipt([
            'schema' => 'billium.schedule.coverage/1', 'sellerRef' => $terms->sellerRef, 'environment' => $terms->environment,
            'agreementRef' => $terms->agreementRef, 'componentRef' => $terms->componentRef,
            'agreementRevision' => $terms->agreementRevision, 'cancellationGeneration' => $terms->cancellationGeneration,
            'fundingRevision' => $terms->fundingRevision, 'externalCorrelationRef' => $terms->externalCorrelationRef,
            'action' => $terms->action, 'coverageRef' => $terms->action === 'pause' ? null : 'coverage:'.$identity,
            'occurrenceRef' => $terms->action === 'pause' ? null : 'occurrence:'.$identity,
            'periodRef' => $terms->action === 'pause' ? null : 'period:'.$identity,
            'period' => $quote->period->toArray(), 'periodDigest' => $quote->period->digest(),
            'termsDigest' => $terms->digest(), 'priceVersionRef' => $terms->priceVersionRef,
            'monetaryTermsDigest' => $terms->monetaryTermsDigest, 'quoteRef' => $quote->quoteRef,
        ]);
    }

    private function assertScope(ScheduleRevisionTerms $terms): void
    {
        $caller = $this->callers->current();
        if ($terms->sellerRef !== $caller->sellerRef || $terms->environment !== $caller->environment) {
            ScheduleProblems::refuse('scope_denied', ProblemCode::AuthDenied);
        }
    }

    private function spec(string $kind, string $key, string $digest, bool $financial): OperationSpec
    {
        $caller = $this->callers->current();

        return new OperationSpec(commandKind: $kind, sellerRef: $caller->sellerRef, environment: $caller->environment,
            mode: $financial ? FinancialMode::Active : FinancialMode::Administrative,
            operationClass: ModuleOperationClass::NewIntent, modules: ['platform', 'rating', 'schedule'],
            commandKey: $key, digest: $digest, commitKind: $financial ? 'schedule.revision_committed' : null);
    }

    private function database(?string $connection = null): Connection
    {
        $name = $connection ?? config('billium.connection') ?? config('database.default');
        if (! is_string($name)) {
            throw new LogicException('Schedule database connection must be configured');
        }

        return $this->databases->connection($name);
    }
}
