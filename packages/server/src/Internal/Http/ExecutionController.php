<?php

declare(strict_types=1);

namespace Billium\Server\Internal\Http;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\AuthorizePayment;
use Billium\Contracts\Data\Envelopes\CancelOperation;
use Billium\Contracts\Data\Envelopes\CapturePayment;
use Billium\Contracts\Data\Envelopes\ExecutePayment;
use Billium\Contracts\Data\Envelopes\FindOperation;
use Billium\Contracts\Data\Envelopes\RequestRefund;
use Billium\Contracts\Data\Envelopes\VoidAuthorization;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Execution\Contracts\AuthorizationCommands;
use Billium\Execution\Contracts\ExecutionQueries;
use Billium\Execution\Contracts\RefundCommands;
use Billium\Execution\Contracts\RootPaymentCommands;
use Billium\Execution\Data\ExecutionScope;
use Billium\Server\Data\ExecutionContext;
use Billium\Server\Internal\Readiness;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Packman\Attributes\Internal;

#[Internal]
final readonly class ExecutionController
{
    public function __construct(
        private Application $app,
        private RequestAccess $access,
        private Readiness $readiness,
        private Codec $codec,
    ) {}

    public function command(Request $request, string $seller): JsonResponse
    {
        if (! $this->readiness->hasExecutionDelivery()) {
            return ProblemResponse::from(ProblemCode::CapabilityUnsupported);
        }

        return $this->access->run(
            $request,
            $seller,
            [ExecutePayment::class, AuthorizePayment::class, CancelOperation::class, CapturePayment::class, VoidAuthorization::class, RequestRefund::class],
            function (ExecutePayment|AuthorizePayment|CancelOperation|CapturePayment|VoidAuthorization|RequestRefund $command, ExecutionContext $context): JsonResponse {
                $scope = self::scope($context);
                $outcome = match (true) {
                    $command instanceof ExecutePayment, $command instanceof AuthorizePayment => $this->app->make(RootPaymentCommands::class)->admit($scope, $command),
                    $command instanceof CancelOperation => $this->app->make(RootPaymentCommands::class)->cancel($scope, $command),
                    $command instanceof CapturePayment, $command instanceof VoidAuthorization => $this->app->make(AuthorizationCommands::class)->admit($scope, $command),
                    $command instanceof RequestRefund => $this->app->make(RefundCommands::class)->admit($scope, $command),
                };

                return new JsonResponse($outcome->orThrow()->body, headers: ['Cache-Control' => 'no-store']);
            },
        );
    }

    public function find(Request $request, string $seller): JsonResponse
    {
        if (! $this->readiness->hasExecutionDelivery()) {
            return ProblemResponse::from(ProblemCode::CapabilityUnsupported);
        }

        return $this->access->run($request, $seller, [FindOperation::class], function (FindOperation $command, ExecutionContext $context): JsonResponse {
            $found = $this->app->make(ExecutionQueries::class)->find(self::scope($context), $command->operationRef);

            return JsonResponse::fromJsonString($this->codec->encode($found), headers: ['Cache-Control' => 'no-store']);
        });
    }

    private static function scope(ExecutionContext $context): ExecutionScope
    {
        if ($context->intentOwnerNamespace === null || $context->intentOwnerNamespace === '') {
            throw new ProblemException(ProblemCode::AuthDenied);
        }

        return new ExecutionScope($context->installationRef, $context->sellerRef, $context->environment, $context->intentOwnerNamespace);
    }
}
