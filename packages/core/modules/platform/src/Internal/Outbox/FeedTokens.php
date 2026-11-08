<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Outbox;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Platform\Data\Feed\FeedCursor;
use Billium\Platform\Data\Feed\FeedPosition;
use Billium\Platform\Data\Feed\FeedScope;
use JsonException;
use LogicException;

/** D63: HMAC key comes from the host app key (or explicit feed signing key), never the copied database. */
final readonly class FeedTokens
{
    public function __construct(private string $key)
    {
        if (strlen($key) < 32) {
            throw new LogicException('Feed requires a host-provisioned signing key of at least 32 bytes');
        }
    }

    public function issue(string $subscription, FeedScope $scope, string $selectionDigest, FeedPosition $position, ?string $leaseRef = null): FeedCursor
    {
        $body = (new Codec)->canonicalize(['v' => 1, 'subscriptionRef' => $subscription, 'scope' => $scope->toArray(),
            'selectionDigest' => $selectionDigest, 'position' => $position->toArray(), 'leaseRef' => $leaseRef]);
        $encoded = rtrim(strtr(base64_encode($body), '+/', '-_'), '=');

        return new FeedCursor($encoded.'.'.hash_hmac('sha256', $encoded, $this->key), $position);
    }

    /** @return array{FeedPosition,?string} */
    public function verify(string $token, string $subscription, FeedScope $scope, string $selectionDigest): array
    {
        if (strlen($token) > 4096) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Invalid cursor');
        }
        $parts = explode('.', $token);
        if (count($parts) !== 2 || ! hash_equals(hash_hmac('sha256', $parts[0], $this->key), $parts[1])) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Cursor authentication failed');
        }
        try {
            $raw = base64_decode(strtr($parts[0], '-_', '+/'), true);
            $body = json_decode($raw === false ? '' : $raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Malformed cursor');
        }
        if (! is_array($body) || ($body['v'] ?? null) !== 1 || ($body['subscriptionRef'] ?? null) !== $subscription
            || ($body['selectionDigest'] ?? null) !== $selectionDigest || (new Codec)->canonicalize($body['scope'] ?? null) !== $scope->key()
            || ! is_array($body['position'] ?? null) || ! is_string($body['position']['txId'] ?? null) || ! is_string($body['position']['id'] ?? null)
            || (isset($body['leaseRef']) && ! is_string($body['leaseRef']))) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Cursor scope/selection mismatch');
        }

        return [new FeedPosition($body['position']['txId'], $body['position']['id']), $body['leaseRef'] ?? null];
    }
}
