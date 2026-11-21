<?php

declare(strict_types=1);

namespace Billium\Server\Internal\Http;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelope;
use Billium\Contracts\Data\Envelopes\BilliumQuery;
use Billium\Contracts\Data\Envelopes\PaymentObservation;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Data\Runtime\Caller;
use Billium\Server\Contracts\Authenticator;
use Billium\Server\Contracts\AuthorizationVerdict;
use Billium\Server\Contracts\SellerContextResolver;
use Billium\Server\Data\ExecutionContext;
use Billium\Server\Data\RequestMeta;
use Billium\Server\Data\ResourceScope;
use Billium\Server\Enums\Verdict;
use Billium\Server\Internal\Readiness;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Packman\Attributes\Internal;

#[Internal]
final readonly class RequestAccess
{
    public function __construct(private Application $app, private Readiness $readiness, private Codec $codec) {}

    /**
     * @template T of Envelope
     *
     * @param  non-empty-list<class-string<T>>  $allowed
     * @param  Closure(T, ExecutionContext): JsonResponse  $consume
     */
    public function run(Request $request, string $seller, array $allowed, Closure $consume): JsonResponse
    {
        try {
            if (! $this->app->bound(Authenticator::class) || ! $this->app->bound(SellerContextResolver::class)
                || ! $this->app->bound(AuthorizationVerdict::class)) {
                throw new ProblemException(ProblemCode::TransportUnavailable);
            }
            $principal = $this->app->make(Authenticator::class)->authenticate(
                $request->headers->all(),
                new RequestMeta($request->method(), $request->path(), $request->ip()),
            );
            $context = $this->app->make(SellerContextResolver::class)->resolve($principal, $seller);
            if ($context->sellerRef !== $seller) {
                throw new ProblemException(ProblemCode::AuthDenied);
            }

            $envelope = $this->codec->decode($request->getContent());
            if (! in_array($envelope::class, $allowed, true)) {
                throw new ProblemException(ProblemCode::ValidationInvalid);
            }
            /** @var T $envelope */
            if (($envelope->raw->sellerRef ?? null) !== $context->sellerRef
                || ($envelope->raw->environment ?? null) !== $context->environment
                || ($envelope->raw->installationRef ?? null) !== $context->installationRef) {
                throw new ProblemException(ProblemCode::AuthDenied);
            }

            $authorities = $this->sourceAuthorities($context, $envelope);
            $scope = new ResourceScope($context->installationRef, $context->environment, $context->sellerRef, self::selectors($envelope));
            $verdict = $this->app->make(AuthorizationVerdict::class)->authorize($principal, $envelope->schema, $scope, $this->codec->digestOf($envelope));
            if ($verdict !== Verdict::Allow) {
                throw new ProblemException(ProblemCode::AuthDenied);
            }
            if (! $this->readiness->ready()) {
                throw new ProblemException(ProblemCode::TransportUnavailable);
            }

            $caller = new Caller($context->sellerRef, $context->environment, $context->installationRef, $context->actorRef,
                $authorities, $context->intentOwnerNamespace === null ? [] : [$context->intentOwnerNamespace], $context->permissions);

            return RequestCaller::run($request, $caller, static fn (): JsonResponse => $consume($envelope, $context));
        } catch (ProblemException $refusal) {
            return ProblemResponse::from($refusal->problem);
        }
    }

    /** @return array<string, mixed> */
    private static function selectors(Envelope $envelope): array
    {
        if ($envelope instanceof BilliumQuery) {
            return ['query' => $envelope->query, 'selector' => get_object_vars($envelope->selector)];
        }

        return array_intersect_key(get_object_vars($envelope->raw), array_flip([
            'operationRef', 'originalOperationRef', 'originalPaymentRef', 'merchantAccountRef', 'providerAccountRef',
            'eventId', 'sourceAuthority', 'sourceLineage', 'partitionRef', 'businessIntentRef',
        ]));
    }

    /** @return list<string> */
    private function sourceAuthorities(ExecutionContext $context, Envelope $envelope): array
    {
        $authorities = [];
        foreach ($context->sourceAuthorities as $grant) {
            if ($grant->channel !== 'payment.observation' || $grant->installationRef !== $context->installationRef
                || $grant->environment !== $context->environment || $grant->sellerRef !== $context->sellerRef) {
                continue;
            }
            if ($envelope instanceof PaymentObservation) {
                if ($grant->sourceAuthority === $envelope->sourceAuthority && $grant->sourceLineage === $envelope->sourceLineage
                    && in_array($envelope->partitionRef, $grant->partitionRefs, true)) {
                    return [$grant->sourceAuthority];
                }
            } else {
                $authorities[] = $grant->sourceAuthority;
            }
        }
        if ($envelope instanceof PaymentObservation) {
            throw new ProblemException(ProblemCode::AuthDenied);
        }

        return array_values(array_unique($authorities));
    }
}
