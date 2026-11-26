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
        $status = HttpContract::PROBLEM_HTTP[$code->value];

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
