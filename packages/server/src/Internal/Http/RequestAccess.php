<?php

declare(strict_types=1);

namespace Billium\Server\Internal\Http;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelope;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Server\Contracts\Authenticator;
use Billium\Server\Contracts\AuthorizationVerdict;
use Billium\Server\Contracts\InstallationContextResolver;
use Billium\Server\Contracts\SellerContextResolver;
use Billium\Server\Contracts\SellerEligibility;
use Billium\Server\Data\AuthenticatedPrincipal;
use Billium\Server\Data\ExecutionContext;
use Billium\Server\Data\InstallationContext;
use Billium\Server\Data\RequestMeta;
use Billium\Server\Enums\Verdict;
use Billium\Server\Internal\Auth\RequestScope;
use Billium\Server\Internal\Readiness;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;
use Packman\Attributes\Internal;
use PDOException;

#[Internal]
final readonly class RequestAccess
{
    public function __construct(
        private Application $app,
        private Readiness $readiness,
        private Codec $codec,
        private BilliumOptions $options,
        private HttpLimits $limits,
    ) {}

    /**
     * @template T of Envelope
     *
     * @param  non-empty-list<class-string<T>>  $allowed
     * @param  Closure(T, ExecutionContext): JsonResponse  $consume
     */
    public function run(Request $request, string $seller, array $allowed, Closure $consume, ?string $action = null, bool $read = false): JsonResponse
    {
        try {
            if (! $this->readiness->hasAuthPorts()) {
                throw new ProblemException(ProblemCode::TransportUnavailable);
            }
            $principal = $this->authenticate($request);
            $context = $this->app->make(SellerContextResolver::class)->resolve($principal, $seller);
            if ($context->sellerRef !== $seller
                || ($this->options->installationRef !== null && $this->options->installationRef !== $context->installationRef)) {
                throw new ProblemException(ProblemCode::AuthDenied);
            }

            $envelope = $this->decode($request);
            if (! in_array($envelope::class, $allowed, true)) {
                throw new ProblemException(ProblemCode::ValidationInvalid);
            }
            /** @var T $envelope */
            $scope = RequestScope::select($context, $envelope, $request->route('operationRef'));
            $caller = RequestScope::caller($context, $envelope);
            $sellerScope = $context->sellerScope();
            $admission = $this->app->make(SellerEligibility::class)->admission($sellerScope);
            if ($admission === null || ! $admission->eligible || ! $sellerScope->matches($admission->scope)) {
                throw new ProblemException(ProblemCode::AuthDenied);
            }

            $verdict = $this->app->make(AuthorizationVerdict::class)->authorize($principal, $action ?? $envelope->schema, $scope, $this->codec->digestOf($envelope));
            if ($verdict !== Verdict::Allow) {
                throw new ProblemException(ProblemCode::AuthDenied);
            }
            if (! $this->readiness->ready($read)) {
                throw new ProblemException(ProblemCode::TransportUnavailable);
            }

            return RequestCaller::run($request, $caller, static fn (): JsonResponse => $consume($envelope, $context));
        } catch (ProblemException $refusal) {
            return ProblemResponse::from($refusal->problem === ProblemCode::ReferenceUnknown ? ProblemCode::AuthDenied : $refusal->problem);
        } catch (PDOException $failure) {
            $state = (string) $failure->getCode();
            if (! str_starts_with($state, '08') && ! in_array($state, ['57014', '57P01', '57P02', '57P03'], true)) {
                throw $failure;
            }
            report($failure);

            return ProblemResponse::from(ProblemCode::TransportUnavailable);
        }
    }

    private function decode(Request $request): Envelope
    {
        $body = $request->getContent();
        $type = strtolower(trim(explode(';', $request->headers->get('Content-Type') ?? '', 2)[0]));
        if ($type !== 'application/json' || strlen($body) > $this->limits->maxBodyBytes) {
            throw new ProblemException(ProblemCode::ValidationInvalid);
        }
        try {
            json_decode($body, false, $this->limits->maxDepth + 1, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ProblemException(ProblemCode::ValidationInvalid);
        }
        $envelope = $this->codec->decode($body);
        $header = $request->headers->all('Idempotency-Key');
        if ($header !== [] && (! is_string($envelope->raw->commandKey ?? null) || $header !== [$envelope->raw->commandKey])) {
            throw new ProblemException(ProblemCode::ValidationInvalid);
        }

        return $envelope;
    }

    public function installation(Request $request): InstallationContext
    {
        $principal = $this->authenticate($request);
        if (! $this->app->bound(InstallationContextResolver::class)) {
            throw new ProblemException(ProblemCode::TransportUnavailable);
        }
        $context = $this->app->make(InstallationContextResolver::class)->resolve($principal);
        if ($context === null || ($this->options->installationRef !== null && $this->options->installationRef !== $context->installationRef)) {
            throw new ProblemException(ProblemCode::AuthDenied);
        }

        return $context;
    }

    private function authenticate(Request $request): AuthenticatedPrincipal
    {
        if (! $this->app->bound(Authenticator::class)) {
            throw new ProblemException(ProblemCode::TransportUnavailable);
        }

        return $this->app->make(Authenticator::class)->authenticate(
            $request->headers->all(),
            new RequestMeta($request->method(), $request->path(), $request->ip()),
        );
    }
}
