<?php

declare(strict_types=1);

namespace Billium\Usage\Internal;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\MetricVersion;
use Billium\Contracts\Data\Envelopes\UsageFactV2;
use Billium\Contracts\Data\Envelopes\UsageReceiptV2;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Platform\Data\Installation\PlatformReceipt;
use Billium\Usage\Contracts\Usage;
use Billium\Usage\Contracts\UsageAttribution;
use Billium\Usage\Data\AttributionSnapshot;
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
        $caller = $this->authenticate($fact->sellerRef);
        if (! in_array($fact->source, $caller->sourceAuthorities, true)) {
            throw new ProblemException(ProblemCode::AuthDenied, 'caller is not authorized for this usage source');
        }
        // A committed replay needs no mutable host lookup. The runner still authenticates and checks its digest.
        $name = $this->options->connection;
        $db = $this->databases->connection(is_string($name) ? $name : null);
        $exists = $db->table('billium_usage_fact')->where([
            'seller_ref' => $caller->sellerRef, 'environment' => $caller->environment,
            'source' => $fact->source, 'fact_id' => $fact->factId, 'revision' => $fact->revision,
        ])->exists();
        $snapshot = $exists ? null : $this->attribution->resolve(caller: $caller, fact: $fact);
        $outcome = $this->runner->run(
            spec: $this->spec($caller, 'usage.fact_submit', [$fact->source, $fact->factId, $fact->revision], $fact->digest),
            body: function (TransactionContext $context) use ($fact, $caller, $snapshot): PlatformReceipt {
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
                $this->validateSemantics(fact: $fact, metric: $metric);
                $this->validateSnapshot(fact: $fact, snapshot: $snapshot);
                assert($snapshot instanceof AttributionSnapshot);
                $previous = $db->table('billium_usage_fact')->where([
                    'seller_ref' => $caller->sellerRef, 'environment' => $caller->environment,
                    'source' => $fact->source, 'fact_id' => $fact->factId,
                ])->orderByDesc('revision')->first();
                $this->validateCorrection(fact: $fact, previous: $previous, snapshot: $snapshot);
                $body = [
                    'schema' => 'usage.receipt.v2', 'sellerRef' => $caller->sellerRef,
                    'source' => $fact->source, 'factId' => $fact->factId, 'revision' => $fact->revision,
                    'result' => 'accepted', 'factDigest' => $fact->digest,
                    'receiptRef' => 'usage:'.hash('sha256', $this->codec->canonicalize([
                        $caller->sellerRef, $caller->environment, $fact->source, $fact->factId, $fact->revision,
                    ])),
                ];
                $body['digest'] = $this->codec->digest($body);
                $receipt = $this->codec->decode($this->codec->canonicalize($body));
                assert($receipt instanceof UsageReceiptV2);
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
        $outcome->orThrow();
        $receipt = $this->codec->decode($this->codec->canonicalize($outcome->body));
        assert($receipt instanceof UsageReceiptV2);

        return $receipt;
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
    private function spec(Caller $caller, string $kind, array $identity, string $digest): OperationSpec
    {
        return new OperationSpec(
            commandKind: $kind, sellerRef: $caller->sellerRef, environment: $caller->environment,
            mode: FinancialMode::Administrative, operationClass: ModuleOperationClass::NewIntent,
            modules: ['platform', 'usage'], commandKey: hash('sha256', $this->codec->canonicalize($identity)), digest: $digest,
        );
    }

    private function database(TransactionContext $context): Connection
    {
        $this->runner->assertCurrent(context: $context, moduleId: 'usage');

        return $this->databases->connection($context->connectionName());
    }

    private function validateSemantics(UsageFactV2 $fact, MetricVersion $metric): void
    {
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

    private function validateSnapshot(UsageFactV2 $fact, ?AttributionSnapshot $snapshot): void
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
