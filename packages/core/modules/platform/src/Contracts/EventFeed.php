<?php

declare(strict_types=1);

namespace Billium\Platform\Contracts;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Attributes\Spi;
use Billium\Contracts\Attributes\SpiKind;
use Billium\Platform\Data\Feed\FeedBootstrap;
use Billium\Platform\Data\Feed\FeedPage;
use Billium\Platform\Data\Feed\FeedScope;
use Billium\Platform\Data\Feed\FeedSelection;

/** D52/D60/D63. Feed position is never a database freshness certificate. */
#[Api]
#[Spi(SpiKind::Call)]
interface EventFeed
{
    /**
     * @param  list<string>  $envelopeSchemas
     * @param  list<string>  $eventTypes
     */
    public function openFeed(FeedScope $scope, string $eventContractRevision, array $envelopeSchemas, array $eventTypes, bool $required = false): FeedSelection;

    public function read(FeedSelection $selection, ?string $cursor = null, int $limit = 100): FeedPage;

    public function bootstrap(FeedSelection $selection): FeedBootstrap;

    /** Receiver calls only after verified staged items have durable dispositions and atomic cursor adoption. */
    public function acknowledgeBootstrap(FeedSelection $selection, string $leaseRef, string $snapshotDigest): void;

    /** Advance an explicitly required consumer's floor only after durable custody. */
    public function acknowledgeCursor(FeedSelection $selection, string $cursor): void;
}
