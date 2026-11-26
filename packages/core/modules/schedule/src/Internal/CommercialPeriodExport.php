<?php

declare(strict_types=1);

namespace Billium\Schedule\Internal;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\CommercialPeriod;
use Billium\Schedule\Contracts\CommercialPeriods;
use Billium\Schedule\Data\PeriodQuote;
use LogicException;
use Packman\Attributes\Internal;

#[Internal]
final readonly class CommercialPeriodExport implements CommercialPeriods
{
    public function __construct(private Codec $codec) {}

    public function fromQuote(PeriodQuote $quote): CommercialPeriod
    {
        $terms = $quote->terms;
        $policy = $terms->policy;
        if ($policy->intervalCount !== 1 || $policy->dstGapPolicy !== 'shift_forward' || $policy->dstFoldPolicy !== 'earlier_offset') {
            ScheduleProblems::refuse('period_wire_policy_unsupported');
        }
        $identity = substr(Canonical::digest([$terms->sellerRef, $terms->environment, $terms->componentRef,
            $terms->purpose, $quote->period->start, $quote->period->end]), 7);
        $body = ['schema' => 'period.commercial.v1', 'periodRef' => 'period:'.$identity,
            'sellerRef' => $terms->sellerRef, 'componentRef' => $terms->componentRef,
            'agreementRef' => $terms->agreementRef, 'agreementRevision' => $terms->agreementRevision,
            'interval' => ['start' => $quote->period->start, 'end' => $quote->period->end],
            'localStart' => $quote->period->localStart, 'localEnd' => $quote->period->localEnd,
            'timezone' => $policy->timezone, 'tzdbVersion' => $quote->period->tzdbVersion,
            'calendarPolicy' => $policy->calendarPolicy, 'frequency' => $policy->frequency,
            'anchor' => ['day' => (int) substr($terms->anchorLocal, 8, 2), 'month' => (int) substr($terms->anchorLocal, 5, 2),
                'localTime' => substr($terms->anchorLocal, 11), 'endOfMonth' => $policy->endOfMonth],
            'shortMonthPolicy' => $policy->shortMonthPolicy, 'dstGapPolicy' => $policy->dstGapPolicy,
            'dstFoldPolicy' => $policy->dstFoldPolicy, 'prorationPolicy' => $policy->prorationPolicy,
            'chargeTiming' => $policy->chargeTiming, 'externalCorrelationRef' => $terms->externalCorrelationRef];
        $body['digest'] = $this->codec->digest($body);
        $period = $this->codec->decode(Canonical::encode($body));

        return $period instanceof CommercialPeriod ? $period : throw new LogicException('Unexpected commercial period envelope');
    }
}
