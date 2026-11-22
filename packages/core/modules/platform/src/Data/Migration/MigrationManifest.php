<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Migration;

use Billium\Contracts\Contracts\Codec;
use Billium\Platform\Data\Feed\FeedCursor;
use Billium\Platform\Data\Feed\FeedSelection;
use DateTimeImmutable;
use InvalidArgumentException;
use Packman\Attributes\Api;

/** D52/D63: immutable source snapshot and pinned public feed checkpoint. */
#[Api(since: '1.0')]
final readonly class MigrationManifest
{
    /** @param array<array-key,ReconciliationChecksum> $checksums */
    public function __construct(
        public HandoffPlan $plan,
        public SourceFreezeCertificate $freeze,
        public string $snapshotToken,
        public string $snapshotDigest,
        public FeedSelection $selection,
        public FeedCursor $safeFeedCursor,
        public string $retentionLeaseRef,
        public DateTimeImmutable $retentionLeaseExpiresAt,
        array $checksums,
    ) {
        $selectionDigest = (new Codec)->digest(['scope' => $selection->scope->toArray(), 'eventContractRevision' => $selection->eventContractRevision,
            'envelopeSchemas' => $selection->envelopeSchemas, 'eventTypes' => $selection->eventTypes]);
        if ($plan->digest() !== $freeze->plan->digest() || $plan->source->feed->key() !== $selection->scope->key()
            || trim($snapshotToken) === '' || ! MigrationDigest::valid($snapshotDigest) || trim($retentionLeaseRef) === ''
            || trim($selection->subscriptionRef) === '' || trim($selection->eventContractRevision) === ''
            || $selection->selectionDigest !== $selectionDigest || trim($safeFeedCursor->token) === ''
            || ! self::isPinnedSet($selection->envelopeSchemas) || ! self::isPinnedSet($selection->eventTypes)
            || $checksums === [] || ! array_is_list($checksums)) {
            throw new InvalidArgumentException('Incomplete snapshot manifest or mismatched source scope');
        }
        $previous = '';
        foreach ($checksums as $summary) {
            if ($summary->sellerRef !== $plan->source->feed->sellerRef || strcmp($previous, $summary->currency) >= 0) {
                throw new InvalidArgumentException('Reconciliation scope or currency ordering mismatch');
            }
            $previous = $summary->currency;
        }
        $this->checksums = $checksums;
    }

    /** @var non-empty-list<ReconciliationChecksum> */
    public array $checksums;

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $body = $this->body();

        return [...$body, 'manifestDigest' => (new Codec)->digest($body)];
    }

    /** @return array<string,mixed> */
    private function body(): array
    {
        return ['schema' => 'billium.migration.manifest.v1', 'plan' => $this->plan->toArray(), 'sourceFreezeCertificate' => $this->freeze->toArray(),
            'snapshotToken' => $this->snapshotToken, 'snapshotDigest' => $this->snapshotDigest,
            'subscriptionRef' => $this->selection->subscriptionRef, 'eventContractRevision' => $this->selection->eventContractRevision,
            'selectionDigest' => $this->selection->selectionDigest, 'envelopeSchemas' => $this->selection->envelopeSchemas,
            'eventTypes' => $this->selection->eventTypes, 'safeFeedCursor' => ['token' => $this->safeFeedCursor->token, ...$this->safeFeedCursor->position->toArray()],
            'retentionLeaseRef' => $this->retentionLeaseRef, 'retentionLeaseExpiresAt' => $this->retentionLeaseExpiresAt->format('Y-m-d\TH:i:s.uP'),
            'perSellerCurrencyChecksums' => array_map(static fn (ReconciliationChecksum $summary): array => $summary->toArray(), $this->checksums)];
    }

    public function digest(): string
    {
        return (new Codec)->digest($this->body());
    }

    /** @param list<string> $refs */
    private static function isPinnedSet(array $refs): bool
    {
        $sorted = array_unique($refs);
        sort($sorted, SORT_STRING);

        return $refs !== [] && $refs === $sorted && ! array_any($refs, static fn (string $ref): bool => trim($ref) === '');
    }
}
