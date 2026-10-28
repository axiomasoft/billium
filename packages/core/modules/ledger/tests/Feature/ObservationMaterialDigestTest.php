<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Feature;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\PaymentObservation;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Core\Tests\Support\ObservationFactory;
use Billium\Ledger\Internal\Observation\EconomicDigest;
use Billium\Ledger\Tests\Observation\ObservationTestCase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use stdClass;

/**
 * D60 source-economic dedupe (INV-06, observation-apply): the ledger materialDigest is separate from the transport
 * contentDigest. Delivery identity, timestamps and evidence refs are custody metadata; operation binding, state,
 * currency, totals and the discrepancy identity are economic. Another delivery of the same snapshot has no second
 * effect; the same revision with another economic snapshot is quarantined, while the original stays untouched.
 */
#[Group('D60')]
final class ObservationMaterialDigestTest extends ObservationTestCase
{
    public function test_the_material_digest_ignores_custody_metadata_and_covers_every_economic_field(): void
    {
        $base = ObservationFactory::charge('op-1', 2, 1000, 100);
        $digest = self::material($base);

        foreach ([
            'eventId' => (string) Str::ulid(), 'sourceLineage' => 'L9', 'partitionRef' => 'part-9', 'sourceSequence' => 99,
            'evidenceRef' => 'ev-9', 'observedAt' => '2026-10-30T00:00:00Z', 'recordedAt' => '2026-10-30T00:00:01Z', 'providerOperationId' => 'psp-9',
        ] as $field => $value) {
            self::assertSame($digest, self::material(ObservationFactory::with($base, [$field => $value])), "{$field} is custody metadata");
        }
        foreach ([
            'operationRef' => 'op-2', 'operationRevision' => 3, 'state' => 'pending', 'businessIntentRef' => 'intent-b',
            'providerAccountRef' => 'pa-2', 'sourceAuthority' => 'standalone-billium', 'sellerRef' => 'seller-b', 'environment' => 'live',
            'installationRef' => 'inst-other', 'requested' => 2000, 'authorizedTotal' => 900, 'capturedTotal' => 900, 'refundedTotal' => 0,
            'discrepancy' => ['discrepancyRef' => 'disc-1', 'kind' => 'amount_mismatch', 'revision' => 1],
        ] as $field => $value) {
            self::assertNotSame($digest, self::material(ObservationFactory::with($base, [$field => $value])), "{$field} is economic");
        }
        $reported = ObservationFactory::with($base, ['discrepancy' => ['discrepancyRef' => 'disc-1', 'kind' => 'amount_mismatch', 'revision' => 1]]);
        self::assertNotSame(self::material($reported), self::material(ObservationFactory::with($reported, [
            'discrepancy' => ['discrepancyRef' => 'disc-1', 'kind' => 'amount_mismatch', 'revision' => 2],
        ])), 'the discrepancy has its own revision');
        self::assertSame(self::material($reported), self::material(ObservationFactory::with($reported, [
            'discrepancy' => ['discrepancyRef' => 'disc-1', 'kind' => 'amount_mismatch', 'revision' => 1, 'verifiedExternalFacts' => [['ref' => 'x']]],
        ])), 'verified external facts are evidence');
        self::assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/', $digest);
        self::assertNotSame($base->contentDigest, $digest, 'the economic digest is not the transport digest');
    }

    public function test_another_delivery_of_the_same_snapshot_adds_evidence_but_no_effect(): void
    {
        $this->debt('o1', 1000);
        $this->intent('intent-a', ['o1']);
        $original = ObservationFactory::charge('op-1', 1, 600, sequence: 1);
        $first = $this->observe($original);
        $other = ObservationFactory::with($original, ['eventId' => (string) Str::ulid(), 'sourceSequence' => 2, 'evidenceRef' => 'ev-second',
            'recordedAt' => '2026-10-29T12:00:00Z']);

        $second = $this->observe($other);

        self::assertSame($first->receiptRef, $second->receiptRef);
        self::assertSame(1, $this->rows('billium_allocation'));
        self::assertSame(1, $this->rows('billium_observed_payment_revision'));
        self::assertSame(2, $this->rows('billium_inbox_message', "status = 'applied'"), 'both custody records exist, one economic effect');
    }

    public function test_a_changed_economic_snapshot_of_the_same_revision_is_quarantined(): void
    {
        $this->debt('o1', 1000);
        $this->intent('intent-a', ['o1']);
        $original = ObservationFactory::charge('op-1', 1, 600, sequence: 1);
        $this->observe($original);

        $state = ObservationFactory::with($original, ['eventId' => (string) Str::ulid(), 'sourceSequence' => 2, 'state' => 'pending']);
        self::assertSame(ProblemCode::ObservationConflict->value, $this->attempt($state));
        // The same event identity with another transport digest is preserved apart and refused as well (REQ-FAIL-04).
        $sameEvent = ObservationFactory::with($original, ['capturedTotal' => 700, 'authorizedTotal' => 700, 'requested' => 700]);
        self::assertSame(ProblemCode::ObservationConflict->value, $this->attempt($sameEvent));

        self::assertSame(600, $this->allocated('o1'));
        self::assertSame(1, $this->rows('billium_inbox_conflict'));
        self::assertSame('quarantined', $this->inboxStatus(self::eventId($state)));
        self::assertSame([600, 'succeeded'], [self::integer($this->observedRow()['captured_minor']), $this->observedRow()['state']]);
    }

    private static function material(stdClass $envelope): string
    {
        $codec = new Codec;
        $observation = $codec->decode($codec->canonicalize($envelope));
        self::assertInstanceOf(PaymentObservation::class, $observation);

        return EconomicDigest::of($observation);
    }
}
