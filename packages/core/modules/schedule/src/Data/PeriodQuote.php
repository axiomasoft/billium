<?php

declare(strict_types=1);

namespace Billium\Schedule\Data;

use Billium\Core\Data\Runner\Receipt;
use Billium\Schedule\Internal\Canonical;
use Billium\Schedule\Internal\Payload;
use Packman\Attributes\Api;

#[Api]
final readonly class PeriodQuote implements Receipt
{
    public string $digest;

    public string $quoteRef;

    public function __construct(
        public ScheduleRevisionTerms $terms,
        public int $ordinal,
        public ResolvedInterval $period,
        public string $issuedAt,
        public string $validUntil,
    ) {
        $this->digest = Canonical::digest($this->content());
        $this->quoteRef = 'quote:'.substr($this->digest, 7);
    }

    /** @return array<string, mixed> */
    private function content(): array
    {
        return ['schema' => 'billium.schedule.quote/1', 'terms' => $this->terms->toArray(), 'termsDigest' => $this->terms->digest(),
            'ordinal' => $this->ordinal, 'period' => $this->period->toArray(), 'periodDigest' => $this->period->digest(),
            'issuedAt' => $this->issuedAt, 'validUntil' => $this->validUntil];
    }

    public function receiptClass(): string
    {
        return 'schedule.period_quoted';
    }

    /** @return array<string, mixed> */
    public function body(): array
    {
        return [...$this->content(), 'quoteRef' => $this->quoteRef, 'digest' => $this->digest];
    }

    /**
     * Hydrate persisted boundaries; deliberately does not invoke the resolver or current tzdb.
     *
     * @param  array<string, mixed>  $body
     */
    public static function fromArray(array $body): self
    {
        $period = Payload::map($body['period']);

        return new self(
            terms: ScheduleRevisionTerms::fromArray(Payload::map($body['terms'])), ordinal: Payload::integer($body['ordinal']),
            period: new ResolvedInterval(start: Payload::text($period['start']), end: Payload::text($period['end']),
                localStart: Payload::text($period['localStart']), localEnd: Payload::text($period['localEnd']),
                cutoffAt: Payload::text($period['cutoffAt']), tzdbVersion: Payload::text($period['tzdbVersion']), evidence: Payload::map($period['evidence'])),
            issuedAt: Payload::text($body['issuedAt']), validUntil: Payload::text($body['validUntil']),
        );
    }
}
