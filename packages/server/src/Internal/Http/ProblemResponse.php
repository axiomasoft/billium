<?php

declare(strict_types=1);

namespace Billium\Server\Internal\Http;

use Billium\Contracts\Enums\ProblemCode;
use Illuminate\Http\JsonResponse;
use Packman\Attributes\Internal;

#[Internal]
final readonly class ProblemResponse
{
    public static function from(ProblemCode $code): JsonResponse
    {
        $status = match ($code) {
            ProblemCode::AuthUnauthenticated => 401,
            ProblemCode::AuthDenied => 403,
            ProblemCode::ValidationInvalid, ProblemCode::MoneyScaleInvalid, ProblemCode::SchemaUnsupported => 400,
            ProblemCode::ReferenceUnknown => 404,
            ProblemCode::TransportUnavailable, ProblemCode::LookupUnavailable, ProblemCode::RecoveryRequired => 503,
            default => 409,
        };

        return new JsonResponse([
            'code' => $code->value,
            'title' => $code->value,
            'status' => $status,
            'category' => $code->category(),
            'retryable' => $code->retryable(),
            'sameKey' => $code->sameKey(),
            'receipt' => $code->receipt(),
        ], $status, ['Content-Type' => 'application/problem+json', 'Cache-Control' => 'no-store']);
    }
}
