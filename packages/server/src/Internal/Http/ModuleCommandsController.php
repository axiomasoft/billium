<?php

declare(strict_types=1);

namespace Billium\Server\Internal\Http;

use Billium\Collection\Contracts\DunningCaseService;
use Billium\Collection\Contracts\PinnedCollectionInputs;
use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\AuthorizeCollection;
use Billium\Contracts\Data\Envelopes\EnsureBillingOccurrence;
use Billium\Contracts\Data\Envelopes\PrepareObligation;
use Billium\Contracts\Data\Envelopes\UsageFact;
use Billium\Contracts\Data\Envelopes\UsageFactV2;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Rating\Contracts\PinnedRatingInputs;
use Billium\Rating\Contracts\RatingBooking;
use Billium\Schedule\Contracts\OccurrenceService;
use Billium\Schedule\Contracts\PinnedOccurrenceInputs;
use Billium\Usage\Contracts\Usage;
use Billium\Usage\Contracts\UsageFactV1Attribution;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Packman\Attributes\Internal;

#[Internal]
final readonly class ModuleCommandsController
{
    /** @var array<string, class-string> */
    public const array HOST_INPUTS = [
        'billium.server.rating.prepare' => PinnedRatingInputs::class,
        'billium.server.schedule.ensure' => PinnedOccurrenceInputs::class,
        'billium.server.collection.authorize' => PinnedCollectionInputs::class,
        'billium.server.usage.accept' => UsageFactV1Attribution::class,
    ];

    public function __construct(private Application $app, private RequestAccess $access, private EnvelopeResponse $response) {}

    public function prepare(Request $request, string $sellerRef): JsonResponse
    {
        return $this->access->run($request, $sellerRef, [PrepareObligation::class], fn (PrepareObligation $command): JsonResponse => $this->response->from($this->app->make(RatingBooking::class)->prepareObligation($command, $this->input(PinnedRatingInputs::class))), 'obligation.prepare');
    }

    public function ensure(Request $request, string $sellerRef): JsonResponse
    {
        return $this->access->run($request, $sellerRef, [EnsureBillingOccurrence::class], fn (EnsureBillingOccurrence $command): JsonResponse => $this->response->from($this->app->make(OccurrenceService::class)->ensureBillingOccurrence($command, $this->input(PinnedOccurrenceInputs::class))), 'occurrence.ensure');
    }

    public function authorize(Request $request, string $sellerRef): JsonResponse
    {
        return $this->access->run($request, $sellerRef, [AuthorizeCollection::class], fn (AuthorizeCollection $command): JsonResponse => $this->response->from($this->app->make(DunningCaseService::class)->authorizePinnedCollection($command, $this->input(PinnedCollectionInputs::class))), 'collection.authorize');
    }

    public function usage(Request $request, string $sellerRef): JsonResponse
    {
        return $this->access->run(
            $request,
            $sellerRef,
            [UsageFact::class],
            fn (UsageFact $fact): JsonResponse => $this->response->from($this->app->make(Usage::class)->acceptUsageFact($fact, $this->input(UsageFactV1Attribution::class))),
            'usage.accept',
            codec: Codec::forRevision('2026-10'),
        );
    }

    public function usageV2(Request $request, string $sellerRef): JsonResponse
    {
        return $this->access->run(
            $request,
            $sellerRef,
            [UsageFactV2::class],
            fn (UsageFactV2 $fact): JsonResponse => $this->response->from($this->app->make(Usage::class)->submitUsageFact($fact)),
            'usage.accept.v2',
            codec: Codec::forRevision('2026-12'),
        );
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $port
     * @return T
     */
    private function input(string $port): object
    {
        if (! $this->app->bound($port)) {
            throw new ProblemException(ProblemCode::ModuleUnavailable);
        }

        $input = $this->app->make($port);
        if (! $input instanceof $port) {
            throw new LogicException('Invalid host input binding for '.$port);
        }

        return $input;
    }
}
