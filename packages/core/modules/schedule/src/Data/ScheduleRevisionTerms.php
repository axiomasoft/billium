<?php

declare(strict_types=1);

namespace Billium\Schedule\Data;

use Billium\Contracts\Attributes\Api;
use Billium\Schedule\Internal\Canonical;
use Billium\Schedule\Internal\Payload;
use Billium\Schedule\Internal\ScheduleProblems;
use DateTimeImmutable;
use DateTimeZone;

/** Approved host input, not an agreement lifecycle (REQ-CON-33, REQ-NBR-06; owner schedule). */
#[Api]
final readonly class ScheduleRevisionTerms
{
    public function __construct(
        public string $sellerRef,
        public string $environment,
        public string $agreementRef,
        public string $componentRef,
        public int $agreementRevision,
        public int $cancellationGeneration,
        public string $anchorLocal,
        public PeriodPolicy $policy,
        public string $priceVersionRef,
        public string $monetaryTermsDigest,
        public string $externalCorrelationRef,
        public int $fundingRevision,
        public string $purpose = 'commercial_coverage',
        public string $action = 'apply',
        public string $anchorPolicy = 'preserve',
    ) {
        foreach ([$sellerRef, $agreementRef, $componentRef, $priceVersionRef, $externalCorrelationRef, $purpose] as $ref) {
            if ($ref === '' || strlen($ref) > 200) {
                ScheduleProblems::refuse('invalid_reference');
            }
        }
        $anchor = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $anchorLocal, new DateTimeZone('UTC'));
        if ($anchor === false || $anchor->format('Y-m-d\TH:i:s') !== $anchorLocal
            || ! in_array($environment, ['test', 'live'], true)
            || $agreementRevision < 1 || $cancellationGeneration < 0 || $fundingRevision < 1
            || preg_match('/^sha256:[a-f0-9]{64}$/D', $monetaryTermsDigest) !== 1
            || ! in_array($action, ['apply', 'pause', 'resume'], true)
            || ! in_array($anchorPolicy, ['preserve', 'reanchor_explicit'], true)) {
            ScheduleProblems::refuse('invalid_revision_terms');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['sellerRef' => $this->sellerRef, 'environment' => $this->environment,
            'agreementRef' => $this->agreementRef, 'componentRef' => $this->componentRef,
            'agreementRevision' => $this->agreementRevision, 'cancellationGeneration' => $this->cancellationGeneration,
            'anchorLocal' => $this->anchorLocal, 'policy' => $this->policy->toArray(), 'priceVersionRef' => $this->priceVersionRef,
            'monetaryTermsDigest' => $this->monetaryTermsDigest, 'externalCorrelationRef' => $this->externalCorrelationRef,
            'fundingRevision' => $this->fundingRevision, 'purpose' => $this->purpose, 'action' => $this->action,
            'anchorPolicy' => $this->anchorPolicy];
    }

    /** @param array<string, mixed> $value */
    public static function fromArray(array $value): self
    {
        Payload::fields(value: $value, expected: ['sellerRef', 'environment', 'agreementRef', 'componentRef', 'agreementRevision',
            'cancellationGeneration', 'anchorLocal', 'policy', 'priceVersionRef', 'monetaryTermsDigest', 'externalCorrelationRef',
            'fundingRevision', 'purpose', 'action', 'anchorPolicy']);
        $p = Payload::map($value['policy']);
        Payload::fields(value: $p, expected: ['timezone', 'frequency', 'intervalCount', 'endOfMonth', 'shortMonthPolicy',
            'dstGapPolicy', 'dstFoldPolicy', 'chargeTiming', 'cutoffSeconds', 'calendarPolicy', 'prorationPolicy']);
        $policy = new PeriodPolicy(timezone: Payload::text($p['timezone']), frequency: Payload::text($p['frequency']),
            intervalCount: Payload::integer($p['intervalCount']), endOfMonth: Payload::boolean($p['endOfMonth']),
            shortMonthPolicy: Payload::text($p['shortMonthPolicy']), dstGapPolicy: Payload::text($p['dstGapPolicy']),
            dstFoldPolicy: Payload::text($p['dstFoldPolicy']), chargeTiming: Payload::text($p['chargeTiming']),
            cutoffSeconds: Payload::integer($p['cutoffSeconds']), calendarPolicy: Payload::text($p['calendarPolicy']),
            prorationPolicy: Payload::text($p['prorationPolicy']));

        return new self(sellerRef: Payload::text($value['sellerRef']), environment: Payload::text($value['environment']),
            agreementRef: Payload::text($value['agreementRef']), componentRef: Payload::text($value['componentRef']),
            agreementRevision: Payload::integer($value['agreementRevision']), cancellationGeneration: Payload::integer($value['cancellationGeneration']),
            anchorLocal: Payload::text($value['anchorLocal']), policy: $policy, priceVersionRef: Payload::text($value['priceVersionRef']),
            monetaryTermsDigest: Payload::text($value['monetaryTermsDigest']), externalCorrelationRef: Payload::text($value['externalCorrelationRef']),
            fundingRevision: Payload::integer($value['fundingRevision']), purpose: Payload::text($value['purpose']),
            action: Payload::text($value['action']), anchorPolicy: Payload::text($value['anchorPolicy']));
    }

    public function digest(): string
    {
        return Canonical::digest($this->toArray());
    }
}
