<?php

declare(strict_types=1);

namespace Billium\Usage\Internal;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\MetricVersion;
use Billium\Contracts\Data\Envelopes\UsageFact;
use Billium\Contracts\Data\Envelopes\UsageFactV2;
use Billium\Contracts\Data\Envelopes\UsageReceipt;
use Billium\Contracts\Data\Envelopes\UsageReceiptV2;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Database\RlsSessionContext;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Platform\Data\Installation\PlatformReceipt;
use Billium\Usage\Contracts\Usage;
use Billium\Usage\Contracts\UsageAttribution;
use Billium\Usage\Contracts\UsageFactV1Attribution;
use Billium\Usage\Data\AttributionSnapshot;
use Billium\Usage\Data\UsageFactV1Inputs;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Packman\Attributes\Internal;
use stdClass;

#[Internal]
final readonly class DatabaseUsage implements Usage
{
    public function __construct(
        private DatabaseManager $databases,
        private OperationRunner $runner,
        private CallerContext $callers,
        private UsageAttribution $attribution,
        private Codec $codec,
        private BilliumOptions $options,
    ) {}

    public function registerMetric(MetricVersion $metric): void
    {
        // Re-decode raw: generated DTO constructors and their nested objects are not a validation boundary.
        $metric = $this->codec->decode($this->codec->encode($metric));
        assert($metric instanceof MetricVersion);
        $caller = $this->authenticate($metric->sellerRef);
        $outcome = $this->runner->run(
            spec: $this->spec($caller, 'usage.metric_register', [$metric->metricRef, $metric->metricVersion], $metric->digest),
            body: function (TransactionContext $context) use ($metric, $caller): PlatformReceipt {
                $db = $this->database($context);
                $stream = $metric->billableStream;
                $db->insert(query: 'INSERT INTO public.billium_usage_stream (seller_ref, environment, commercial_basis_ref, mode, normalization_ref) VALUES (?, ?, ?, ?, ?) ON CONFLICT DO NOTHING',
                    bindings: [$caller->sellerRef, $caller->environment, $stream->commercialBasisRef, $stream->mode, $stream->normalizationRef]);
                $stored = $db->selectOne(query: 'SELECT mode, normalization_ref FROM public.billium_usage_stream WHERE seller_ref=? AND environment=? AND commercial_basis_ref=?',
                    bindings: [$caller->sellerRef, $caller->environment, $stream->commercialBasisRef]);
                if (! $stored instanceof stdClass || $stored->mode !== $stream->mode || $stored->normalization_ref !== $stream->normalizationRef) {
                    throw new ProblemException(ProblemCode::UsageConflict, 'commercial basis already has a different gross/net stream');
                }
                $db->table('billium_usage_metric')->insert([
                    'seller_ref' => $caller->sellerRef, 'environment' => $caller->environment,
                    'metric_ref' => $metric->metricRef, 'metric_version' => $metric->metricVersion,
                    'digest' => $metric->digest, 'unit' => $metric->unit, 'model' => $metric->model->id,
                    'commercial_basis_ref' => $stream->commercialBasisRef, 'mode' => $stream->mode,
                    'body' => $this->codec->encode($metric),
                ]);

                return new PlatformReceipt('usage.metric_registered', ['digest' => $metric->digest]);
            },
        );
        if (! $outcome->accepted) {
            $outcome->orThrow();
        }
    }

    public function submitUsageFact(UsageFactV2 $fact): UsageReceiptV2
    {
        $fact = $this->codec->decode($this->codec->encode($fact));
        assert($fact instanceof UsageFactV2);
        $outcome = $this->submit($fact);
        $receipt = $this->codec->decode($this->codec->canonicalize($outcome->orThrow()->body));
        assert($receipt instanceof UsageReceiptV2);

        return $receipt;
    }

    public function acceptUsageFact(UsageFact $fact, UsageFactV1Attribution $attribution): UsageReceipt
    {
        $fact = $this->codec->decode($this->codec->encode($fact));
        assert($fact instanceof UsageFact);
        try {
            $outcome = $this->submit($fact, $attribution)->orThrow();
            $body = $outcome->replayed ? $this->v1Receipt($fact, 'duplicate') : $outcome->body;
        } catch (ProblemException $problem) {
            if ($problem->problem !== ProblemCode::IdempotencyConflict) {
                throw $problem;
            }
            $body = $this->v1Receipt($fact, 'conflict');
        }
        $receipt = $this->codec->decode($this->codec->canonicalize($body));
        assert($receipt instanceof UsageReceipt);

        return $receipt;
    }

    private function submit(UsageFact|UsageFactV2 $fact, ?UsageFactV1Attribution $attribution = null): StoredOutcome
    {
        $caller = $this->authenticate($fact->sellerRef);
        if (! in_array($fact->source, $caller->sourceAuthorities, true)) {
            throw new ProblemException(ProblemCode::AuthDenied, 'caller is not authorized for this usage source');
        }
        // A committed replay needs no mutable host lookup. The runner still authenticates and checks its digest.
        $db = $this->databases->connection($this->options->connection);
        $exists = $db->transaction(static function () use ($db, $caller, $fact): bool {
            RlsSessionContext::apply($db, $caller);

            return $db->table('billium_usage_fact')->useWritePdo()->where([
                'seller_ref' => $caller->sellerRef, 'environment' => $caller->environment,
                'source' => $fact->source, 'fact_id' => $fact->factId, 'revision' => $fact->revision,
            ])->exists();
        });
        $pinned = $exists || $fact instanceof UsageFactV2 ? null : $attribution?->resolve($caller, $fact);
        $snapshot = $exists ? null : ($fact instanceof UsageFactV2 ? $this->attribution->resolve($caller, $fact) : $pinned?->snapshot);
        $scope = $fact instanceof UsageFact ? new FinancialScope($caller->installationRef, $caller->sellerRef, $caller->environment, null, $fact->source) : null;

        return $this->runner->run(
            spec: $this->spec($caller, 'usage.fact_submit', [$fact->source, $fact->factId, $fact->revision], $fact->digest, $scope),
            body: function (TransactionContext $context) use ($fact, $caller, $snapshot, $pinned): PlatformReceipt {
                $db = $this->database($context);
                // Usage owns this leaf lock. No later platform or financial locks are acquired in the body.
                $db->selectOne(query: 'SELECT pg_advisory_xact_lock(hashtextextended(?, 4202))',
                    bindings: [$this->codec->canonicalize([$caller->sellerRef, $caller->environment, $fact->source, $fact->factId])]);
                $row = $db->table('billium_usage_metric')->where([
                    'seller_ref' => $caller->sellerRef, 'environment' => $caller->environment,
                    'metric_ref' => $fact->metricRef, 'metric_version' => $fact->metricVersion,
                ])->first();
                if ($row === null) {
                    throw new ProblemException(ProblemCode::ValidationInvalid, 'unknown metric version');
                }
                $metric = $this->codec->decode(self::text($row->body));
                assert($metric instanceof MetricVersion);
                $previous = $db->table('billium_usage_fact')->where([
                    'seller_ref' => $caller->sellerRef, 'environment' => $caller->environment,
                    'source' => $fact->source, 'fact_id' => $fact->factId,
                ])->orderByDesc('revision')->first();
                $original = $fact;
                if ($fact instanceof UsageFact) {
                    $fact = $this->normalize($fact, $metric, $pinned, $previous);
                }
                $this->validateSemantics(fact: $fact, metric: $metric);
                $this->validateSnapshot(fact: $fact, metric: $metric, snapshot: $snapshot);
                assert($snapshot instanceof AttributionSnapshot);
                $this->validateCorrection(fact: $fact, previous: $previous, snapshot: $snapshot);
                $body = [
                    'schema' => 'usage.receipt.v2', 'sellerRef' => $caller->sellerRef,
                    'source' => $fact->source, 'factId' => $fact->factId, 'revision' => $fact->revision,
                    'result' => 'accepted', 'factDigest' => $fact->digest,
                    'receiptRef' => 'usage:'.hash('sha256', $this->codec->canonicalize([
                        $caller->sellerRef, $caller->environment, $fact->source, $fact->factId, $fact->revision,
                    ])),
                ];
                if ($original instanceof UsageFact) {
                    $body = $this->v1Receipt($original, 'accepted');
                } else {
                    $body['digest'] = $this->codec->digest($body);
                }
                $receipt = $this->codec->decode($this->codec->canonicalize($body));
                assert($receipt instanceof UsageReceiptV2 || $receipt instanceof UsageReceipt);
                $db->table('billium_usage_fact')->insert([
                    'seller_ref' => $caller->sellerRef, 'environment' => $caller->environment,
                    'source' => $fact->source, 'fact_id' => $fact->factId, 'revision' => $fact->revision,
                    'digest' => $fact->digest, 'metric_ref' => $fact->metricRef, 'metric_version' => $fact->metricVersion,
                    'subject_ref' => $fact->attribution->subjectRef, 'customer_ref' => $fact->attribution->customerRef,
                    'billing_account_ref' => $fact->attribution->billingAccountRef, 'component_ref' => $fact->attribution->componentRef,
                    'agreement_ref' => $fact->attribution->agreementRef, 'agreement_revision' => $fact->attribution->agreementRevision,
                    'attribution_version' => $fact->attribution->attributionVersion, 'mapping_ref' => $snapshot->mappingRef,
                    'valid_from' => self::time($snapshot->validFrom), 'valid_until' => self::time($snapshot->validUntil),
                    'period_ref' => $snapshot->periodRef, 'period_start' => self::time($snapshot->periodStart), 'period_end' => self::time($snapshot->periodEnd),
                    'event_at' => self::time($fact->eventAt), 'interval_start' => self::time(self::text($fact->interval->start)), 'interval_end' => self::time(self::text($fact->interval->end)),
                    'received_at' => self::time($fact->receivedAt), 'quantity' => $fact->value->quantity ?? null,
                    'supersedes_revision' => $fact->correction?->supersedesRevision,
                    'correction_ref' => $fact->correction?->correctionRef,
                    'body' => $this->codec->encode($fact), 'receipt' => $this->codec->encode($receipt),
                ]);

                return new PlatformReceipt('usage.fact_admitted', $body);
            },
        );
    }

    private function normalize(UsageFact $fact, MetricVersion $metric, ?UsageFactV1Inputs $pinned, ?stdClass $previous): UsageFactV2
    {
        if ($pinned === null) {
            throw new ProblemException(ProblemCode::PolicyUnknown, 'Missing original usage attribution and provenance');
        }
        $semantics = match ($metric->model->id) {
            'additive_sum', 'additive_count' => 'additive',
            'gauge' => 'gauge',
            'exact_distinct' => 'distinct',
            default => null,
        };
        if ($semantics !== $fact->semantics || ($pinned->snapshot->attribution->subjectRef ?? null) !== $fact->subjectRef
            || ($semantics !== 'distinct' && self::decimal(self::text($pinned->value->quantity ?? null)) !== self::decimal($fact->quantity))
            || (($fact->revision > 1 || $fact->correctsFactId !== null) && $pinned->correctionRef === null)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Usage fact differs from the pinned commercial input');
        }
        $body = ['schema' => UsageFactV2::SCHEMA, 'sellerRef' => $fact->sellerRef, 'source' => $fact->source,
            'factId' => $fact->factId, 'revision' => $fact->revision, 'metricRef' => $fact->metricRef,
            'metricVersion' => $fact->metricVersion, 'metricDigest' => $metric->digest, 'unit' => $fact->unit,
            'attribution' => $pinned->snapshot->attribution, 'interval' => $fact->interval, 'eventAt' => $fact->eventAt,
            'receivedAt' => new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
            'value' => $pinned->value, 'grouping' => $pinned->grouping, 'billableStream' => $pinned->billableStream,
            'provenance' => (object) [...get_object_vars($fact->provenance), 'sourceDigest' => $pinned->sourceDigest]];
        if ($pinned->correctionRef !== null) {
            if ($previous === null || $fact->revision <= 1) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'Correction predecessor absent');
            }
            $body['correction'] = ['correctionRef' => $pinned->correctionRef,
                'supersedesRevision' => $fact->revision - 1, 'originalFactDigest' => self::text($previous->digest)];
        }
        $v1Codec = Codec::forRevision('2026-10');
        $body['digest'] = $v1Codec->digest($body);
        $normalized = $v1Codec->decode($v1Codec->canonicalize($body));
        assert($normalized instanceof UsageFactV2);

        return $normalized;
    }

    private static function decimal(string $quantity): string
    {
        return str_contains($quantity, '.') ? rtrim(rtrim($quantity, '0'), '.') : $quantity;
    }

    /** @return array<string, mixed> */
    private function v1Receipt(UsageFact $fact, string $result): array
    {
        $body = ['schema' => UsageReceipt::SCHEMA, 'result' => $result, 'source' => $fact->source,
            'factId' => $fact->factId, 'revision' => $fact->revision];

        return [...$body, 'digest' => $this->codec->digest($body)];
    }

    private function authenticate(string $seller): Caller
    {
        $caller = $this->callers->current();
        if ($seller !== $caller->sellerRef) {
            throw new ProblemException(ProblemCode::AuthDenied, 'usage seller differs from authenticated caller');
        }

        return $caller;
    }

    /** @param list<int|string> $identity */
    private function spec(Caller $caller, string $kind, array $identity, string $digest, ?FinancialScope $scope = null): OperationSpec
    {
        return new OperationSpec(
            commandKind: $kind, sellerRef: $caller->sellerRef, environment: $caller->environment,
            mode: FinancialMode::Administrative, operationClass: ModuleOperationClass::NewIntent,
            modules: ['platform', 'usage'], commandKey: hash('sha256', $this->codec->canonicalize($identity)), digest: $digest,
            financialScope: $scope, inputDigest: $scope === null ? null : $digest,
        );
    }

    private function database(TransactionContext $context): Connection
    {
        $this->runner->assertCurrent(context: $context, moduleId: 'usage');

        return $this->databases->connection($context->connectionName());
    }

    private function validateSemantics(UsageFactV2 $fact, MetricVersion $metric): void
    {
        if ($metric->correctionPolicy->version === 2 && $fact->revision > $metric->correctionPolicy->maxRevision) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'fact exceeds immutable metric correction revision bound');
        }
        $kind = match ($metric->model->id) {
            'additive_sum', 'gauge' => 'decimal', 'additive_count' => 'count', 'exact_distinct' => 'members',
            default => throw new ProblemException(ProblemCode::ValidationInvalid, 'unsupported metric semantics'),
        };
        $dimensions = array_map(static fn (mixed $group): string => self::text($group instanceof stdClass ? $group->name : null), $fact->grouping);
        sort($dimensions);
        $expected = $metric->groupingDimensions;
        sort($expected);
        if ($fact->metricDigest !== $metric->digest || $fact->unit !== $metric->unit
            || ! is_array($metric->sourceSelection->sources) || ! in_array($fact->source, $metric->sourceSelection->sources, true)
            || $fact->value->kind !== $kind || $dimensions !== $expected
            || $this->codec->canonicalize($fact->billableStream) !== $this->codec->canonicalize($metric->billableStream)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'fact differs from immutable metric semantics');
        }
    }

    private function validateSnapshot(UsageFactV2 $fact, MetricVersion $metric, ?AttributionSnapshot $snapshot): void
    {
        if ($snapshot === null || $snapshot->sellerRef !== $fact->sellerRef || $snapshot->mappingRef === '' || $snapshot->periodRef === ''
            || $this->codec->canonicalize($snapshot->attribution) !== $this->codec->canonicalize($fact->attribution)
            || self::time($snapshot->validFrom) > self::time(self::text($fact->interval->start))
            || self::time($snapshot->validUntil) < self::time(self::text($fact->interval->end))
            || self::time($snapshot->periodStart) > self::time(self::text($fact->interval->start))
            || self::time($snapshot->periodEnd) < self::time(self::text($fact->interval->end))
            || self::time(self::text($fact->attribution->effectiveAt)) > self::time($fact->eventAt)
            || self::time($fact->eventAt) < self::time(self::text($fact->interval->start))
            || self::time($fact->eventAt) >= self::time(self::text($fact->interval->end))) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'unresolved attribution or interval requires pre-split normalized facts');
        }
        if ($metric->attributionPolicy->version === 2
            && ($snapshot->mappingRef !== $metric->attributionPolicy->mappingRef
                || $this->codec->canonicalize($fact->attribution) !== $this->codec->canonicalize($metric->attributionPolicy->attribution))) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'fact differs from immutable metric attribution pin');
        }
    }

    private function validateCorrection(UsageFactV2 $fact, ?stdClass $previous, AttributionSnapshot $snapshot): void
    {
        if ($previous === null) {
            if ($fact->revision !== 1 || $fact->correction !== null) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'correction predecessor absent; retry after predecessor');
            }

            return;
        }
        $original = $this->codec->decode(self::text($previous->body));
        assert($original instanceof UsageFactV2);
        if ($fact->revision !== $original->revision + 1 || $fact->correction === null
            || $fact->correction->supersedesRevision !== $original->revision
            || $fact->correction->originalFactDigest !== $original->digest
            || $fact->metricRef !== $original->metricRef || $fact->metricVersion !== $original->metricVersion
            || $fact->eventAt !== $original->eventAt || $snapshot->mappingRef !== $previous->mapping_ref
            || $snapshot->periodRef !== $previous->period_ref
            || self::time($snapshot->validFrom) !== $previous->valid_from || self::time($snapshot->validUntil) !== $previous->valid_until
            || self::time($snapshot->periodStart) !== $previous->period_start || self::time($snapshot->periodEnd) !== $previous->period_end
            || $this->codec->canonicalize($fact->attribution) !== $this->codec->canonicalize($original->attribution)
            || $this->codec->canonicalize($fact->interval) !== $this->codec->canonicalize($original->interval)
            || $this->codec->canonicalize($fact->grouping) !== $this->codec->canonicalize($original->grouping)) {
            throw new ProblemException(ProblemCode::UsageConflict, 'correction must extend the same immutable fact/attribution chain');
        }
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : throw new ProblemException(ProblemCode::ValidationInvalid, 'expected string');
    }

    /** Preserve nanosecond ordering; PostgreSQL timestamps alone have only microsecond precision. */
    public static function time(string $instant): string
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d{1,9}))?Z$/D', $instant, $parts) !== 1) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'expected UTC instant');
        }
        if (! checkdate((int) substr($instant, 5, 2), (int) substr($instant, 8, 2), (int) substr($instant, 0, 4))
            || (int) substr($instant, 11, 2) > 23 || (int) substr($instant, 14, 2) > 59 || (int) substr($instant, 17, 2) > 59) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'invalid UTC instant');
        }

        return $parts[1].'.'.str_pad($parts[2] ?? '', 9, '0');
    }
}
