<?php

declare(strict_types=1);

namespace Billium\Server\Internal\Http;

use Billium\Contracts\Data\Envelopes\BilliumQuery;
use Billium\Contracts\Data\Envelopes\PaymentObservation;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Ledger\Contracts\LedgerQueries;
use Billium\Ledger\Contracts\ObservationApplier;
use Billium\Server\Internal\Readiness;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Packman\Attributes\Internal;

#[Internal]
final readonly class LedgerController
{
    public function __construct(private Application $app, private RequestAccess $access, private Readiness $readiness) {}

    public function query(Request $request, string $seller): JsonResponse
    {
        if (! $this->app->bound(LedgerQueries::class)) {
            return ProblemResponse::from(ProblemCode::CapabilityUnsupported);
        }

        return $this->access->run(
            $request,
            $seller,
            [BilliumQuery::class],
            fn (): JsonResponse => new JsonResponse($this->app->make(LedgerQueries::class)->dispatch($request->getContent()), headers: ['Cache-Control' => 'no-store']),
        );
    }

    public function observe(Request $request, string $seller): JsonResponse
    {
        if (! $this->app->bound(ObservationApplier::class) || ! $this->readiness->acceptsObservations()) {
            return ProblemResponse::from(ProblemCode::CapabilityUnsupported);
        }

        return $this->access->run($request, $seller, [PaymentObservation::class], function () use ($request): JsonResponse {
            $receipt = $this->app->make(ObservationApplier::class)->apply($request->getContent());

            return new JsonResponse($receipt->body, headers: ['Cache-Control' => 'no-store']);
        });
    }
}
