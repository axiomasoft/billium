<?php

declare(strict_types=1);

namespace Billium\Usage\Internal;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Runtime\CallerContext;
use Illuminate\Database\DatabaseManager;
use Packman\Attributes\Internal;

/** D56 / COV-334: authorized keyset scan; each page is bounded, and always filters frozen attribution. */
#[Internal]
final readonly class UsageQueries
{
    public function __construct(private DatabaseManager $databases, private CallerContext $callers, private BilliumOptions $options) {}

    /**
     * Returns effective revisions only. A correction accepted between pages requires a fresh scan to include it;
     * this admission query does not promise a final aggregate snapshot (the aggregation snapshot protocol owns that).
     *
     * @param  array{event_at:string,fact_id:string,revision:int,source:string}|null  $after
     * @return list<object>
     */
    public function period(string $subject, string $metricVersion, string $mappingRef, string $start, string $end, int $limit = 100, ?array $after = null): array
    {
        if ($limit < 1 || $limit > 500 || $subject === '' || $metricVersion === '' || $mappingRef === ''
            || DatabaseUsage::time($start) >= DatabaseUsage::time($end)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'usage query requires bounded subject/window/attribution');
        }
        $caller = $this->callers->current();
        $sql = <<<'SQL'
            SELECT f.* FROM public.billium_usage_fact f
            WHERE f.seller_ref=? AND f.environment=? AND f.subject_ref=? AND f.metric_version=? AND f.mapping_ref=?
                AND f.event_at>=? AND f.event_at<?
                AND NOT EXISTS (SELECT 1 FROM public.billium_usage_fact newer
                    WHERE newer.seller_ref=f.seller_ref AND newer.environment=f.environment
                        AND newer.source=f.source AND newer.fact_id=f.fact_id AND newer.revision>f.revision)
            SQL;
        $bindings = [$caller->sellerRef, $caller->environment, $subject, $metricVersion, $mappingRef,
            DatabaseUsage::time($start), DatabaseUsage::time($end)];
        if ($after !== null) {
            $sql .= ' AND (f.event_at,f.fact_id,f.revision,f.source)>(?,?,?,?)';
            $bindings[] = $after['event_at'];
            $bindings[] = $after['fact_id'];
            $bindings[] = $after['revision'];
            $bindings[] = $after['source'];
        }
        $sql .= ' ORDER BY f.event_at,f.fact_id,f.revision,f.source LIMIT ?';
        $bindings[] = $limit;

        $name = $this->options->connection;
        $rows = $this->databases->connection(is_string($name) ? $name : null)->select(query: $sql, bindings: $bindings);

        return array_values(array_filter($rows, is_object(...)));
    }
}
