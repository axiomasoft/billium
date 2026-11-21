<?php

declare(strict_types=1);

namespace Billium\Server\Internal\Http;

use Billium\Contracts\Data\Envelope;
use Billium\Contracts\Data\Envelopes\AuthorizePayment;
use Billium\Contracts\Data\Envelopes\CancelOperation;
use Billium\Contracts\Data\Envelopes\CapturePayment;
use Billium\Contracts\Data\Envelopes\ExecutePayment;
use Billium\Contracts\Data\Envelopes\FindOperation;
use Billium\Contracts\Data\Envelopes\RequestRefund;
use Billium\Contracts\Data\Envelopes\VoidAuthorization;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Execution\Contracts\AuthorizationCommands;
use Billium\Execution\Contracts\ExecutionQueries;
use Billium\Execution\Contracts\RefundCommands;
use Billium\Execution\Contracts\RootPaymentCommands;
use Billium\Execution\Data\ExecutionReceipt;
use Billium\Execution\Data\ExecutionScope;
use Billium\Server\Data\ExecutionContext;
use Billium\Server\Internal\Operations\ServerEvents;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Packman\Attributes\Internal;

#[Internal]
final readonly class ExecutionController
{
    public function __construct(private Application $app, private RequestAccess $access, private EnvelopeResponse $response) {}

    public function execute(Request $request, string $sellerRef): JsonResponse
    {
        return $this->admit($request, $sellerRef, ExecutePayment::class, 'payment.execute',
            fn (ExecutionScope $scope, ExecutePayment $command): StoredOutcome => $this->app->make(RootPaymentCommands::class)->admit($scope, $command));
    }

    public function authorize(Request $request, string $sellerRef): JsonResponse
    {
        return $this->admit($request, $sellerRef, AuthorizePayment::class, 'payment.authorize',
            fn (ExecutionScope $scope, AuthorizePayment $command): StoredOutcome => $this->app->make(RootPaymentCommands::class)->admit($scope, $command));
    }

    public function refund(Request $request, string $sellerRef): JsonResponse
    {
        return $this->admit($request, $sellerRef, RequestRefund::class, 'payment.refund',
            fn (ExecutionScope $scope, RequestRefund $command): StoredOutcome => $this->app->make(RefundCommands::class)->admit($scope, $command));
    }

    public function cancel(Request $request, string $sellerRef, string $operationRef): JsonResponse
    {
        return $this->admit($request, $sellerRef, CancelOperation::class, 'payment.cancel',
            fn (ExecutionScope $scope, CancelOperation $command): StoredOutcome => $this->app->make(RootPaymentCommands::class)->cancel($scope, $command));
    }

    public function capture(Request $request, string $sellerRef): JsonResponse
    {
        return $this->admit($request, $sellerRef, CapturePayment::class, 'payment.capture',
            fn (ExecutionScope $scope, CapturePayment $command): StoredOutcome => $this->app->make(AuthorizationCommands::class)->admit($scope, $command));
    }

    public function voidAuthorization(Request $request, string $sellerRef): JsonResponse
    {
        return $this->admit($request, $sellerRef, VoidAuthorization::class, 'payment.void_authorization',
            fn (ExecutionScope $scope, VoidAuthorization $command): StoredOutcome => $this->app->make(AuthorizationCommands::class)->admit($scope, $command));
    }

    public function find(Request $request, string $sellerRef): JsonResponse
    {
        return $this->access->run($request, $sellerRef, [FindOperation::class], function (FindOperation $query, ExecutionContext $context): JsonResponse {
            $result = $this->app->make(ExecutionQueries::class)->find(self::scope($context), $query->operationRef);

            return $this->response->from($result);
        }, 'payment.find', read: true);
    }

    /**
     * @template T of Envelope
     *
     * @param  class-string<T>  $schema
     * @param  Closure(ExecutionScope, T): StoredOutcome  $handler
     */
    private function admit(Request $request, string $seller, string $schema, string $action, Closure $handler): JsonResponse
    {
        return $this->access->run($request, $seller, [$schema], function (Envelope $command, ExecutionContext $context) use ($handler): JsonResponse {
            $outcome = $handler(self::scope($context), $command);
            $receipt = ExecutionReceipt::admission($outcome);
            if ($outcome->replayed) {
                ServerEvents::ReceiptReplayed->record();
            }

            return $this->response->from($receipt, $receipt->result === 'accepted' ? 202 : 200);
        }, $action);
    }

    private static function scope(ExecutionContext $context): ExecutionScope
    {
        if ($context->intentOwnerNamespace === null || $context->intentOwnerNamespace === '') {
            throw new ProblemException(ProblemCode::AuthDenied);
        }

        return new ExecutionScope($context->installationRef, $context->sellerRef, $context->environment, $context->intentOwnerNamespace);
    }
}
