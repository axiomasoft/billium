<?php

declare(strict_types=1);

namespace Billium\Server\Internal\Http;

use Billium\Contracts\Data\Envelopes\BilliumQuery;
use Billium\Contracts\Data\Envelopes\ObservationCustody;
use Billium\Contracts\Data\Envelopes\PaymentObservation;
use Billium\Contracts\Data\Envelopes\ReallocatePayment as ReallocatePaymentEnvelope;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Ledger\Contracts\Ledger;
use Billium\Ledger\Contracts\LedgerQueries;
use Billium\Ledger\Contracts\ObservationApplier;
use Billium\Ledger\Data\Allocations\AllocationReceipt;
use Billium\Ledger\Data\Allocations\ReallocatePayment;
use Billium\Ledger\Data\Allocations\ReallocationMode;
use Billium\Server\Data\ExecutionContext;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Packman\Attributes\Internal;

#[Internal]
final readonly class LedgerController
{
    public function __construct(
        private Application $app,
        private RequestAccess $access,
        private EnvelopeResponse $response,
        private ReceiptWire $receipts,
    ) {}

    public function query(Request $request, string $sellerRef): JsonResponse
    {
        return $this->access->run(
            $request,
            $sellerRef,
            [BilliumQuery::class],
            function (BilliumQuery $query) use ($request): JsonResponse {
                $schema = HttpContract::QUERY_RESULTS[$query->query] ?? throw new ProblemException(ProblemCode::ModuleUnavailable);
                $body = $this->app->make(LedgerQueries::class)->dispatch($request->getContent());

                return $this->response->fromBody($body + ['schema' => $schema], digest: ! isset($body['schema']));
            },
            'ledger.query',
            read: true,
        );
    }

    public function observe(Request $request, string $sellerRef): JsonResponse
    {
        return $this->access->run($request, $sellerRef, [PaymentObservation::class], function (PaymentObservation $command, ExecutionContext $context) use ($request): JsonResponse {
            $receipt = $this->app->make(ObservationApplier::class)->applyWithCustody($request->getContent());
            if ($receipt instanceof ObservationCustody) {
                return $this->response->from($receipt, 202);
            }

            return $this->response->fromBody($this->receipts->allocation($context, $receipt));
        }, 'payment.observe');
    }

    public function reallocate(Request $request, string $sellerRef): JsonResponse
    {
        return $this->access->run($request, $sellerRef, [ReallocatePaymentEnvelope::class], function (ReallocatePaymentEnvelope $command, ExecutionContext $context): JsonResponse {
            $receipt = $this->app->make(Ledger::class)->reallocatePayment(new ReallocatePayment(
                $command->commandKey, $command->installationRef, $command->sellerRef, $command->environment,
                $command->paymentRef, $command->policyVersion, ReallocationMode::from($command->mode),
            ))->orThrow();
            $ref = $receipt->body['receipt_ref'] ?? null;
            if (! is_string($ref) || $receipt->receiptClass !== 'ledger.reallocation') {
                throw new LogicException('Missing reallocation receipt identity');
            }

            $allocation = new AllocationReceipt($ref, 'reallocation', $receipt->body, $receipt->replayed);

            return $this->response->fromBody($this->receipts->allocation($context, $allocation));
        }, 'allocation.reallocate');
    }
}
