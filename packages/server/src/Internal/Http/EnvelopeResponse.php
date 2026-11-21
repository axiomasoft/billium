<?php

declare(strict_types=1);

namespace Billium\Server\Internal\Http;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelope;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Illuminate\Http\JsonResponse;
use Packman\Attributes\Internal;

#[Internal]
final readonly class EnvelopeResponse
{
    public function __construct(private Codec $codec) {}

    public function from(Envelope $envelope, int $status = 200): JsonResponse
    {
        return $this->json($this->codec->encode($envelope), $status);
    }

    /** @param array<string, mixed> $body */
    public function fromBody(array $body, bool $digest = false): JsonResponse
    {
        try {
            if ($digest) {
                $body['digest'] = $this->codec->digest($body);
            }
            $json = $this->codec->canonicalize($body);
            $this->codec->decode($json);
        } catch (ProblemException $failure) {
            report($failure);

            return ProblemResponse::from(ProblemCode::TransportUnavailable);
        }

        return $this->json($json, 200);
    }

    private function json(string $body, int $status): JsonResponse
    {
        return JsonResponse::fromJsonString($body, $status, ['Cache-Control' => 'no-store']);
    }
}
