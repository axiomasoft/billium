<?php

declare(strict_types=1);

namespace Billium\Platform\Contracts\Outbox;

use Billium\Platform\Data\Feed\FeedScope;
use Billium\Platform\Data\Outbox\OutboxDeliveryStatus;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** Exact authenticated feed scope; returned status contains no transferable claim token. */
#[Spi(kind: SpiKind::Call, since: '1.0')]
interface OutboxDeliveryQueries
{
    /** @return list<OutboxDeliveryStatus> */
    public function due(FeedScope $scope, string $destinationRef, int $limit = 100): array;

    /** @return list<OutboxDeliveryStatus> */
    public function byEvent(FeedScope $scope, string $eventId, int $limit = 100): array;
}
