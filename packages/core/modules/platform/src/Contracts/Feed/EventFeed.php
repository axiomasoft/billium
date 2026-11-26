<?php

declare(strict_types=1);

namespace Billium\Platform\Contracts\Feed;

use Billium\Contracts\Data\Envelopes\FeedBootstrap as WireBootstrap;
use Billium\Contracts\Data\Envelopes\FeedBootstrapRequest;
use Billium\Contracts\Data\Envelopes\FeedOpen;
use Billium\Contracts\Data\Envelopes\FeedSelection as WireSelection;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Platform\Data\Feed\FeedBootstrap;
use Billium\Platform\Data\Feed\FeedPage;
use Billium\Platform\Data\Feed\FeedScope;
use Billium\Platform\Data\Feed\FeedSelection;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** D52/D60/D63. Feed position is never a database freshness certificate. */
#[Spi(kind: SpiKind::Call, since: '1.0')]
interface EventFeed
{
    /**
     * @param  list<string>  $envelopeSchemas
     * @param  list<string>  $eventTypes
     */
    public function openFeed(FeedScope $scope, string $eventContractRevision, array $envelopeSchemas, array $eventTypes, bool $required = false): FeedSelection;

    public function openSelectedFeed(FeedOpen $command): WireSelection;

    /** Get the complete pinned selection by scoped reference and digest without opening or advancing it. */
    public function findSelection(FinancialScope $scope, string $subscriptionRef, string $selectionDigest): FeedSelection;

    public function read(FeedSelection $selection, ?string $cursor = null, int $limit = 100): FeedPage;

    public function bootstrap(FeedSelection $selection): FeedBootstrap;

    public function bootstrapSelectedFeed(FeedBootstrapRequest $command): WireBootstrap;

    /** Receiver calls only after verified staged items have durable dispositions and atomic cursor adoption. */
    public function acknowledgeBootstrap(FeedSelection $selection, string $leaseRef, string $snapshotDigest): void;

    /** Advance an explicitly required consumer's floor only after durable custody. */
    public function acknowledgeCursor(FeedSelection $selection, string $cursor): void;

    /** Release the subscription: its floor and bootstrap pins stop holding the outbox tail. Irreversible; open a new feed to resume. */
    public function closeFeed(FeedSelection $selection): void;
}
