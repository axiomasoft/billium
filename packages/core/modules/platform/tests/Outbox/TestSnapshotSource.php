<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Outbox;

use Billium\Contracts\Contracts\Codec;
use Billium\Platform\Contracts\Feed\SnapshotSource;
use Billium\Platform\Data\Feed\FeedSelection;
use Billium\Platform\Internal\Installation\Rows;
use Billium\Platform\Tests\PlatformTestCase;
use Closure;

final class TestSnapshotSource implements SnapshotSource
{
    public int $calls = 0;

    public ?Closure $duringSnapshot = null;

    public function __construct(private readonly string $sourceKey = 'ledger.obligations') {}

    public function key(): string
    {
        return $this->sourceKey;
    }

    public function snapshot(FeedSelection $selection, string $connectionName): array
    {
        $this->calls++;
        ($this->duringSnapshot ?? static fn () => null)();
        $rows = PlatformTestCase::connection($connectionName)->select('SELECT id FROM billium_test_effect WHERE seller_ref = ? AND environment = ? ORDER BY id', [$selection->scope->sellerRef, $selection->scope->environment]);

        return array_values(array_map(static function (mixed $row): array {
            $ref = 'obligation:'.Rows::int(Rows::field($row, 'id'));

            return ['operationRef' => $ref, 'revision' => 1, 'materialDigest' => (new Codec)->digest(['ref' => $ref])];
        }, $rows));
    }
}
