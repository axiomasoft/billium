<?php

declare(strict_types=1);

namespace Billium\Server\Internal\Http;

use Billium\Contracts\Data\Envelopes\AllocationReceiptResult;
use Billium\Contracts\Data\Envelopes\FeedBootstrapRequest;
use Billium\Contracts\Data\Envelopes\FeedOpen;
use Billium\Contracts\Data\Envelopes\FeedRead;
use Billium\Contracts\Data\Envelopes\FindReceipt;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Ledger\Contracts\LedgerQueries;
use Billium\Platform\Contracts\Feed\EventFeed;
use Billium\Platform\Contracts\Idempotency\IdempotencyReceiptQueries;
use Billium\Platform\Contracts\Idempotency\ReceiptAbsenceAuthority;
use Billium\Server\Data\ExecutionContext;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Packman\Attributes\Internal;

#[Internal]
final readonly class RecoveryController
{
    public function __construct(
        private Application $app,
        private RequestAccess $access,
        private EnvelopeResponse $response,
        private ReceiptWire $receipts,
    ) {}

    public function findReceipt(Request $request, string $sellerRef): JsonResponse
    {
        return $this->access->run($request, $sellerRef, [FindReceipt::class], function (FindReceipt $query, ExecutionContext $context): JsonResponse {
            $allocation = $this->app->bound(LedgerQueries::class) ? $this->app->make(LedgerQueries::class)->allocationReceipt($query->receiptRef) : null;
            if ($allocation !== null) {
                return $this->response->fromBody([
                    'schema' => FindReceipt::SCHEMA,
                    'installationRef' => $context->installationRef,
                    'sellerRef' => $context->sellerRef,
                    'environment' => $context->environment,
                    'receiptRef' => $query->receiptRef,
                    'status' => 'found',
                    'receiptSchema' => AllocationReceiptResult::SCHEMA,
                    'body' => $this->receipts->allocation($context, $allocation),
                ], digest: true);
            }
            $authority = $this->app->bound(ReceiptAbsenceAuthority::class) ? $this->app->make(ReceiptAbsenceAuthority::class) : null;
            $result = $this->app->make(IdempotencyReceiptQueries::class)->find(self::scope($context), $query, $authority);

            return $this->response->from($result);
        }, 'receipt.find', read: true);
    }

    public function openFeed(Request $request, string $sellerRef): JsonResponse
    {
        return $this->access->run($request, $sellerRef, [FeedOpen::class], fn (FeedOpen $command): JsonResponse => $this->response->from($this->app->make(EventFeed::class)->openSelectedFeed($command)), 'feed.open');
    }

    public function readFeed(Request $request, string $sellerRef): JsonResponse
    {
        return $this->access->run($request, $sellerRef, [FeedRead::class], function (FeedRead $query, ExecutionContext $context): JsonResponse {
            $feed = $this->app->make(EventFeed::class);
            $selection = $feed->findSelection(self::scope($context), $query->subscriptionRef, $query->selectionDigest);

            return $this->response->fromBody($feed->read($selection, $query->cursor, $query->limit ?? 100)->toArray());
        }, 'feed.read', read: true);
    }

    public function bootstrapFeed(Request $request, string $sellerRef): JsonResponse
    {
        return $this->access->run($request, $sellerRef, [FeedBootstrapRequest::class], fn (FeedBootstrapRequest $command): JsonResponse => $this->response->from($this->app->make(EventFeed::class)->bootstrapSelectedFeed($command)), 'feed.bootstrap');
    }

    private static function scope(ExecutionContext $context): FinancialScope
    {
        return new FinancialScope($context->installationRef, $context->sellerRef, $context->environment, null);
    }
}
