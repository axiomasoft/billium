<?php

declare(strict_types=1);

namespace Billium\Usage\Tests\Unit;

use Billium\Contracts\Exceptions\ProblemException;
use Billium\Usage\Enums\CompletenessStatus;
use Billium\Usage\Internal\Canonical\CanonicalJson;
use Billium\Usage\Internal\Storage\AggregationRows;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AggregationRowsTest extends TestCase
{
    public function test_stored_frontier_entry_round_trips(): void
    {
        $entry = AggregationRows::completeness(CanonicalJson::encode(self::entry()));

        self::assertSame('meter', $entry->sourceRef);
        self::assertSame(CompletenessStatus::Complete, $entry->status);
        self::assertNull($entry->repairOrReplayRef);
    }

    /** @return iterable<string, array{0: string}> */
    public static function members(): iterable
    {
        foreach (array_keys(self::entry()) as $key) {
            yield $key => [$key];
        }
    }

    #[DataProvider('members')]
    public function test_stored_frontier_entry_without_a_member_is_reported_as_corrupt_evidence(string $key): void
    {
        $entry = self::entry();
        unset($entry[$key]);

        $this->expectException(ProblemException::class);
        $this->expectExceptionMessage('billium.usage.stored_evidence_invalid');
        AggregationRows::completeness($entry);
    }

    public function test_stored_frontier_entry_with_unknown_status_is_reported_as_corrupt_evidence(): void
    {
        $this->expectException(ProblemException::class);
        $this->expectExceptionMessage('billium.usage.stored_evidence_invalid');
        AggregationRows::completeness([...self::entry(), 'status' => 'bogus']);
    }

    /** @return array<string, int|string|null> */
    private static function entry(): array
    {
        $payload = [
            'sourceRef' => 'meter', 'evidenceSeq' => 1, 'status' => CompletenessStatus::Complete->value,
            'rangeStartMicros' => 0, 'rangeEndMicros' => 10, 'watermarkMicros' => 10, 'repairOrReplayRef' => null,
        ];

        return [...$payload, 'evidenceDigest' => CanonicalJson::digest($payload)];
    }
}
